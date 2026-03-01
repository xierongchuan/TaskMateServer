<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\TaskGenerator;
use App\Models\User;
use App\Services\DealershipAccessService;
use Illuminate\Auth\Access\Response;

class TaskGeneratorPolicy
{
    public function __construct(
        private readonly DealershipAccessService $dealershipAccess,
    ) {}

    /**
     * Доступ к генератору задач: owner или доступ к дилерству генератора.
     */
    public function view(User $user, TaskGenerator $generator): Response
    {
        if ($this->dealershipAccess->isOwner($user)) {
            return Response::allow();
        }

        if ($this->dealershipAccess->hasAccessToDealership($user, $generator->dealership_id)) {
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

    /**
     * Приостановка генератора: owner или доступ к дилерству генератора.
     */
    public function pause(User $user, TaskGenerator $generator): Response
    {
        return $this->view($user, $generator);
    }

    /**
     * Возобновление генератора: owner или доступ к дилерству генератора.
     */
    public function resume(User $user, TaskGenerator $generator): Response
    {
        return $this->view($user, $generator);
    }

    /**
     * Просмотр статистики генератора: owner или доступ к дилерству генератора.
     */
    public function viewStatistics(User $user, TaskGenerator $generator): Response
    {
        return $this->view($user, $generator);
    }

    /**
     * Просмотр сгенерированных задач: owner или доступ к дилерству генератора.
     */
    public function viewGeneratedTasks(User $user, TaskGenerator $generator): Response
    {
        return $this->view($user, $generator);
    }
}
