<?php

declare(strict_types=1);

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
        Schema::table('notification_settings', function (Blueprint $table) {
            $table->integer('notification_offset')->nullable()->after('notification_time');
        });

        // Set default offsets for existing records
        DB::statement("
            UPDATE notification_settings
            SET notification_offset = 30
            WHERE channel_type = 'task_deadline_30min'
        ");

        DB::statement("
            UPDATE notification_settings
            SET notification_offset = 60
            WHERE channel_type = 'task_hour_late'
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notification_settings', function (Blueprint $table) {
            $table->dropColumn('notification_offset');
        });
    }
};
