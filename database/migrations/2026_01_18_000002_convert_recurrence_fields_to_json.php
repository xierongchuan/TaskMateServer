<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     *
     * Конвертирует поля recurrence_day_of_week и recurrence_day_of_month
     * из integer в JSON массивы для поддержки выбора нескольких дней.
     */
    public function up(): void
    {
        // Шаг 1: Добавляем новые JSON колонки
        Schema::table('task_generators', function (Blueprint $table) {
            $table->json('recurrence_days_of_week')->nullable()->after('deadline_time');
            $table->json('recurrence_days_of_month')->nullable()->after('recurrence_days_of_week');
        });

        // Шаг 2: Мигрируем существующие данные
        // Конвертируем integer в JSON массив: 5 → [5]
        DB::statement("
            UPDATE task_generators
            SET recurrence_days_of_week = jsonb_build_array(recurrence_day_of_week)
            WHERE recurrence_day_of_week IS NOT NULL
        ");

        DB::statement("
            UPDATE task_generators
            SET recurrence_days_of_month = jsonb_build_array(recurrence_day_of_month)
            WHERE recurrence_day_of_month IS NOT NULL
        ");

        // Шаг 3: Удаляем старые колонки
        Schema::table('task_generators', function (Blueprint $table) {
            $table->dropColumn(['recurrence_day_of_week', 'recurrence_day_of_month']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Шаг 1: Добавляем старые колонки обратно
        Schema::table('task_generators', function (Blueprint $table) {
            $table->integer('recurrence_day_of_week')->nullable()->after('deadline_time');
            $table->integer('recurrence_day_of_month')->nullable()->after('recurrence_day_of_week');
        });

        // Шаг 2: Мигрируем данные обратно (берём первый элемент массива)
        // Используем json_array_length для типа JSON (не JSONB)
        DB::statement("
            UPDATE task_generators
            SET recurrence_day_of_week = (recurrence_days_of_week->>0)::integer
            WHERE recurrence_days_of_week IS NOT NULL
              AND json_array_length(recurrence_days_of_week) > 0
        ");

        DB::statement("
            UPDATE task_generators
            SET recurrence_day_of_month = (recurrence_days_of_month->>0)::integer
            WHERE recurrence_days_of_month IS NOT NULL
              AND json_array_length(recurrence_days_of_month) > 0
        ");

        // Шаг 3: Удаляем новые JSON колонки
        Schema::table('task_generators', function (Blueprint $table) {
            $table->dropColumn(['recurrence_days_of_week', 'recurrence_days_of_month']);
        });
    }
};
