<?php

use App\Models\CategoryField;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        CategoryField::updateOrCreate(
            [
                'category_slug' => 'jobs',
                'field_name' => 'contact_via_type',
            ],
            [
                'display_name' => 'نوع حقل التواصل عبر',
                'type' => 'string',
                'options' => ['البريد الإلكتروني', 'واتساب', 'اتصال'],
                'required' => true,
                'filterable' => false,
                'is_active' => true,
                'sort_order' => 4,
                'rules_json' => null,
            ]
        );

        CategoryField::query()
            ->where('category_slug', 'jobs')
            ->where('field_name', 'contact_via')
            ->update(['sort_order' => 5]);
    }

    public function down(): void
    {
        CategoryField::query()
            ->where('category_slug', 'jobs')
            ->where('field_name', 'contact_via_type')
            ->delete();

        CategoryField::query()
            ->where('category_slug', 'jobs')
            ->where('field_name', 'contact_via')
            ->update(['sort_order' => 4]);
    }
};
