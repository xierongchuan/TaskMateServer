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
        Schema::table('tasks', function (Blueprint $table) {
            $table->json('notification_settings')->nullable()->after('tags');
        });

        Schema::table('notification_settings', function (Blueprint $table) {
            $table->json('recipient_roles')->nullable()->after('notification_offset');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('notification_settings');
        });

        Schema::table('notification_settings', function (Blueprint $table) {
            $table->dropColumn('recipient_roles');
        });
    }
};
