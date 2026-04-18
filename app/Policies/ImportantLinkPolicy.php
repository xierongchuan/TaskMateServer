<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Role;
use App\Models\ImportantLink;
use App\Models\User;
use App\Services\DealershipAccessService;
use Illuminate\Auth\Access\Response;

class ImportantLinkPolicy
{
    public function __construct(
        private readonly DealershipAccessService $dealershipAccess,
    ) {}

    /**
     * Просмотр ссылки: доступ к дилерству ссылки (null = общедоступная).
     */
    public function view(User $user, ImportantLink $link): Response
    {
        if ($this->dealershipAccess->isOwner($user)) {
            return Response::allow();
        }

        if ($link->dealership_id !== null && $this->dealershipAccess->hasAccessToDealership($user, $link->dealership_id)) {
            return Response::allow();
        }

        return Response::deny('Нет доступа к указанному дилерству');
    }

    /**
     * Создание ссылки: только manager/owner.
     */
    public function create(User $user): Response
    {
        if (! in_array($user->role, [Role::MANAGER, Role::OWNER], true)) {
            return Response::deny('Только менеджеры и владельцы могут создавать важные ссылки');
        }

        return Response::allow();
    }

    /**
     * Редактирование ссылки: только manager/owner с доступом к дилерству.
     */
    public function update(User $user, ImportantLink $link): Response
    {
        if (! in_array($user->role, [Role::MANAGER, Role::OWNER], true)) {
            return Response::deny('У вас нет прав для редактирования этой ссылки');
        }

        return $this->view($user, $link);
    }

    /**
     * Удаление ссылки: только manager/owner с доступом к дилерству.
     */
    public function delete(User $user, ImportantLink $link): Response
    {
        if (! in_array($user->role, [Role::MANAGER, Role::OWNER], true)) {
            return Response::deny('У вас нет прав для удаления этой ссылки');
        }

        return $this->view($user, $link);
    }
}
