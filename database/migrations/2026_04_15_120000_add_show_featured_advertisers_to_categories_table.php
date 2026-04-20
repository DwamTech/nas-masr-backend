<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->boolean('show_featured_advertisers')
                ->default(true)
                ->after('is_active');
        });

        DB::table('categories')
            ->whereNull('show_featured_advertisers')
            ->update(['show_featured_advertisers' => true]);
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('show_featured_advertisers');
        });
    }
};
