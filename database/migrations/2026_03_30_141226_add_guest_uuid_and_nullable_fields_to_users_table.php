<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Allow guest creation: phone and password are no longer required
            $table->string('phone')->nullable()->change();
            $table->string('password')->nullable()->change();

            // Guest identifier
            $table->string('guest_uuid')->nullable()->unique()->after('fcm_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('guest_uuid');
            $table->string('phone')->nullable(false)->change();
            $table->string('password')->nullable(false)->change();
        });
    }
};
