<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DelegationStatus;
use App\Helpers\TimeHelper;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskDelegation;
use App\Models\TaskResponse;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Сервис для управления делегированием задач.
 *
 * Обрабатывает создание, принятие, отклонение и отмену запросов
 * на делегирование. Все мутации выполняются в транзакциях с блокировками.
 */
class TaskDelegationService
{
    /**
     * Создать запрос на делегирование задачи.
     *
     * @param  Task  $task  Задача для делегирования
     * @param  User  $fromUser  Текущий исполнитель
     * @param  User  $toUser  Целевой сотрудник
     * @param  string|null  $reason  Причина делегирования
     *
     * @throws \DomainException Если делегирование невозможно
     */
    public function createDelegation(Task $task, User $fromUser, User $toUser, ?string $reason): TaskDelegation
    {
        return DB::transaction(function () use ($task, $fromUser, $toUser, $reason) {
            // Блокируем задачу для предотвращения race conditions
            Task::where('id', $task->id)->lockForUpdate()->first();

            // Проверка: from_user назначен на задачу
            $isAssigned = TaskAssignment::where('task_id', $task->id)
                ->where('user_id', $fromUser->id)
                ->exists();

            if (! $isAssigned) {
                throw new \DomainException('Вы не назначены на эту задачу');
            }

            // Проверка: нет pending делегации от этого пользователя для этой задачи
            $existingPending = TaskDelegation::where('task_id', $task->id)
                ->where('from_user_id', $fromUser->id)
                ->where('status', DelegationStatus::PENDING)
                ->exists();

            if ($existingPending) {
                throw new \DomainException('У вас уже есть активный запрос на делегирование этой задачи');
            }

            // Проверка: статус response позволяет делегирование
            $response = TaskResponse::where('task_id', $task->id)
                ->where('user_id', $fromUser->id)
                ->first();

            if ($response && in_array($response->status, ['completed', 'pending_review'])) {
                throw new \DomainException('Нельзя делегировать задачу, которая уже выполнена или на проверке');
            }

            // Проверка для групповых задач: target не назначен на эту задачу
            if ($task->task_type === 'group') {
                $targetAlreadyAssigned = TaskAssignment::where('task_id', $task->id)
                    ->where('user_id', $toUser->id)
                    ->exists();

                if ($targetAlreadyAssigned) {
                    throw new \DomainException('Этот сотрудник уже назначен на данную задачу');
                }
            }

            return TaskDelegation::create([
                'task_id' => $task->id,
                'from_user_id' => $fromUser->id,
                'to_user_id' => $toUser->id,
                'status' => DelegationStatus::PENDING,
                'reason' => $reason,
            ]);
        });
    }

    /**
     * Принять запрос на делегирование.
     *
     * Переназначает задачу: soft-delete assignment от from_user,
     * создаёт/восстанавливает assignment для to_user, удаляет
     * незавершённый response от from_user.
     *
     * @throws \DomainException Если запрос уже обработан
     */
    public function accept(TaskDelegation $delegation): TaskDelegation
    {
        return DB::transaction(function () use ($delegation) {
            $delegation = TaskDelegation::where('id', $delegation->id)->lockForUpdate()->first();

            if (! $delegation->isPending()) {
                throw new \DomainException('Этот запрос уже обработан');
            }

            Task::where('id', $delegation->task_id)->lockForUpdate()->first();

            // 1. Soft-delete assignment от from_user (сохраняет историю)
            TaskAssignment::where('task_id', $delegation->task_id)
                ->where('user_id', $delegation->from_user_id)
                ->delete();

            // 2. Создать или восстановить assignment для to_user
            $existing = TaskAssignment::withTrashed()
                ->where('task_id', $delegation->task_id)
                ->where('user_id', $delegation->to_user_id)
                ->first();

            if ($existing && $existing->trashed()) {
                $existing->restore();
            } elseif (! $existing) {
                TaskAssignment::create([
                    'task_id' => $delegation->task_id,
                    'user_id' => $delegation->to_user_id,
                ]);
            }

            // 3. Удалить response от from_user если незавершённый
            $fromResponse = TaskResponse::where('task_id', $delegation->task_id)
                ->where('user_id', $delegation->from_user_id)
                ->first();

            if ($fromResponse && in_array($fromResponse->status, ['pending', 'acknowledged', 'rejected'])) {
                $fromResponse->delete();
            }

            // 4. Обновить статус делегации
            $delegation->update([
                'status' => DelegationStatus::ACCEPTED,
                'responded_at' => TimeHelper::nowUtc(),
            ]);

            return $delegation;
        });
    }

    /**
     * Отклонить запрос на делегирование.
     *
     * @param  string  $rejectionReason  Причина отказа
     *
     * @throws \DomainException Если запрос уже обработан
     */
    public function reject(TaskDelegation $delegation, string $rejectionReason): TaskDelegation
    {
        return DB::transaction(function () use ($delegation, $rejectionReason) {
            $delegation = TaskDelegation::where('id', $delegation->id)->lockForUpdate()->first();

            if (! $delegation->isPending()) {
                throw new \DomainException('Этот запрос уже обработан');
            }

            $delegation->update([
                'status' => DelegationStatus::REJECTED,
                'rejection_reason' => $rejectionReason,
                'responded_at' => TimeHelper::nowUtc(),
            ]);

            return $delegation;
        });
    }

    /**
     * Отменить запрос на делегирование.
     *
     * Может быть выполнена инициатором или менеджером/владельцем.
     *
     * @param  User  $cancelledBy  Кто отменяет
     *
     * @throws \DomainException Если запрос не в статусе pending
     */
    public function cancel(TaskDelegation $delegation, User $cancelledBy): TaskDelegation
    {
        return DB::transaction(function () use ($delegation, $cancelledBy) {
            $delegation = TaskDelegation::where('id', $delegation->id)->lockForUpdate()->first();

            if (! $delegation->isPending()) {
                throw new \DomainException('Можно отменить только ожидающий запрос');
            }

            $delegation->update([
                'status' => DelegationStatus::CANCELLED,
                'cancelled_by' => $cancelledBy->id,
                'responded_at' => TimeHelper::nowUtc(),
            ]);

            return $delegation;
        });
    }
}
