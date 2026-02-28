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
     * Редактирование/закрытие смены: owner для любой, employee для своей.
     */
    public function update(User $user, Shift $shift): Response
    {
        $isOwnShift = $shift->user_id === $user->id;

        if (! $isOwnShift && $user->role !== Role::OWNER) {
            return Response::deny('Редактирование смен других пользователей доступно только Владельцу');
        }

        $canEditShift = $user->role === Role::OWNER ||
                        ($user->role === Role::EMPLOYEE && $isOwnShift);

        if (! $canEditShift) {
            return Response::deny('Управление сменами через админку доступно только Владельцу и сотрудникам (для своих смен).');
        }

        return Response::allow();
    }
}
