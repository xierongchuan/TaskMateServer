<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ImportantLink;
use App\Models\User;
use App\Traits\HasDealershipAccess;
use Illuminate\Auth\Access\Response;

class ImportantLinkPolicy
{
    use HasDealershipAccess;

    /**
     * Просмотр ссылки: доступ к дилерству ссылки (null = общедоступная).
     */
    public function view(User $user, ImportantLink $link): Response
    {
        if ($link->dealership_id === null || $this->isOwner($user)) {
            return Response::allow();
        }

        if ($this->hasAccessToDealership($user, $link->dealership_id)) {
            return Response::allow();
        }

        return Response::deny('Нет доступа к указанному дилерству');
    }

    /**
     * Обновление ссылки: доступ к дилерству ссылки.
     */
    public function update(User $user, ImportantLink $link): Response
    {
        if ($link->dealership_id === null || $this->isOwner($user)) {
            return Response::allow();
        }

        if ($this->hasAccessToDealership($user, $link->dealership_id)) {
            return Response::allow();
        }

        return Response::deny('Нет доступа к указанному дилерству');
    }

    /**
     * Удаление ссылки: доступ к дилерству ссылки.
     */
    public function delete(User $user, ImportantLink $link): Response
    {
        if ($link->dealership_id === null || $this->isOwner($user)) {
            return Response::allow();
        }

        if ($this->hasAccessToDealership($user, $link->dealership_id)) {
            return Response::allow();
        }

        return Response::deny('Нет доступа к указанному дилерству');
    }
}
