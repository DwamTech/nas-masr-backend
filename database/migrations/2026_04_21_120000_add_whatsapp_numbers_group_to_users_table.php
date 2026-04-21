<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'whatsapp_numbers_group')) {
                $table->json('whatsapp_numbers_group')
                    ->nullable()
                    ->after('profile_image');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'whatsapp_numbers_group')) {
                $table->dropColumn('whatsapp_numbers_group');
            }
        });
    }
};
