<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\CategoryFieldOptionRank;
use App\Models\Listing;
use App\Models\Make;
use App\Models\CarModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;


class MakeController extends Controller
{
    /**
     * Request-level cache for ranks map.
     *
     * @var array<string, array<string, int>>
     */
    private array $fieldRankMapCache = [];

    public function index()
    {
        $categoryId = Category::where('slug', 'cars')->value('id');
        $items = Make::with('models')->get();
        
        // معالجة الماركات والموديلات (الترتيب سيتم في الفرونت إند)
        $makesArray = [];
        foreach ($items as $make) {
            $modelNames = $make->models->pluck('name')->toArray();

            if ($categoryId) {
                $modelNames = $this->sortOptionsByRankWithFallbackFields(
                    (int) $categoryId,
                    [
                        "model_make_id_{$make->id}",
                        'model_' . $this->normalizeRankToken((string) $make->name),
                        "model_{$make->name}",
                        "Model_{$make->name}",
                        "model::{$make->name}",
                        'model',
                        'Model',
                    ],
                    $modelNames
                );
            }

            $modelNames = \App\Support\OptionsHelper::processOptions($modelNames, false, false);
            $modelsByName = $make->models->keyBy('name');
            
            $makesArray[] = [
                'id' => $make->id,
                'name' => $make->name,
                'models' => collect($modelNames)->values()->map(function($modelName, $idx) use ($make, $modelsByName) {
                    $model = $modelsByName->get($modelName);
                    return (object)[
                        'id' => $model?->id,
                        'name' => $modelName,
                        'make_id' => $make->id,
                        'rank' => $idx + 1,
                    ];
                })->all()
            ];
        }

        if ($categoryId) {
            $nameOrder = $this->sortOptionsByRankWithFallbackFields(
                (int) $categoryId,
                ['brand', 'Brand'],
                array_values(array_map(fn ($row) => (string) $row['name'], $makesArray))
            );

            $byName = [];
            foreach ($makesArray as $row) {
                $byName[(string) $row['name']] = $row;
            }

            $sortedMakesArray = [];
            foreach ($nameOrder as $name) {
                if (isset($byName[$name])) {
                    $sortedMakesArray[] = $byName[$name];
                    unset($byName[$name]);
                }
            }
            foreach ($byName as $row) {
                $sortedMakesArray[] = $row;
            }

            $makesArray = $sortedMakesArray;
        }

        $makesArray = collect($makesArray)->values()->map(function ($row, $idx) {
            $row['rank'] = $idx + 1;
            return $row;
        })->all();
        
        // إضافة "غير ذلك" في الآخر
        $makesArray[] = [
            'id' => null,
            'name' => 'غير ذلك',
            'models' => []
        ];
        
        // تحويل إلى objects
        $result = collect($makesArray)->map(function($item) {
            return (object)$item;
        });
        
        return response()->json($result);
    }

    public function addMake(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'], // شيلنا unique هنا
            // 'models'   => ['nullable', 'array'],
            // 'models.*' => ['string', 'max:191'],
        ]);

        $make = Make::where('name', $data['name'])->first();

        $isNew = false;

        if (!$make) {
            $make = Make::create(['name' => $data['name']]);
            
            // إضافة موديل "غير ذلك" تلقائيًا
            CarModel::create([
                'name' => 'غير ذلك',
                'make_id' => $make->id,
            ]);

            $isNew = true;
        } else {
            return response()->json([
                'message' => 'Make with this name already exists.',
            ], 422);
        }

        // // 2) نجهز الموديلات اللي جاية من الريكوست
        // $models = collect($data['models'] ?? [])
        //     ->map(fn($m) => trim($m))
        //     ->filter()          // شيل الفاضي
        //     ->unique()          // شيل التكرار
        //     ->values();         // ريسيت للـ index

        // $existing = $make->models()
        //     ->pluck('id', 'name');

        // $keepIds = [];

        // foreach ($models as $modelName) {
        //     if (isset($existing[$modelName])) {
        //         // الموديل موجود بالفعل بنفس الاسم → نخليه
        //         $keepIds[] = $existing[$modelName];
        //     } else {
        //         // موديل جديد → نضيفه
        //         $model = $make->models()->create([
        //             'name' => $modelName,
        //         ]);
        //         $keepIds[] = $model->id;
        //     }
        // }


        // if (count($keepIds) > 0) {
        //     $make->models()
        //         ->whereNotIn('id', $keepIds)
        //         ->delete();
        // } else {
        //     // لو مبعتيش ولا موديل → امسح كل الموديلات القديمة
        //     $make->models()->delete();
        // }


        $make->load('models');

        return response()->json($make, $isNew ? 201 : 200);
    }

    public function update(Request $request, Make $make)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:191', 'unique:makes,name,' . $make->id],
        ]);

        if (array_key_exists('name', $data)) {
            $make->update(['name' => $data['name']]);
        }

        return response()->json($make->load('models'));
    }

    public function destroy(Make $make)
    {
        // كل الموديلات اللي تحت الماركة دي
        $modelIds = $make->models()->pluck('id');

        $isUsed = Listing::where('make_id', $make->id)
            ->orWhereIn('model_id', $modelIds)
            ->exists();

        if ($isUsed) {
            return response()->json([
                'message' => 'لا يمكن حذف هذه الماركة لأنها مرتبطة بإعلانات أو موديلات مستخدمة في إعلانات.',
            ], 422);
        }

        // لو حابة كمان يتم حذف كل الموديلات التابعة ليها:
        $make->models()->delete();

        $make->delete();

        return response()->json("Deleted successfully", 204);
    }

    public function models(Make $make)
    {
        $models = $make->models()->get();
        
        // معالجة الموديلات (الترتيب سيتم في الفرونت إند)
        $modelNames = $models->pluck('name')->toArray();

        $categoryId = Category::where('slug', 'cars')->value('id');
        if ($categoryId) {
            $modelNames = $this->sortOptionsByRankWithFallbackFields(
                (int) $categoryId,
                [
                    "model_make_id_{$make->id}",
                    'model_' . $this->normalizeRankToken((string) $make->name),
                    "model_{$make->name}",
                    "Model_{$make->name}",
                    "model::{$make->name}",
                    'model',
                    'Model',
                ],
                $modelNames
            );
        }

        $modelNames = \App\Support\OptionsHelper::processOptions($modelNames, false, false);
        
        // تحويل إلى objects مع الحفاظ على الترتيب
        $sortedModels = collect($modelNames)->map(function($name) use ($models, $make) {
            $model = $models->firstWhere('name', $name);
            return (object)[
                'id' => $model ? $model->id : null,
                'name' => $name,
                'make_id' => $make->id
            ];
        });
        
        return response()->json($sortedModels);
    }

    /**
     * Try multiple rank field names and use the first one that has data.
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

        usort($withRanks, function ($a, $b) {
            return $a['rank'] <=> $b['rank'];
        });

        $sortedWithRanks = array_map(function ($item) {
            return $item['option'];
        }, $withRanks);

        $result = array_merge($sortedWithRanks, $withoutRanks);
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

    public function addModel(Request $request, Make $make)
    {
        // ✅ Validate
        $data = $request->validate([
            'models' => ['required', 'array', 'min:1'],
            'models.*' => [
                'required',
                'string',
                'max:191',
                // Unique per make
                Rule::unique('models', 'name')->where(function ($q) use ($make) {
                    return $q->where('make_id', $make->id);
                }),
            ],
        ]);

        $createdModels = [];

        // ✅ Create all models
        foreach ($data['models'] as $name) {
            $createdModels[] = CarModel::create([
                'name' => $name,
                'make_id' => $make->id,
            ]);
        }

        // ✅ Response بالشكل اللي تحبيه
        return response()->json([
            'make_id' => $make->id,
            'models' => $createdModels,
        ], 201);
    }

    public function updateModel(Request $request, CarModel $model)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191', 'unique:models,name,' . $model->id . ',id,make_id,' . $model->make_id],
            'make_id' => ['required', 'integer', 'exists:makes,id'],
        ]);

        $model->update($data);
        return response()->json($model);
    }

    public function deleteModel(CarModel $model)
    {
        $isUsed = Listing::where('model_id', $model->id)->exists();

        if ($isUsed) {
            return response()->json([
                'message' => 'لا يمكن حذف هذا الموديل لأنه مستخدم في إعلانات حالية.',
            ], 422);
        }

        $model->delete();

        return response()->json("Deleted successfully", 204);
    }
}
