<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            // For daily recurring tasks: store time only (e.g., "14:30")
            $table->time('recurrence_time')->nullable()->after('recurrence');

            // For weekly recurring tasks: store day of week (1=Monday, 7=Sunday)
            $table->integer('recurrence_day_of_week')->nullable()->after('recurrence_time');

            // For monthly recurring tasks: store day number (1-31), or special values:
            // -1 = first day of month, -2 = last day of month
            $table->integer('recurrence_day_of_month')->nullable()->after('recurrence_day_of_week');

            // Track when this recurring task was last processed
            $table->timestampTz('last_recurrence_at')->nullable()->after('recurrence_day_of_month');

            // Index for performance
            $table->index('last_recurrence_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['last_recurrence_at']);
            $table->dropColumn([
                'recurrence_time',
                'recurrence_day_of_week',
                'recurrence_day_of_month',
                'last_recurrence_at',
            ]);
        });
    }
};
