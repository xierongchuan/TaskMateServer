<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Добавление полей для отслеживания источника отправки доказательств.
 *
 * submission_source: как были созданы доказательства
 *   - 'individual' - сотрудник отправил сам
 *   - 'shared' - менеджер выполнил за всех (complete_for_all)
 *   - 'resubmitted' - переотправлено после отклонения
 *
 * uses_shared_proofs: использует ли response общие файлы задачи
 *   - true: отображать shared_proofs из Task
 *   - false: отображать индивидуальные proofs из TaskProof
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_responses', function (Blueprint $table) {
            $table->string('submission_source', 20)->default('individual')->after('rejection_count');
            $table->boolean('uses_shared_proofs')->default(false)->after('submission_source');

            $table->index('submission_source');
        });

        // Миграция данных: пометить существующие responses с shared_proofs
        $this->migrateExistingData();
    }

    public function down(): void
    {
        Schema::table('task_responses', function (Blueprint $table) {
            $table->dropIndex(['submission_source']);
            $table->dropColumn(['submission_source', 'uses_shared_proofs']);
        });
    }

    /**
     * Пометить существующие responses, которые используют shared_proofs.
     */
    private function migrateExistingData(): void
    {
        // Получаем task_id задач, у которых есть shared_proofs
        $taskIdsWithSharedProofs = \DB::table('task_shared_proofs')
            ->distinct()
            ->pluck('task_id');

        if ($taskIdsWithSharedProofs->isEmpty()) {
            return;
        }

        // Обновляем responses этих задач
        \DB::table('task_responses')
            ->whereIn('task_id', $taskIdsWithSharedProofs)
            ->where('submission_source', 'individual')
            ->whereIn('status', ['pending_review', 'completed'])
            ->update([
                'submission_source' => 'shared',
                'uses_shared_proofs' => true,
            ]);
    }
};
