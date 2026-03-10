<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            if (!Schema::hasColumn('listings', 'whatsapp_clicks')) {
                $table->unsignedInteger('whatsapp_clicks')->default(0)->after('views');
            }

            if (!Schema::hasColumn('listings', 'call_clicks')) {
                $table->unsignedInteger('call_clicks')->default(0)->after('whatsapp_clicks');
            }
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            if (Schema::hasColumn('listings', 'call_clicks')) {
                $table->dropColumn('call_clicks');
            }
            if (Schema::hasColumn('listings', 'whatsapp_clicks')) {
                $table->dropColumn('whatsapp_clicks');
            }
        });
    }
};
