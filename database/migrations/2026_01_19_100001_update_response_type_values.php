<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Обновление значений response_type:
     * - acknowledge → notification
     * - complete → completion
     * + добавление нового типа: completion_with_proof
     */
    public function up(): void
    {
        // Обновляем tasks
        DB::table('tasks')
            ->where('response_type', 'acknowledge')
            ->update(['response_type' => 'notification']);

        DB::table('tasks')
            ->where('response_type', 'complete')
            ->update(['response_type' => 'completion']);

        // Обновляем task_generators
        DB::table('task_generators')
            ->where('response_type', 'acknowledge')
            ->update(['response_type' => 'notification']);

        DB::table('task_generators')
            ->where('response_type', 'complete')
            ->update(['response_type' => 'completion']);
    }

    /**
     * Откат изменений.
     */
    public function down(): void
    {
        // Откатываем tasks
        DB::table('tasks')
            ->where('response_type', 'notification')
            ->update(['response_type' => 'acknowledge']);

        DB::table('tasks')
            ->where('response_type', 'completion')
            ->update(['response_type' => 'complete']);

        DB::table('tasks')
            ->where('response_type', 'completion_with_proof')
            ->update(['response_type' => 'complete']);

        // Откатываем task_generators
        DB::table('task_generators')
            ->where('response_type', 'notification')
            ->update(['response_type' => 'acknowledge']);

        DB::table('task_generators')
            ->where('response_type', 'completion')
            ->update(['response_type' => 'complete']);

        DB::table('task_generators')
            ->where('response_type', 'completion_with_proof')
            ->update(['response_type' => 'complete']);
    }
};
