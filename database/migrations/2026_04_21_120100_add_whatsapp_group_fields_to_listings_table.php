<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            if (!Schema::hasColumn('listings', 'whatsapp_mode')) {
                $table->string('whatsapp_mode', 20)
                    ->default('single')
                    ->after('whatsapp_phone');
            }

            if (!Schema::hasColumn('listings', 'whatsapp_group_number_ids')) {
                $table->json('whatsapp_group_number_ids')
                    ->nullable()
                    ->after('whatsapp_mode');
            }

            if (!Schema::hasColumn('listings', 'current_whatsapp_group_index')) {
                $table->unsignedInteger('current_whatsapp_group_index')
                    ->default(0)
                    ->after('whatsapp_group_number_ids');
            }
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            if (Schema::hasColumn('listings', 'current_whatsapp_group_index')) {
                $table->dropColumn('current_whatsapp_group_index');
            }

            if (Schema::hasColumn('listings', 'whatsapp_group_number_ids')) {
                $table->dropColumn('whatsapp_group_number_ids');
            }

            if (Schema::hasColumn('listings', 'whatsapp_mode')) {
                $table->dropColumn('whatsapp_mode');
            }
        });
    }
};
