<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Task;
use App\Models\User;
use App\Services\DealershipAccessService;
use Illuminate\Auth\Access\Response;

class TaskPolicy
{
    public function __construct(
        private readonly DealershipAccessService $dealershipAccess,
    ) {}

    /**
     * Просмотр задачи: owner, создатель, назначенный, или доступ к дилерству.
     */
    public function view(User $user, Task $task): Response
    {
        if ($this->dealershipAccess->isOwner($user)) {
            return Response::allow();
        }

        if ($task->creator_id === $user->id) {
            return Response::allow();
        }

        if ($task->assignments->contains('user_id', $user->id)) {
            return Response::allow();
        }

        if ($this->dealershipAccess->hasAccessToDealership($user, $task->dealership_id)) {
            return Response::allow();
        }

        return Response::deny('У вас нет доступа к этой задаче');
    }

    /**
     * Редактирование задачи: owner, создатель, или доступ к дилерству.
     */
    public function update(User $user, Task $task): Response
    {
        if ($this->dealershipAccess->isOwner($user)) {
            return Response::allow();
        }

        if ($task->creator_id === $user->id) {
            return Response::allow();
        }

        if ($this->dealershipAccess->hasAccessToDealership($user, $task->dealership_id)) {
            return Response::allow();
        }

        return Response::deny('У вас нет прав для редактирования этой задачи');
    }

    /**
     * Удаление задачи: owner, или (доступ к дилерству || создатель).
     */
    public function delete(User $user, Task $task): Response
    {
        if ($this->dealershipAccess->isOwner($user)) {
            return Response::allow();
        }

        if ($this->dealershipAccess->hasAccessToDealership($user, $task->dealership_id)) {
            return Response::allow();
        }

        if ($task->creator_id === $user->id) {
            return Response::allow();
        }

        return Response::deny('У вас нет прав для удаления этой задачи');
    }

    /**
     * Обновление статуса задачи: доступ к дилерству задачи.
     */
    public function updateStatus(User $user, Task $task): Response
    {
        if ($task->dealership_id === null || $this->dealershipAccess->isOwner($user)) {
            return Response::allow();
        }

        if ($this->dealershipAccess->hasAccessToDealership($user, $task->dealership_id)) {
            return Response::allow();
        }

        return Response::deny('Нет доступа к указанному дилерству');
    }
}
