<?php

namespace Database\Seeders;

use App\Models\CategoryField;
use Illuminate\Database\Seeder;

class CategoryFieldsSeeder extends Seeder
{
    public function run(): void
    {
        $realEstateFields = [
            [
                'category_slug' => 'real_estate',
                'field_name' => 'property_type',
                'display_name' => 'نوع العقار',
                'type' => 'string',
                'options' => ['فيلا', 'شقة', 'أرض', 'استوديو', 'محل تجاري', 'مكتب', 'غير ذلك'],
                'required' => true,
                'filterable' => true,
                'sort_order' => 1,
            ],
            [
                'category_slug' => 'real_estate',
                'field_name' => 'contract_type',
                'display_name' => 'نوع العقد',
                'type' => 'string',
                'options' => ['بيع', 'إيجار', 'غير ذلك'],
                'required' => true,
                'filterable' => true,
                'sort_order' => 2,
            ],
        ];

        $carsRentFields = [
            [
                'category_slug' => 'cars_rent',
                'field_name' => 'brand',
                'display_name' => 'الماركة',
                'type' => 'string',
                'options' => [], // Loaded dynamically from /api/makes
                'required' => true,
                'filterable' => true,
                'sort_order' => 1,
            ],
            [
                'category_slug' => 'cars_rent',
                'field_name' => 'model',
                'display_name' => 'الموديل',
                'type' => 'string',
                'options' => [], // Loaded dynamically per make from /api/makes
                'required' => true,
                'filterable' => true,
                'sort_order' => 2,
            ],
            [
                'category_slug' => 'cars_rent',
                'field_name' => 'year',
                'display_name' => 'السنة',
                'type' => 'string',
                'options' => array_merge(range(2000, 2030), ['غير ذلك']),
                'required' => true,
                'filterable' => true,
                'sort_order' => 3,
            ],
            [
                'category_slug' => 'cars_rent',
                'field_name' => 'driver_option',
                'display_name' => 'السائق',
                'type' => 'string',
                'options' => ['بدون سائق', 'بسائق', 'غير ذلك'],
                'required' => true,
                'filterable' => true,
                'sort_order' => 4,
            ],
        ];


        $jobsFields = [
            // 🔹 حقل التصنيف (مطلوب للعمل / باحث عن عمل)
            [
                'category_slug' => 'jobs',
                'field_name' => 'job_type',
                'display_name' => 'التصنيف',
                'type' => 'string',
                'options' => [
                    'مطلوب للعمل',
                    'باحث عن عمل',
                ],
                'required' => true,
                'filterable' => true,
                'sort_order' => 1,
            ],
            // 🔹 حقل التخصص
            [
                'category_slug' => 'jobs',
                'field_name' => 'specialization',
                'display_name' => 'التخصص',
                'type' => 'string',
                'options' => [
                    'محاسب',
                    'مهندس',
                    'دكتور',
                    'صيدلي',
                    'ممرض',
                    'مدرس',
                    'محامي',
                    'مبرمج',
                    'مصمم جرافيك',
                    'مسوق',
                    'مندوب مبيعات',
                    'سكرتير',
                    'مدير موارد بشرية',
                    'كهربائي',
                    'سباك',
                    'نجار',
                    'سائق',
                    'طباخ',
                    'أمن وحراسة',
                    'خدمة عملاء',
                    'محلل بيانات',
                    'موظف إداري',
                    'فني صيانة',
                    'عامل إنتاج',
                ],
                'required' => true,
                'filterable' => true,
                'sort_order' => 2,
            ],
            [
                'category_slug' => 'jobs',
                'field_name' => 'salary',
                'display_name' => 'الراتب',
                'type' => 'decimal',
                'options' => [],
                'required' => true,
                'filterable' => false,
                'rules_json' => ['min:0'],
                'sort_order' => 3,
            ],
            [
                'category_slug' => 'jobs',
                'field_name' => 'contact_via_type',
                'display_name' => 'نوع حقل التواصل عبر',
                'type' => 'string',
                'options' => ['الإيميل', 'واتساب', 'اتصال'],
                'required' => true,
                'filterable' => false,
                'sort_order' => 4,
            ],
            [
                'category_slug' => 'jobs',
                'field_name' => 'contact_via',
                'display_name' => 'التواصل عبر',
                'type' => 'string',
                'options' => [],
                'required' => true,
                'filterable' => false,
                'sort_order' => 5,
            ],
        ];

        $carFields = [
            [
                'category_slug' => 'cars',
                'field_name' => 'brand',
                'display_name' => 'الماركة',
                'type' => 'string',
                'options' => [], // Loaded dynamically from /api/makes
                'required' => true,
                'filterable' => true,
                'sort_order' => 1,
            ],
            [
                'category_slug' => 'cars',
                'field_name' => 'model',
                'display_name' => 'الموديل',
                'type' => 'string',
                'options' => [], // Loaded dynamically per make from /api/makes
                'required' => true,
                'filterable' => true,
                'sort_order' => 2,
            ],
            [
                'category_slug' => 'cars',
                'field_name' => 'year',
                'display_name' => 'السنة',
                'type' => 'string',
                'options' => array_merge(range(1990, 2025), ['غير ذلك']),
                'required' => true,
                'filterable' => true,
                'sort_order' => 3,
            ],
            [
                'category_slug' => 'cars',
                'field_name' => 'kilometers',
                'display_name' => 'الكيلو متر',
                'type' => 'string',
                'options' => [
                    '0 - 10,000',
                    '10,000 - 50,000',
                    '50,000 - 100,000',
                    '100,000 - 200,000',
                    'أكثر من 200,000',
                    'غير ذلك'
                ],
                'required' => true,
                'filterable' => true,
                'sort_order' => 4,
            ],
            [
                'category_slug' => 'cars',
                'field_name' => 'fuel_type',
                'display_name' => 'نوع الوقود',
                'type' => 'string',
                'options' => ['بنزين', 'ديزل', 'غاز', 'كهرباء', 'غير ذلك'],
                'required' => true,
                'filterable' => true,
                'sort_order' => 5,
            ],
            [
                'category_slug' => 'cars',
                'field_name' => 'transmission',
                'display_name' => 'الفتيس',
                'type' => 'string',
                'options' => ['أوتوماتيك', 'مانيوال', 'غير ذلك'],
                'required' => true,
                'filterable' => true,
                'sort_order' => 6,
            ],
            [
                'category_slug' => 'cars',
                'field_name' => 'exterior_color',
                'display_name' => 'اللون الخارجي',
                'type' => 'string',
                'options' => ['أبيض', 'أسود', 'أزرق', 'رمادي', 'فضي', 'أحمر', 'غير ذلك'],
                'required' => true,
                'filterable' => true,
                'sort_order' => 7,
            ],
            [
                'category_slug' => 'cars',
                'field_name' => 'type',
                'display_name' => 'النوع',
                'type' => 'string',
                'options' => [
                    'سيدان',
                    'هاتشباك',
                    'SUV',
                    'كروس أوفر',
                    'بيك أب',
                    'كوبيه',
                    'كشف',
                    'غير ذلك'
                ],
                'required' => true,
                'filterable' => true,
                'sort_order' => 8,
            ],
        ];

        // 🔹 حقول قطع غيار سيارات
        $sparePartsFields = [
            [
                'category_slug' => 'spare-parts',
                'field_name' => 'brand',
                'display_name' => 'الماركة',
                'type' => 'string',
                'options' => [], // Loaded dynamically from /api/makes
                'required' => true,
                'filterable' => true,
                'sort_order' => 1,
            ],
            [
                'category_slug' => 'spare-parts',
                'field_name' => 'model',
                'display_name' => 'الموديل',
                'type' => 'string',
                'options' => [], // Loaded dynamically per make from /api/makes
                'required' => true,
                'filterable' => true,
                'sort_order' => 2,
            ],
        ];

        // 🔹 حقول المدرسين
        $teachersFields = [
            [
                'category_slug' => 'teachers',
                'field_name' => 'name',
                'display_name' => 'الاسم',
                'type' => 'string',
                'options' => [],
                'required' => true,
                'filterable' => false,
                'sort_order' => 0,
            ],
            [
                'category_slug' => 'teachers', // غيّريه لو السلاج مختلف عندك
                'field_name' => 'specialization',
                'display_name' => 'التخصص',
                'type' => 'string',
                'options' => [
                    'رياضيات',
                    'فيزياء',
                    'كيمياء',
                    'أحياء',
                    'لغة عربية',
                    'لغة إنجليزية',
                    'لغة فرنسية',
                    'دراسات اجتماعية',
                    'حاسب آلي',
                    'علوم شرعية',
                    'رياض أطفال',
                    'مرحلة ابتدائية',
                    'مرحلة إعدادية',
                    'مرحلة ثانوية',
                    'غير ذلك'
                ],
                'required' => true,
                'filterable' => true,
                'sort_order' => 1,
            ],
        ];

        // 🔹 حقول الأطباء
        $doctorsFields = [
            [
                'category_slug' => 'doctors',
                'field_name' => 'name',
                'display_name' => 'الاسم',
                'type' => 'string',
                'options' => [],
                'required' => true,
                'filterable' => false,
                'sort_order' => 0,
            ],
            [
                'category_slug' => 'doctors', // غيّريه لو السلاج مختلف عندك
                'field_name' => 'specialization',
                'display_name' => 'التخصص',
                'type' => 'string',
                'options' => [
                    'باطنة',
                    'أطفال',
                    'قلب وأوعية دموية',
                    'عظام',
                    'نساء وتوليد',
                    'أنف وأذن وحنجرة',
                    'جلدية',
                    'أسنان',
                    'عيون',
                    'مخ وأعصاب',
                    'مسالك بولية',
                    'جراحة عامة',
                    'علاج طبيعي',
                    'تحاليل طبية',
                    'أشعة',
                    'غير ذلك'
                ],
                'required' => true,
                'filterable' => true,
                'sort_order' => 1,
            ],
        ];


        // ✅ كل الحقول المسموح بيها
        $allFields = array_merge(
            $realEstateFields,
            $carFields,
            $carsRentFields,
            $sparePartsFields,
            $jobsFields,
            $teachersFields,
            $doctorsFields,
        );

        // ✅ نبني قائمة بالمفاتيح المسموح بيها: category_slug + field_name
        $allowedKeys = collect($allFields)
            ->map(fn($f) => $f['category_slug'] . '::' . $f['field_name'])
            ->all();

        // ✅ امسح أي حقول قديمة مش موجودة في اللي فوق
        CategoryField::all()->each(function (CategoryField $field) use ($allowedKeys) {
            $key = $field->category_slug . '::' . $field->field_name;

            if (!in_array($key, $allowedKeys, true)) {
                $field->delete();
            }
        });

        // ✅ اعملي upsert / create لباقي الحقول
        foreach ($allFields as $field) {
            CategoryField::updateOrCreate(
                [
                    'category_slug' => $field['category_slug'],
                    'field_name' => $field['field_name'],
                ],
                [
                    'display_name' => $field['display_name'],
                    'type' => $field['type'] ?? 'string',
                    'options' => $field['options'] ?? [],
                    'required' => $field['required'] ?? true,
                    'filterable' => $field['filterable'] ?? true,
                    'is_active' => true,
                    'sort_order' => $field['sort_order'] ?? 999,
                    'rules_json' => $field['rules_json'] ?? null,
                ]
            );
        }
    }
}
