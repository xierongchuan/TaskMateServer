<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Role;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ShiftPolicy
{
    /**
     * Просмотр смены: owner — все, manager — в своих дилерствах, employee — свои смены, observer — в своих дилерствах.
     */
    public function view(User $user, Shift $shift): Response
    {
        if ($user->role === Role::OWNER) {
            return Response::allow();
        }

        $accessibleIds = $user->getAccessibleDealershipIds();

        if (in_array($user->role, [Role::MANAGER, Role::OBSERVER])) {
            if (in_array($shift->dealership_id, $accessibleIds, true)) {
                return Response::allow();
            }

            return Response::deny('Нет доступа к данной смене');
        }

        // Employee — только свои смены
        if ($user->role === Role::EMPLOYEE && $shift->user_id === $user->id) {
            return Response::allow();
        }

        return Response::deny('Нет доступа к данной смене');
    }

    /**
     * Открытие смены за другого пользователя: только owner.
     */
    public function createForOther(User $currentUser, User $targetUser): Response
    {
        if ($currentUser->id === $targetUser->id) {
            // Своя смена — проверяем роль
            if (in_array($currentUser->role, [Role::OWNER, Role::EMPLOYEE])) {
                return Response::allow();
            }

            return Response::deny('Открытие смен через админку доступно только Владельцу и сотрудникам (для своих смен).');
        }

        if ($currentUser->role === Role::OWNER) {
            return Response::allow();
        }

        return Response::deny('Только Владелец может открывать смены за других пользователей');
    }

    /**
     * Редактирование/закрытие смены: owner для любой, manager — в своих дилерствах, employee для своей.
     */
    public function update(User $user, Shift $shift): Response
    {
        if ($user->role === Role::OWNER) {
            return Response::allow();
        }

        $isOwnShift = $shift->user_id === $user->id;

        if ($user->role === Role::MANAGER) {
            $accessibleIds = $user->getAccessibleDealershipIds();
            if (in_array($shift->dealership_id, $accessibleIds, true)) {
                return Response::allow();
            }

            return Response::deny('Нет доступа к смене в этом дилерстве');
        }

        if ($user->role === Role::EMPLOYEE && $isOwnShift) {
            return Response::allow();
        }

        return Response::deny('Управление сменами через админку доступно только Владельцу и сотрудникам (для своих смен).');
    }

    /**
     * Удаление смены: только owner или manager в своих дилерствах.
     */
    public function delete(User $user, Shift $shift): Response
    {
        if ($user->role === Role::OWNER) {
            return Response::allow();
        }

        if ($user->role === Role::MANAGER) {
            $accessibleIds = $user->getAccessibleDealershipIds();
            if (in_array($shift->dealership_id, $accessibleIds, true)) {
                return Response::allow();
            }

            return Response::deny('Нет доступа к смене в этом дилерстве');
        }

        return Response::deny('Удаление смен доступно только Владельцу и менеджерам');
    }
}
