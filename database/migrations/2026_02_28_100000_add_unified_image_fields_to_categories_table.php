<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->boolean('is_global_image_active')->default(false)->after('is_active');
            $table->string('global_image_url')->nullable()->after('is_global_image_active');
            $table->index('is_global_image_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropIndex(['is_global_image_active']);
            $table->dropColumn(['is_global_image_active', 'global_image_url']);
        });
    }
};
