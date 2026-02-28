<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AutoDealership;
use App\Models\User;
use App\Traits\HasDealershipAccess;
use Illuminate\Auth\Access\Response;

class DealershipPolicy
{
    use HasDealershipAccess;

    /**
     * Просмотр дилерства: owner или доступ к дилерству.
     */
    public function view(User $user, AutoDealership $dealership): Response
    {
        if ($this->isOwner($user)) {
            return Response::allow();
        }

        if ($this->hasAccessToDealership($user, $dealership->id)) {
            return Response::allow();
        }

        return Response::deny('Нет доступа к указанному дилерству');
    }
}
