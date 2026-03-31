<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\TaskApproved;
use App\Events\TaskRejected;
use App\Events\TaskRejectedBulk;
use App\Helpers\TimeHelper;
use App\Models\Task;
use App\Models\TaskResponse;
use App\Models\TaskVerificationHistory;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Сервис для верификации доказательств выполнения задач.
 *
 * Централизует логику одобрения/отклонения доказательств
 * и ведет историю верификации.
 */
class TaskVerificationService
{
    public function __construct(
        private readonly TaskProofService $taskProofService
    ) {}

    /**
     * Одобрить доказательство выполнения задачи.
     *
     * @param  TaskResponse  $response  Ответ на задачу
     * @param  User  $verifier  Верификатор (менеджер/владелец)
     * @return TaskResponse Обновленный ответ
     */
    public function approve(TaskResponse $response, User $verifier): TaskResponse
    {
        $result = DB::transaction(function () use ($response, $verifier) {
            $previousStatus = $response->status;
            $proofCount = $response->relationLoaded('proofs') ? $response->proofs->count() : $response->proofs()->count();

            $response->update([
                'status' => 'completed',
                'verified_at' => TimeHelper::nowUtc(),
                'verified_by' => $verifier->id,
                'rejection_reason' => null,
            ]);

            $this->recordHistory(
                $response,
                TaskVerificationHistory::ACTION_APPROVED,
                $verifier,
                $previousStatus,
                'completed',
                $proofCount
            );

            return $response->fresh();
        });

        event(new TaskApproved($result));

        return $result;
    }

    /**
     * Отклонить доказательство выполнения задачи.
     *
     * ВАЖНО: Всегда отклоняет только один response, независимо от наличия shared_proofs.
     * Для группового отклонения используйте rejectAllForTask().
     *
     * @param  TaskResponse  $response  Ответ на задачу
     * @param  User  $verifier  Верификатор (менеджер/владелец)
     * @param  string  $reason  Причина отклонения
     * @return TaskResponse Обновленный ответ
     */
    public function reject(TaskResponse $response, User $verifier, string $reason): TaskResponse
    {
        $result = DB::transaction(function () use ($response, $verifier, $reason) {
            $previousStatus = $response->status;

            $proofCount = $this->deleteProofsForResponse($response);

            // Обновляем response - переключаем на индивидуальный режим
            $response->update([
                'status' => 'rejected',
                'verified_at' => null,
                'verified_by' => null,
                'rejection_reason' => $reason,
                'rejection_count' => ($response->rejection_count ?? 0) + 1,
                'uses_shared_proofs' => false, // Для переотправки нужны индивидуальные файлы
            ]);

            // Записываем в историю
            $this->recordHistory(
                $response,
                TaskVerificationHistory::ACTION_REJECTED,
                $verifier,
                $previousStatus,
                'rejected',
                $proofCount,
                $reason
            );

            return $response->fresh();
        });

        event(new TaskRejected($result, $reason));

        return $result;
    }

    /**
     * Отклонить один response (внутренний метод для bulk reject).
     *
     * @param  TaskResponse  $response  Ответ на задачу
     * @param  User  $verifier  Верификатор
     * @param  string  $reason  Причина отклонения
     */
    private function rejectSingleResponse(TaskResponse $response, User $verifier, string $reason): void
    {
        $previousStatus = $response->status;

        // В bulk-режиме shared proofs удаляются один раз до цикла
        $proofCount = $this->deleteProofsForResponse($response, false);

        $response->update([
            'status' => 'rejected',
            'verified_at' => null,
            'verified_by' => null,
            'rejection_reason' => $reason,
            'rejection_count' => ($response->rejection_count ?? 0) + 1,
            'uses_shared_proofs' => false, // Для переотправки нужны индивидуальные файлы
        ]);

        $this->recordHistory(
            $response,
            TaskVerificationHistory::ACTION_REJECTED,
            $verifier,
            $previousStatus,
            'rejected',
            $proofCount,
            $reason
        );
    }

    /**
     * Отклонить все pending_review ответы для задачи.
     *
     * Универсальный метод — работает для любых групповых задач,
     * независимо от наличия shared_proofs.
     *
     * @param  Task  $task  Задача
     * @param  User  $verifier  Верификатор (менеджер/владелец)
     * @param  string  $reason  Причина отклонения
     */
    public function rejectAllForTask(Task $task, User $verifier, string $reason): void
    {
        $rejectedUserIds = [];

        DB::transaction(function () use ($task, $verifier, $reason, &$rejectedUserIds) {
            $pendingResponses = $task->responses()
                ->where('status', 'pending_review')
                ->get();

            // Удаляем shared_proofs один раз до цикла (все responses одной задачи)
            $hasSharedProofs = $task->sharedProofs()->exists();
            if ($hasSharedProofs) {
                $this->taskProofService->deleteSharedProofs($task);
            }

            foreach ($pendingResponses as $response) {
                $this->rejectSingleResponse($response, $verifier, $reason);
                $rejectedUserIds[] = $response->user_id;
            }
        });

        if (! empty($rejectedUserIds)) {
            event(new TaskRejectedBulk($task, $rejectedUserIds, $reason));
        }
    }

    /**
     * Записать повторную отправку доказательства.
     *
     * @param  TaskResponse  $response  Ответ на задачу
     * @param  User  $employee  Сотрудник
     */
    public function recordResubmission(TaskResponse $response, User $employee): void
    {
        $proofCount = $response->relationLoaded('proofs') ? $response->proofs->count() : $response->proofs()->count();

        $this->recordHistory(
            $response,
            TaskVerificationHistory::ACTION_RESUBMITTED,
            $employee,
            'rejected',
            'pending_review',
            $proofCount
        );
    }

    /**
     * Записать первоначальную отправку доказательства.
     *
     * @param  TaskResponse  $response  Ответ на задачу
     * @param  User  $employee  Сотрудник
     */
    public function recordSubmission(TaskResponse $response, User $employee): void
    {
        $proofCount = $response->relationLoaded('proofs') ? $response->proofs->count() : $response->proofs()->count();

        $this->recordHistory(
            $response,
            TaskVerificationHistory::ACTION_SUBMITTED,
            $employee,
            'pending',
            'pending_review',
            $proofCount
        );
    }

    /**
     * Удаляет файлы доказательств для ответа.
     *
     * Для индивидуальных файлов — удаляет proofs ответа.
     * Для shared файлов — удаляет shared_proofs задачи.
     *
     * @param  bool  $deleteShared  Удалять ли shared proofs (false в bulk-режиме)
     * @return int Количество удалённых файлов
     */
    private function deleteProofsForResponse(TaskResponse $response, bool $deleteShared = true): int
    {
        if (! $response->uses_shared_proofs) {
            $proofCount = $response->relationLoaded('proofs') ? $response->proofs->count() : $response->proofs()->count();
            $this->taskProofService->deleteAllProofs($response);

            return $proofCount;
        }

        if ($deleteShared) {
            $task = $response->task;
            $proofCount = $task->sharedProofs()->count();
            $this->taskProofService->deleteSharedProofs($task);

            return $proofCount;
        }

        return 0;
    }

    /**
     * Записать действие в историю верификации.
     */
    private function recordHistory(
        TaskResponse $response,
        string $action,
        User $performer,
        string $previousStatus,
        string $newStatus,
        int $proofCount,
        ?string $reason = null
    ): void {
        TaskVerificationHistory::create([
            'task_response_id' => $response->id,
            'action' => $action,
            'performed_by' => $performer->id,
            'reason' => $reason,
            'previous_status' => $previousStatus,
            'new_status' => $newStatus,
            'proof_count' => $proofCount,
            'created_at' => TimeHelper::nowUtc(),
        ]);
    }
}
