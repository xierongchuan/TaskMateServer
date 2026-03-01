<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;
use App\Services\DealershipAccessService;
use Illuminate\Auth\Access\Response;

/**
 * Policy для управления доступом к операциям над пользователями.
 *
 * Проверяет только ролевые и дилерственные ограничения.
 * Бизнес-логика (проверка пароля, построение updateData) — в UserService.
 */
class UserPolicy
{
    public function __construct(
        private readonly DealershipAccessService $dealershipAccess,
    ) {}

    /**
     * Создание нового пользователя: только manager или owner.
     *
     * Тонкая проверка — создание owner-пользователей ограничивается
     * в UserService (после PolicyCheck), так как Policy не знает данных запроса.
     */
    public function create(User $authUser): Response
    {
        if ($authUser->role === Role::MANAGER || $authUser->role === Role::OWNER) {
            return Response::allow();
        }

        return Response::deny('Только Управляющий или Владелец может создавать пользователей');
    }

    /**
     * Обновление пользователя.
     *
     * Правила:
     * - Owner может обновлять любого пользователя из доступных дилерств (или себя)
     * - Manager может обновлять employee/observer своих дилерств (или себя)
     * - Employee/Observer может обновлять только себя (с ограничениями в UserService)
     * - Manager не может обновлять другого Manager или Owner (кроме как себя)
     */
    public function update(User $authUser, User $targetUser): Response
    {
        // Каждый пользователь может редактировать себя (ограниченно — в UserService)
        if ($authUser->id === $targetUser->id) {
            return Response::allow();
        }

        // Employee и Observer не могут редактировать других пользователей
        if ($authUser->role === Role::EMPLOYEE || $authUser->role === Role::OBSERVER) {
            return Response::deny('У вас нет прав для редактирования пользователей');
        }

        // Non-owner не может редактировать Owner
        if ($targetUser->role === Role::OWNER && ! $this->dealershipAccess->isOwner($authUser)) {
            return Response::deny('У вас нет прав для редактирования Владельца');
        }

        // Non-owner не может редактировать другого Manager
        if ($targetUser->role === Role::MANAGER && ! $this->dealershipAccess->isOwner($authUser)) {
            return Response::deny('У вас нет прав для редактирования Управляющего');
        }

        // Owner — полный доступ без ограничений по дилерствам
        if ($this->dealershipAccess->isOwner($authUser)) {
            return Response::allow();
        }

        // Manager — проверяем общие дилерства с целевым пользователем
        if (! $this->dealershipAccess->hasAccessToUser($authUser, $targetUser)) {
            return Response::deny('У вас нет прав для редактирования сотрудника другого автосалона');
        }

        return Response::allow();
    }

    /**
     * Удаление пользователя.
     *
     * Правила:
     * - Нельзя удалить себя (проверяется здесь для раннего отказа)
     * - Только Owner может удалять Owner
     * - Только Owner может удалять Manager
     * - Manager может удалять Employee/Observer своих дилерств
     */
    public function delete(User $authUser, User $targetUser): Response
    {
        // Нельзя удалить себя
        if ($authUser->id === $targetUser->id) {
            return Response::deny('Вы не можете удалить свой собственный аккаунт');
        }

        // Employee и Observer не могут удалять никого
        if ($authUser->role === Role::EMPLOYEE || $authUser->role === Role::OBSERVER) {
            return Response::deny('У вас нет прав для удаления пользователей');
        }

        // Только Owner может удалять Owner
        if ($targetUser->role === Role::OWNER && ! $this->dealershipAccess->isOwner($authUser)) {
            return Response::deny('У вас нет прав для удаления Владельца');
        }

        // Только Owner может удалять Manager
        if ($targetUser->role === Role::MANAGER && ! $this->dealershipAccess->isOwner($authUser)) {
            return Response::deny('У вас нет прав для удаления Управляющего');
        }

        // Owner — полный доступ без ограничений по дилерствам
        if ($this->dealershipAccess->isOwner($authUser)) {
            return Response::allow();
        }

        // Manager — проверяем общие дилерства
        if (! $this->dealershipAccess->hasAccessToUser($authUser, $targetUser)) {
            return Response::deny('У вас нет прав для удаления сотрудника другого автосалона');
        }

        return Response::allow();
    }
}
