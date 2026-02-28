<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\TaskResponse;
use App\Models\User;
use App\Traits\HasDealershipAccess;
use Illuminate\Auth\Access\Response;

class TaskResponsePolicy
{
    use HasDealershipAccess;

    /**
     * Одобрение/отклонение ответа: owner или доступ к дилерству задачи.
     */
    public function verify(User $user, TaskResponse $taskResponse): Response
    {
        $task = $taskResponse->task;

        if ($this->isOwner($user)) {
            return Response::allow();
        }

        if ($this->hasAccessToDealership($user, $task->dealership_id)) {
            return Response::allow();
        }

        return Response::deny('У вас нет доступа к этой задаче');
    }
}
