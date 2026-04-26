<?php

use App\Models\CategoryField;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $field = CategoryField::query()
            ->where('category_slug', 'jobs')
            ->where('field_name', 'contact_via_type')
            ->first();

        if ($field) {
            $options = is_array($field->options) ? $field->options : [];
            $field->options = array_values(array_map(
                fn ($option) => $option === 'البريد الإلكتروني' ? 'الإيميل' : $option,
                $options
            ));
            $field->save();
        }

        DB::table('category_field_option_ranks')
            ->where('option_value', 'البريد الإلكتروني')
            ->update(['option_value' => 'الإيميل']);

        DB::table('listing_attributes')
            ->where('key', 'contact_via_type')
            ->where('value_string', 'البريد الإلكتروني')
            ->update(['value_string' => 'الإيميل']);
    }

    public function down(): void
    {
        $field = CategoryField::query()
            ->where('category_slug', 'jobs')
            ->where('field_name', 'contact_via_type')
            ->first();

        if ($field) {
            $options = is_array($field->options) ? $field->options : [];
            $field->options = array_values(array_map(
                fn ($option) => $option === 'الإيميل' ? 'البريد الإلكتروني' : $option,
                $options
            ));
            $field->save();
        }

        DB::table('category_field_option_ranks')
            ->where('option_value', 'الإيميل')
            ->update(['option_value' => 'البريد الإلكتروني']);

        DB::table('listing_attributes')
            ->where('key', 'contact_via_type')
            ->where('value_string', 'الإيميل')
            ->update(['value_string' => 'البريد الإلكتروني']);
    }
};
