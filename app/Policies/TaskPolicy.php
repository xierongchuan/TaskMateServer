<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Role;
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
        if ($task->trashed()) {
            return Response::deny('Задача удалена');
        }

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
        if ($task->trashed()) {
            return Response::deny('Задача удалена');
        }

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
        if ($task->trashed()) {
            return Response::deny('Задача удалена');
        }

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
     * Обновление статуса задачи: только owner, менеджер, сотрудник.
     * Наблюдатели (observer) не могут менять статус.
     * Глобальные задачи (без dealership_id) — только owner или назначенные.
     */
    public function updateStatus(User $user, Task $task): Response
    {
        if ($task->trashed()) {
            return Response::deny('Задача удалена');
        }

        // Наблюдатели не могут менять статус задач
        if ($user->role === Role::OBSERVER) {
            return Response::deny('Наблюдатели не могут изменять статус задач');
        }

        if ($this->dealershipAccess->isOwner($user)) {
            return Response::allow();
        }

        // Глобальные задачи (без dealership_id): только назначенные или создатель
        if ($task->dealership_id === null) {
            if ($task->creator_id === $user->id) {
                return Response::allow();
            }

            if ($task->assignments->contains('user_id', $user->id)) {
                return Response::allow();
            }

            return Response::deny('Нет доступа к этой задаче');
        }

        if ($this->dealershipAccess->hasAccessToDealership($user, $task->dealership_id)) {
            return Response::allow();
        }

        return Response::deny('Нет доступа к указанному дилерству');
    }
}
