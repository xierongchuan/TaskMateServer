<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remove recurring task fields from tasks table.
 *
 * Tasks are not recurring themselves - they are created by TaskGenerators.
 * This migration removes the legacy recurrence functionality from tasks.
 */
return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            // Drop index first
            $table->dropIndex(['last_recurrence_at']);

            // Drop all recurrence-related columns
            $table->dropColumn([
                'recurrence',
                'recurrence_time',
                'recurrence_day_of_week',
                'recurrence_day_of_month',
                'last_recurrence_at',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            // Restore columns
            $table->string('recurrence', 50)->nullable()->after('scheduled_date');
            $table->time('recurrence_time')->nullable()->after('recurrence');
            $table->integer('recurrence_day_of_week')->nullable()->after('recurrence_time');
            $table->integer('recurrence_day_of_month')->nullable()->after('recurrence_day_of_week');
            $table->timestampTz('last_recurrence_at')->nullable()->after('recurrence_day_of_month');

            // Restore index
            $table->index('last_recurrence_at');
        });
    }
};
