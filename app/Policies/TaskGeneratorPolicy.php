<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\TaskGenerator;
use App\Models\User;
use App\Traits\HasDealershipAccess;
use Illuminate\Auth\Access\Response;

class TaskGeneratorPolicy
{
    use HasDealershipAccess;

    /**
     * Доступ к генератору задач: owner или доступ к дилерству генератора.
     */
    public function view(User $user, TaskGenerator $generator): Response
    {
        if ($this->isOwner($user)) {
            return Response::allow();
        }

        if ($this->hasAccessToDealership($user, $generator->dealership_id)) {
            return Response::allow();
        }

        return Response::deny('Нет доступа к этому генератору задач');
    }

    /**
     * Обновление генератора: owner или доступ к дилерству генератора.
     */
    public function update(User $user, TaskGenerator $generator): Response
    {
        return $this->view($user, $generator);
    }

    /**
     * Удаление генератора: owner или доступ к дилерству генератора.
     */
    public function delete(User $user, TaskGenerator $generator): Response
    {
        return $this->view($user, $generator);
    }
}
