<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\TaskResponse;
use App\Models\User;
use App\Services\DealershipAccessService;
use Illuminate\Auth\Access\Response;

class TaskResponsePolicy
{
    public function __construct(
        private readonly DealershipAccessService $dealershipAccess,
    ) {}

    /**
     * Одобрение/отклонение ответа: owner или доступ к дилерству задачи.
     */
    public function verify(User $user, TaskResponse $taskResponse): Response
    {
        $task = $taskResponse->task;

        if ($this->dealershipAccess->isOwner($user)) {
            return Response::allow();
        }

        if ($this->dealershipAccess->hasAccessToDealership($user, $task->dealership_id)) {
            return Response::allow();
        }

        return Response::deny('У вас нет доступа к этой задаче');
    }
}
