<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Validation\ValidationException;
use App\Http\Requests\Admin\StoreCategoryFieldRequest;
use App\Http\Requests\Admin\UpdateCategoryFieldRequest;
use App\Models\Category;
use App\Models\CategoryField;
use App\Models\CategoryFieldOptionRank;
use App\Models\Governorate;
use App\Models\Make;
use App\Support\Section;
use App\Support\OptionsHelper;
use Illuminate\Http\Request;
use App\Models\CategoryMainSection;
use App\Models\CategorySubSection;


class CategoryFieldsController extends Controller
{
    /**
     * Request-level cache for ranks map.
     *
     * @var array<string, array<string, int>>
     */
    private array $fieldRankMapCache = [];

    // GET /api/admin/category-fields?category_slug=cars
    public function index(Request $request)
    {
        $q = CategoryField::query()
            ->orderBy('category_slug')
            ->orderBy('sort_order');

        $slug = $request->query('category_slug');

        if ($slug) {
            $q->where('category_slug', $slug);
        }

        $fields = $q->get();

        // Sort options by rank if ranks exist
        $category = $slug ? Category::where('slug', $slug)->first() : null;
        if ($category) {
            $fields = $fields->map(function ($field) use ($category) {
                if (!empty($field->options) && is_array($field->options)) {
                    $field->options = $this->sortOptionsByRank(
                        $category->id,
                        $field->field_name,
                        $field->options
                    );
                }
                return $field;
            });
        }

        // معالجة الحقول لضمان "غير ذلك" في الآخر (بدون ترتيب - سيتم الترتيب في الفرونت إند)
        $fields = OptionsHelper::processFieldsCollection($fields, false, false);

        // جلب المحافظات والمدن بنفس ترتيب الداشبورد (sort_order ثم الاسم)
        // ملاحظة: لا نستخدم category_field_option_ranks هنا حتى يكون التطبيق مطابقًا تمامًا للداشبورد.
        $governorates = Governorate::with([
            'cities' => function ($q) {
                $q->orderBy('sort_order')->orderBy('name');
            }
        ])->orderBy('sort_order')->orderBy('name')->get();

        // تحويل للصيغة المطلوبة للفرونت مع إرجاع id/rank لثبات التطابق
        $governorates = $governorates->map(function ($governorate) {
            $cityNames = $governorate->cities->pluck('name')->toArray();
            $orderedCityNames = OptionsHelper::processOptions($cityNames, false, false);

            $citiesByName = $governorate->cities->keyBy('name');
            $cities = collect($orderedCityNames)->map(function ($cityName) use ($citiesByName) {
                $city = $citiesByName->get($cityName);
                if (!$city) {
                    return null;
                }
                return [
                    'id' => $city->id,
                    'name' => $city->name,
                    'rank' => $city->sort_order,
                ];
            })->filter()->values()->all();

            return [
                'id' => $governorate->id,
                'name' => $governorate->name,
                'rank' => $governorate->sort_order,
                'cities' => $cities,
            ];
        })->values()->all();

        $section = $slug ? Section::fromSlug($slug) : null;

        $supportsMakeModel = $section?->supportsMakeModel() ?? false;
        $supportsSections = $section?->supportsSections() ?? false; // ✅ جديد

        $makes = [];
        if ($supportsMakeModel) {
            $makes = Make::with('models')->get();
            
            // معالجة الماركات والموديلات (الترتيب سيتم في الفرونت إند)
            $makesArray = [];
            foreach ($makes as $make) {
                $modelNames = $make->models->pluck('name')->toArray();
                
                // Sort models by rank if ranks exist
                if ($category) {
                    $modelNames = $this->sortOptionsByRankWithFallbackFields(
                        $category->id,
                        [
                            "model_make_id_{$make->id}",
                            'model_' . $this->normalizeRankToken($make->name),
                            "model_{$make->name}",
                            "Model_{$make->name}",
                            "model::{$make->name}",
                            'model',
                            'Model',
                        ],
                        $modelNames
                    );
                }
                
                // معالجة الموديلات لضمان "غير ذلك" في الآخر
                $orderedModelNames = OptionsHelper::processOptions($modelNames, false, false);
                $modelsByName = $make->models->keyBy('name');
                $orderedModels = collect($orderedModelNames)
                    ->values()
                    ->map(function ($modelName, $idx) use ($modelsByName) {
                        $model = $modelsByName->get($modelName);
                        return [
                            'id' => $model?->id,
                            'name' => $modelName,
                            'rank' => $idx + 1,
                        ];
                    })
                    ->all();

                $makesArray[$make->name] = [
                    'id' => $make->id,
                    'models' => $orderedModels,
                ];
            }
            
            // Sort makes by rank if ranks exist
            $makeNames = array_keys($makesArray);
            if ($category) {
                $makeNames = $this->sortOptionsByRankWithFallbackFields(
                    $category->id,
                    ['brand', 'Brand'],
                    $makeNames
                );
            }
            
            // تحويل للصيغة المطلوبة للفرونت إند
            $makes = collect($makeNames)->values()->map(function ($makeName, $idx) use ($makesArray) {
                return [
                    'id' => $makesArray[$makeName]['id'] ?? null,
                    'name' => $makeName,
                    'rank' => $idx + 1,
                    'models' => $makesArray[$makeName]['models'] ?? [],
                ];
            })->values()->all();
        }

        $mainSections = [];
        if ($supportsSections && $section) {
            $mainSections = CategoryMainSection::with([
                'subSections' => function ($q) {
                    $q->where('is_active', true)
                        ->orderBy('sort_order');
                }
            ])
                ->where('category_id', $section->id())
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get();
            
            // تحويل للصيغة المطلوبة للفرونت إند مع الـ IDs
            $mainSections = $mainSections->map(function ($mainSection) use ($category) {
                $subSections = $mainSection->subSections->map(function ($subSection) {
                    return [
                        'id' => $subSection->id,
                        'name' => $subSection->name,
                        'title' => $subSection->title,
                    ];
                })->values();
                
                // معالجة الأقسام الفرعية لضمان "غير ذلك" في الآخر
                $subSectionNames = $subSections->pluck('name')->toArray();
                
                // Sort sub-sections by rank if ranks exist
                if ($category) {
                    $subSectionNames = $this->sortOptionsByRank(
                        $category->id,
                        "SubSection_{$mainSection->name}",
                        $subSectionNames
                    );
                }
                
                $processedNames = OptionsHelper::processOptions($subSectionNames, false, false);
                
                // إعادة ترتيب الأقسام الفرعية حسب الترتيب المعالج
                $orderedSubSections = collect($processedNames)->map(function ($name) use ($subSections) {
                    return $subSections->firstWhere('name', $name);
                })->filter()->values();
                
                return [
                    'id' => $mainSection->id,
                    'name' => $mainSection->name,
                    'title' => $mainSection->title,
                    'sub_sections' => $orderedSubSections->all()
                ];
            })->values();
            
            // معالجة الأقسام الرئيسية لضمان "غير ذلك" في الآخر
            $mainSectionNames = $mainSections->pluck('name')->toArray();
            
            // Sort main sections by rank if ranks exist
            if ($category) {
                $mainSectionNames = $this->sortOptionsByRank(
                    $category->id,
                    'MainSection',
                    $mainSectionNames
                );
            }
            
            $processedMainNames = OptionsHelper::processOptions($mainSectionNames, false, false);
            
            // إعادة ترتيب الأقسام الرئيسية حسب الترتيب المعالج
            $mainSections = collect($processedMainNames)->map(function ($name) use ($mainSections) {
                return $mainSections->firstWhere('name', $name);
            })->filter()->values()->all();
        }

        return response()->json([
            'data' => $fields,

            'governorates' => $governorates,

            'makes' => $supportsMakeModel ? $makes : [],
            'supports_make_model' => $supportsMakeModel,

            // ✅ دعم الأقسام الرئيسية/الفرعية
            'supports_sections' => $supportsSections,
            'main_sections' => $mainSections, // جوّاها subSections جاهزة
        ]);
    }


    // POST /api/admin/category-fields
    public function store(StoreCategoryFieldRequest $request)
    {
        $data = $request->validated();

        $category = Category::firstOrCreate(
            ['slug' => $data['category_slug']],
            [
                'name' => $data['category_slug'],
                'is_active' => true,
            ]
        );

        if (empty($data['options'])) {
            $data['options'] = [OptionsHelper::OTHER_OPTION];
        } else {
            // معالجة لضمان "غير ذلك" في الآخر (بدون ترتيب)
            $data['options'] = OptionsHelper::processOptions($data['options'], false, false);
        }

        $field = CategoryField::create($data);

        return response()->json([
            'message' => 'تم إنشاء الحقل بنجاح',
            'data' => $field,
        ], 201);
    }

    // PUT /api/admin/category-fields/{id}
    public function update(UpdateCategoryFieldRequest $request, $categorySlug)
    {
        $data = $request->validated();

        $field = CategoryField::where('category_slug', $categorySlug)
            ->where('field_name', $data['field_name'])
            ->first();

        if (!$field) {
            throw ValidationException::withMessages([
                'field_name' => ['الحقل المطلوب غير موجود في هذا القسم.'],
            ]);
        }

        if (isset($data['options']) && is_array($data['options'])) {
            // تنظيف وإزالة التكرار
            $clean = [];
            foreach ($data['options'] as $opt) {
                $value = trim((string) $opt);
                if ($value !== '') {
                    $clean[] = $value;
                }
            }

            $clean = array_values(array_unique($clean));
            
            // معالجة لضمان "غير ذلك" في الآخر (بدون ترتيب)
            $data['options'] = OptionsHelper::processOptions($clean, false, false);
            
            // Update ranks for the new options
            $this->updateRanksForOptions($categorySlug, $data['field_name'], $data['options']);
        }

        unset($data['field_name']);

        $field->update($data);

        return response()->json([
            'message' => 'تم تحديث الحقل بنجاح',
            'data' => $field->fresh(),
        ]);
    }


    public function destroy(CategoryField $categoryField)
    {
        $categoryField->update(['is_active' => false]);

        return response()->json([
            'message' => 'تم إلغاء تفعيل الحقل',
        ]);
    }

    /**
     * Sort options by their rank values.
     * If no ranks exist, return options in original order (backward compatibility).
     *
     * @param int $categoryId
     * @param string $fieldName
     * @param array $options
     * @return array
     */
    private function sortOptionsByRank(int $categoryId, string $fieldName, array $options): array
    {
        $rankMap = $this->getRankMap($categoryId, $fieldName);
        if (empty($rankMap)) {
            return $options;
        }

        return $this->sortOptionsByExplicitRankMap($options, $rankMap);
    }

    /**
     * Try multiple rank field names and use the first one that has data.
     * This keeps backward compatibility with old and new key formats.
     *
     * @param int $categoryId
     * @param array<int, string> $fieldNames
     * @param array $options
     * @return array
     */
    private function sortOptionsByRankWithFallbackFields(int $categoryId, array $fieldNames, array $options): array
    {
        foreach ($fieldNames as $fieldName) {
            $key = trim((string) $fieldName);
            if ($key === '') {
                continue;
            }
            $rankMap = $this->getRankMap($categoryId, $key);
            if (!empty($rankMap)) {
                return $this->sortOptionsByExplicitRankMap($options, $rankMap);
            }
        }

        return $options;
    }

    /**
     * Resolve rank map with per-request caching.
     *
     * @param int $categoryId
     * @param string $fieldName
     * @return array<string, int>
     */
    private function getRankMap(int $categoryId, string $fieldName): array
    {
        $cacheKey = $categoryId . '|' . $fieldName;
        if (array_key_exists($cacheKey, $this->fieldRankMapCache)) {
            return $this->fieldRankMapCache[$cacheKey];
        }

        $rankMap = CategoryFieldOptionRank::where('category_id', $categoryId)
            ->where('field_name', $fieldName)
            ->pluck('rank', 'option_value')
            ->toArray();

        $this->fieldRankMapCache[$cacheKey] = $rankMap;
        return $rankMap;
    }

    /**
     * Sort options with a preloaded rank map.
     *
     * @param array $options
     * @param array<string, int> $rankMap
     * @return array
     */
    private function sortOptionsByExplicitRankMap(array $options, array $rankMap): array
    {
        // Separate "غير ذلك", options with ranks, and options without ranks
        $otherOption = null;
        $withRanks = [];
        $withoutRanks = [];

        foreach ($options as $option) {
            if ($option === 'غير ذلك') {
                $otherOption = $option;
            } elseif (isset($rankMap[$option])) {
                $withRanks[] = ['option' => $option, 'rank' => $rankMap[$option]];
            } else {
                $withoutRanks[] = $option;
            }
        }

        // Sort options with ranks by rank value
        usort($withRanks, function ($a, $b) {
            return $a['rank'] <=> $b['rank'];
        });

        // Extract just the option values
        $sortedWithRanks = array_map(function ($item) {
            return $item['option'];
        }, $withRanks);

        // Combine: ranked options first, then unranked options, then "غير ذلك" at the end
        $result = array_merge($sortedWithRanks, $withoutRanks);
        
        // Add "غير ذلك" at the end if it exists
        if ($otherOption !== null) {
            $result[] = $otherOption;
        }
        
        return $result;
    }

    /**
     * Normalize text token for rank key matching.
     *
     * @param string $value
     * @return string
     */
    private function normalizeRankToken(string $value): string
    {
        $v = preg_replace('/\s+/u', ' ', trim($value));
        if (!is_string($v)) {
            return '';
        }
        return strtolower($v);
    }

    /**
     * Update ranks for options when they are modified.
     * This ensures new options get proper ranks and maintains order.
     *
     * @param string $categorySlug
     * @param string $fieldName
     * @param array $options
     * @return void
     */
    private function updateRanksForOptions(string $categorySlug, string $fieldName, array $options): void
    {
        $category = Category::where('slug', $categorySlug)->first();
        
        if (!$category) {
            return;
        }

        // Get existing ranks
        $existingRanks = CategoryFieldOptionRank::where('category_id', $category->id)
            ->where('field_name', $fieldName)
            ->get()
            ->keyBy('option_value');

        // Assign ranks to options
        $rank = 1;
        foreach ($options as $option) {
            // If option already has a rank, keep it
            if (isset($existingRanks[$option])) {
                $existingRanks[$option]->update(['rank' => $rank]);
            } else {
                // New option - create rank
                CategoryFieldOptionRank::create([
                    'category_id' => $category->id,
                    'field_name' => $fieldName,
                    'option_value' => $option,
                    'rank' => $rank,
                ]);
            }
            $rank++;
        }

        // Delete ranks for options that no longer exist
        $optionValues = array_values($options);
        CategoryFieldOptionRank::where('category_id', $category->id)
            ->where('field_name', $fieldName)
            ->whereNotIn('option_value', $optionValues)
            ->delete();
    }
}
