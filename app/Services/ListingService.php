<?php

namespace App\Services;

use App\Models\Listing;
use App\Support\Section;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ListingService
{
    public function create(Section $section, array $data, int $userId): Listing
    {
        return DB::transaction(function () use ($section, $data, $userId) {
            $this->normalizeLocationIdsOrFail($data);


            if ($section->supportsMakeModel()) {
                $this->normalizeMakeModel($data);
            } else {
                unset($data['make_id'], $data['model_id'], $data['make'], $data['model']);
            }
            if ($section->supportsSections()) {
                $this->normalizeSections($data, $section);
            } else {
                unset($data['main_section_id'], $data['sub_section_id'], $data['main_section'], $data['sub_section']);
            }


            $common = Arr::only($data, [
                'title',
                'price',
                'description',
                'governorate_id',
                'city_id',
                'lat',
                'lng',
                'address',
                'main_image',
                'images',
                'status',
                'published_at',
                'rank',
                'country_code',
                'plan_type',
                'contact_phone',
                'whatsapp_phone',
                'whatsapp_mode',
                'whatsapp_group_number_ids',
                'current_whatsapp_group_index',
                'make_id',
                'admin_approved',
                'model_id',
                'expire_at',
                'isPayment',
                'main_section_id',
                'sub_section_id',
                'publish_via'

            ]);

            $listing = Listing::create($common + [
                'category_id' => $section->id(),
                'user_id' => $userId,
            ]);

            $this->syncAttributes($listing, $section, $data['attributes'] ?? []);

            return $listing->fresh('attributes');
        });
    }

    public function update(Section $section, Listing $listing, array $data): Listing
    {
        return DB::transaction(function () use ($section, $listing, $data) {
            $this->normalizeLocationIdsOrFail($data);

            if ($section->supportsMakeModel()) {
                $this->normalizeMakeModel($data);
            } else {
                unset($data['make_id'], $data['model_id'], $data['make'], $data['model']);
            }
            if ($section->supportsSections()) {
                $this->normalizeSections($data, $section);
            } else {
                unset($data['main_section_id'], $data['sub_section_id'], $data['main_section'], $data['sub_section']);
            }



            $listing->update(Arr::only($data, [
                'title',
                'price',
                'description',
                'governorate_id',
                'city_id',
                'lat',
                'lng',
                'address',
                'main_image',
                'images',
                'status',
                'published_at',
                'rank',
                'country_code',
                'plan_type',
                'contact_phone',
                'whatsapp_phone',
                'whatsapp_mode',
                'whatsapp_group_number_ids',
                'current_whatsapp_group_index',
                // 'admin_approved',
                'make_id',
                'model_id',
                'expire_at',
                'isPayment',
                'main_section_id',
                'sub_section_id',

            ]));

            if (array_key_exists('attributes', $data)) {
                $this->syncAttributes($listing, $section, $data['attributes'] ?? []);
            }

            return $listing->fresh('attributes');
        });
    }

    protected function normalizeLocationIdsOrFail(array &$data): void
    {
        $governorateId = $data['governorate_id'] ?? null;
        $governorateName = isset($data['governorate']) ? trim((string) $data['governorate']) : null;
        $cityId = $data['city_id'] ?? null;
        $cityName = isset($data['city']) ? trim((string) $data['city']) : null;


        if (!$governorateId && $governorateName) {
            $governorateId = DB::table('governorates')
                ->where('name', $governorateName)
                ->value('id');

            if (!$governorateId) {
                throw ValidationException::withMessages([
                    'governorate' => ['المحافظة غير معروفة. فضلاً اختر من القائمة.'],
                ]);
            }

            $data['governorate_id'] = $governorateId;
        }

        if ($governorateId) {
            $exists = DB::table('governorates')
                ->where('id', $governorateId)
                ->exists();

            if (!$exists) {
                throw ValidationException::withMessages([
                    'governorate_id' => ['المحافظة غير موجودة. فضلاً اختر من القائمة.'],
                ]);
            }
        }


        if ($cityId) {
            $cityQuery = DB::table('cities')->where('id', $cityId);

            if ($governorateId) {
                $cityQuery->where('governorate_id', $governorateId);
            }

            $city = $cityQuery->first();

            if (!$city) {
                if ($governorateId) {
                    throw ValidationException::withMessages([
                        'city_id' => ['هذه المدينة لا تتبع المحافظة المختارة. فضلاً اختر مدينة من نفس المحافظة.'],
                    ]);
                }

                throw ValidationException::withMessages([
                    'city_id' => ['المدينة غير معروفة. فضلاً اختر من القائمة.'],
                ]);
            }

            if (!$governorateId) {
                $data['governorate_id'] = $city->governorate_id;
            }

            return;
        }

        if ($cityName) {
            $cityQuery = DB::table('cities')->where('name', $cityName);

            if ($governorateId) {
                $cityQuery->where('governorate_id', $governorateId);
            }

            $city = $cityQuery->first();

            if (!$city) {
                if (!$governorateId) {
                    // مش لاقيين مدينة بالاسم ده (أو فيه لبس بين محافظات) بدون تحديد محافظة
                    throw ValidationException::withMessages([
                        'city' => ['المدينة غير معروفة أو تحتاج إلى تحديد المحافظة لنتائج أدق.'],
                    ]);
                }

                throw ValidationException::withMessages([
                    'city' => ['المدينة غير معروفة أو لا تتبع المحافظة المختارة. فضلاً اختر من القائمة.'],
                ]);
            }

            $data['city_id'] = $city->id;

            if (!$governorateId) {
                $data['governorate_id'] = $city->governorate_id;
            }
        }
    }


    protected function normalizeMakeModel(array &$data): void
    {
        $makeId = $data['make_id'] ?? null;
        $makeName = isset($data['make']) ? trim((string) $data['make']) : null;
        $modelId = $data['model_id'] ?? null;
        $modelName = isset($data['model']) ? trim((string) $data['model']) : null;

        if (!$makeId && $makeName) {
            $makeId = DB::table('makes')
                ->where('name', $makeName)
                ->value('id');

            if (!$makeId) {
                throw ValidationException::withMessages([
                    'make' => ['الماركة غير معروفة. فضلاً اختر من القائمة.'],
                ]);
            }

            $data['make_id'] = $makeId;
        }

        if ($makeId) {
            $exists = DB::table('makes')
                ->where('id', $makeId)
                ->exists();

            if (!$exists) {
                throw ValidationException::withMessages([
                    'make_id' => ['الماركة غير موجودة. فضلاً اختر من القائمة.'],
                ]);
            }
        }

        if (!$makeId && !$makeName && !$modelId && !$modelName) {
            return;
        }


        if ($modelId) {
            if (!$makeId) {
                throw ValidationException::withMessages([
                    'make_id' => ['يجب اختيار الماركة قبل اختيار الموديل.'],
                ]);
            }

            $model = DB::table('models')
                ->where('id', $modelId)
                ->where('make_id', $makeId)
                ->first();

            if (!$model) {
                throw ValidationException::withMessages([
                    'model_id' => ['هذا الموديل لا يتبع الماركة المختارة أو غير معروف.'],
                ]);
            }

            return;
        }

        // الحالة B: مفيش model_id لكن فيه اسم موديل
        if ($modelName) {
            if (!$makeId) {
                throw ValidationException::withMessages([
                    'make_id' => ['لا يمكن اختيار موديل بدون تحديد الماركة أولاً.'],
                ]);
            }

            $existingModelId = DB::table('models')
                ->where('make_id', $makeId)
                ->where('name', $modelName)
                ->value('id');

            if (!$existingModelId) {
                throw ValidationException::withMessages([
                    'model' => ['الموديل غير معروف لهذه الماركة. فضلاً اختر من القائمة.'],
                ]);
            }

            $data['model_id'] = $existingModelId;
        }
    }
    protected function normalizeSections(array &$data, Section $section): void
    {
        $categoryId = $section->id();

        $mainId = $data['main_section_id'] ?? null;
        $mainName = isset($data['main_section']) ? trim((string) $data['main_section']) : null;
        $subId = $data['sub_section_id'] ?? null;
        $subName = isset($data['sub_section']) ? trim((string) $data['sub_section']) : null;


        if (!$mainId && $mainName) {
            $mainId = DB::table('category_main_sections')
                ->where('category_id', $categoryId)
                ->where('name', $mainName)
                ->value('id');

            if (!$mainId) {
                throw ValidationException::withMessages([
                    'main_section' => ['القسم الرئيسي غير معروف. فضلاً اختر من القائمة.'],
                ]);
            }

            $data['main_section_id'] = $mainId;
        }

        if ($mainId) {
            $exists = DB::table('category_main_sections')
                ->where('id', $mainId)
                ->where('category_id', $categoryId)
                ->exists();

            if (!$exists) {
                throw ValidationException::withMessages([
                    'main_section_id' => ['القسم الرئيسي غير موجود لهذا القسم. فضلاً اختر من القائمة.'],
                ]);
            }
        }

        // لو لا main ولا sub اتبعتوا، سيبي السيرفس في حاله، الريكويست نفسه ممكن يكون عامله required
        if (!$mainId && !$mainName && !$subId && !$subName) {
            return;
        }

        // 2) حل sub_section بنفس منطق make/model

        if ($subId) {
            if (!$mainId) {
                throw ValidationException::withMessages([
                    'main_section_id' => ['يجب اختيار القسم الرئيسي قبل اختيار القسم الفرعي.'],
                ]);
            }

            $sub = DB::table('category_sub_section')
                ->where('id', $subId)
                ->where('main_section_id', $mainId)
                ->where('category_id', $categoryId)
                ->first();

            if (!$sub) {
                throw ValidationException::withMessages([
                    'sub_section_id' => ['هذا القسم الفرعي لا يتبع القسم الرئيسي المختار أو غير معروف.'],
                ]);
            }

            return;
        }

        // مفيش sub_section_id لكن في اسم
        if ($subName) {
            if (!$mainId) {
                throw ValidationException::withMessages([
                    'main_section_id' => ['لا يمكن اختيار قسم فرعي بدون تحديد القسم الرئيسي أولاً.'],
                ]);
            }

            $existingSubId = DB::table('category_sub_section')
                ->where('category_id', $categoryId)
                ->where('main_section_id', $mainId)
                ->where('name', $subName)
                ->value('id');

            if (!$existingSubId) {
                throw ValidationException::withMessages([
                    'sub_section' => ['القسم الفرعي غير معروف لهذا القسم الرئيسي. فضلاً اختر من القائمة.'],
                ]);
            }

            $data['sub_section_id'] = $existingSubId;
        }
    }




    protected function syncAttributes(Listing $listing, Section $section, array $attrs): void
    {
        $allowedNames = array_column($section->fields, 'field_name');
        $typesByKey = collect($section->fields)
            ->keyBy('field_name')
            ->map(fn($f) => $f['type'] ?? 'string');

        $current = $listing->attributes()
            ->get()
            ->keyBy('key');

        $now = now();

        foreach ($attrs as $key => $value) {
            if (!in_array($key, $allowedNames, true)) {
                continue;
            }

            if ($value === null || $value === '') {
                $listing->attributes()->where('key', $key)->delete();
                continue;
            }

            $type = $typesByKey[$key] ?? 'string';

            $payload = match ($type) {
                'int' => ['value_int' => (int) $value],
                'decimal' => ['value_decimal' => (float) $value],
                'bool' => ['value_bool' => (bool) $value],
                'date' => ['value_date' => $value],
                'json' => ['value_json' => $value],
                default => ['value_string' => (string) $value],
            };

            if ($current->has($key)) {
                $listing->attributes()
                    ->where('key', $key)
                    ->update($payload + [
                        'type' => $type,
                        'updated_at' => $now,
                    ]);
            } else {
                $listing->attributes()->create($payload + [
                    'listing_id' => $listing->id,
                    'key' => $key,
                    'type' => $type,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }
}
