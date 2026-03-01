<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AutoDealership;
use App\Models\User;
use App\Services\DealershipAccessService;
use Illuminate\Auth\Access\Response;

class DealershipPolicy
{
    public function __construct(
        private readonly DealershipAccessService $dealershipAccess,
    ) {}

    /**
     * Просмотр дилерства: owner или доступ к дилерству.
     */
    public function view(User $user, AutoDealership $dealership): Response
    {
        if ($this->dealershipAccess->isOwner($user)) {
            return Response::allow();
        }

        if ($this->dealershipAccess->hasAccessToDealership($user, $dealership->id)) {
            return Response::allow();
        }

        return Response::deny('Нет доступа к указанному дилерству');
    }
}
