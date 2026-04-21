<?php

namespace App\Support;

use App\Models\Category;
use App\Models\CategoryField;
use App\Models\CategoryPlanPrice;

final class Section
{
    public function __construct(
        public string $slug,
        public int $categoryId,
        public array $fields,
        public ?string $name = null,
    ) {}

    public static function fromSlug(string $slug): self
    {
        $cat = Category::where('slug', $slug)
            ->where('is_active', true)
            ->firstOrFail();

        $fields = CategoryField::query()
            ->where('category_slug', $slug)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        // استخدام OptionsHelper لضمان "غير ذلك" في الآخر
        $fields = OptionsHelper::processFieldsCollection($fields)->toArray();

        return new self(
            slug: $slug,
            categoryId: $cat->id,
            fields: $fields,
            name: $cat->name,
        );
    }

    public static function fromId(int $id): ?self
    {
        $cat = Category::where('id', $id)
            ->where('is_active', true)
            ->first();

        if (!$cat) {
            return null;
        }

        $fields = CategoryField::query()
            ->where('category_slug', $cat->slug)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        // استخدام OptionsHelper لضمان "غير ذلك" في الآخر
        $fields = OptionsHelper::processFieldsCollection($fields)->toArray();

        return new self(
            slug: $cat->slug,
            categoryId: $cat->id,
            fields: $fields,
            name: $cat->name,
        );
    }

    public function id(): int
    {
        return $this->categoryId;
    }


    public function supportsMakeModel(): bool
    {
        return in_array($this->slug, ['cars', 'cars_rent', 'spare-parts'], true);
    }
    public function supportsSections(): bool
    {
        return in_array($this->slug, [
            'stores',
            'restaurants',
            'groceries',
            'food-products',
            'electronics',
            'home-appliances',
            'home-tools',
            'furniture',
            'health',
            'education',
            'shipping',
            'mens-clothes',
            'watches-jewelry',
            'free-professions',
            'kids-toys',
            'gym',
            'construction',
            'maintenance',
            'car-services',
            'home-services',
            'lighting-decor',
            'animals',
            'farm-products',
            'wholesale',
            'production-lines',
            'light-vehicles',
            'heavy-transport',
            'tools',
            'missing',
            'spare-parts',
            'jobs',
        ], true);
    }

    public function planPrices(): ?array
    {
        $row = CategoryPlanPrice::where('category_id', $this->categoryId)->first();

        if (!$row) {
            return null;
        }

        return [
            'price_featured' => (int) $row->price_featured,
            'price_standard' => (int) $row->price_standard,
        ];
    }


    public function supportsContact(): bool
    {
        return true;
    }
    public function rules(): array
    {
        $requiresTitle = $this->slug === 'spare-parts'
            || ($this->supportsSections() && $this->slug !== 'jobs');

        $priceRules = ($this->slug === 'missing' || $this->slug === 'jobs')
            ? ['nullable', 'numeric', 'min:0']
            : ['required', 'numeric', 'min:0'];

        $planRules = $this->slug === 'missing'
            ? ['required', 'string', 'in:free']
            : ['required', 'string', 'in:standard,premium,featured,free'];

        $base = [
            'title' => $requiresTitle
                ? ['required', 'string', 'max:180']
                : ['nullable', 'string', 'max:180'],
            'price' => $priceRules,
            'description' => ['required', 'string'],

            'governorate_id' => ['nullable', 'integer', 'exists:governorates,id'],
            'city_id' => ['nullable', 'integer', 'exists:cities,id'],
            'governorate' => ['required', 'string', 'max:100'],
            'city' => ['required', 'string', 'max:100'],

            'lat' => ['required', 'numeric'],
            'lng' => ['required', 'numeric'],
            'address' => ['required', 'string', 'max:255'],

            'main_image' => ($this->slug === 'jobs' || $this->slug === 'doctors' || $this->slug === 'teachers')
                ? ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120']
                : ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'images' => ['nullable', 'array', 'max:20'],
            'images.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            "plan_type" => $planRules,
            'contact_phone' => ['nullable', 'string', 'max:20'],
            'whatsapp_phone' => ['nullable', 'string', 'max:20'],
            'whatsapp_mode' => ['nullable', 'string', 'in:single,group'],
            'whatsapp_group_number_ids' => ['nullable', 'array'],
            'whatsapp_group_number_ids.*' => ['integer'],
            'country_code' => ['nullable', 'string', 'max:20'],
            'admin_approved' => ['nullable', 'boolean'],
            'expire_at' => ['nullable', 'date'],
            'isPayment' => ['nullable', 'boolean'],

            // 'make_id' => ['nullable', 'integer', 'exists:makes,id'],
            // 'model_id' => ['nullable', 'integer', 'exists:models,id'],
            // 'make' => ['nullable', 'string'],
            // 'model' => ['nullable', 'string'],
        ];

        $attrs = [];
        foreach ($this->fields as $f) {
            // العنوان يدار من الحقل الرئيسي `title` فقط، وليس attributes.title
            if (($f['field_name'] ?? null) === 'title') {
                continue;
            }

            $key = 'attributes.' . $f['field_name'];
            $rules = [(!empty($f['required']) ? 'required' : 'nullable')];

            $rules[] = match ($f['type'] ?? 'string') {
                'int' => 'integer',
                'decimal' => 'numeric',
                'bool' => 'boolean',
                'date' => 'date',
                'json' => 'array',
                default => 'string',
            };

            if (!empty($f['options'])) {
                $opts = is_array($f['options']) ? $f['options'] : json_decode($f['options'], true);
                if (is_array($opts) && $opts) {
                    if ($this->slug === 'jobs' || !in_array('غير ذلك', $opts)) {
                        // $rules[] = 'in:' . implode(',', array_map(fn($v) => str_replace(',', '،', (string) $v), $opts));
                        $rules[] = \Illuminate\Validation\Rule::in($opts);
                    }
                }
            }

            if (!empty($f['rules_json'])) {
                $extra = is_array($f['rules_json']) ? $f['rules_json'] : json_decode($f['rules_json'], true);
                if (is_array($extra)) {
                    $rules = array_merge($rules, $extra);
                }
            }

            $attrs[$key] = $rules;
        }

        return $base + $attrs;
    }
}
