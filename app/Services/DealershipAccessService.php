<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Role;
use App\Exceptions\AccessDeniedException;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Сервис проверки доступа к дилерствам.
 *
 * Содержит бизнес-логику проверки доступа без HTTP-зависимостей.
 * Бросает исключения вместо возврата JsonResponse,
 * что делает его пригодным для использования в сервисном слое.
 */
class DealershipAccessService
{
    /**
     * Проверяет, является ли пользователь владельцем (owner).
     */
    public function isOwner(User $user): bool
    {
        return $user->role === Role::OWNER;
    }

    /**
     * Получает список ID дилерств, доступных пользователю.
     *
     * @return array<int>
     */
    public function getUserDealershipIds(User $user): array
    {
        return $user->getAccessibleDealershipIds();
    }

    /**
     * Получает все ID дилерств пользователя (основное + прикреплённые).
     *
     * @return array<int>
     */
    public function getAllUserDealershipIds(User $user): array
    {
        $ids = [];

        if ($user->dealership_id) {
            $ids[] = $user->dealership_id;
        }

        $attachedIds = $user->dealerships->pluck('id')->toArray();

        return array_unique(array_merge($ids, $attachedIds));
    }

    /**
     * Проверяет, имеет ли пользователь доступ к указанному дилерству.
     */
    public function hasAccessToDealership(User $user, int $dealershipId): bool
    {
        if ($this->isOwner($user)) {
            return true;
        }

        return in_array($dealershipId, $this->getUserDealershipIds($user), true);
    }

    /**
     * Проверяет доступ к дилерству и бросает исключение если доступа нет.
     *
     * @throws AccessDeniedException
     */
    public function validateAccess(User $user, ?int $dealershipId): void
    {
        if ($dealershipId === null || $this->isOwner($user)) {
            return;
        }

        if (! $this->hasAccessToDealership($user, $dealershipId)) {
            throw new AccessDeniedException('Нет доступа к указанному дилерству');
        }
    }

    /**
     * Проверяет доступ к нескольким дилерствам и бросает исключение если доступа нет.
     *
     * @param  int[]  $dealershipIds
     *
     * @throws AccessDeniedException
     */
    public function validateMultipleAccess(User $user, array $dealershipIds): void
    {
        if (empty($dealershipIds) || $this->isOwner($user)) {
            return;
        }

        $accessibleIds = $this->getUserDealershipIds($user);
        $inaccessible = array_diff($dealershipIds, $accessibleIds);

        if (! empty($inaccessible)) {
            throw new AccessDeniedException('Нет доступа к одному или нескольким дилерствам');
        }
    }

    /**
     * Проверяет, имеет ли текущий пользователь доступ к целевому пользователю
     * через общие дилерства.
     */
    public function hasAccessToUser(User $currentUser, User $targetUser): bool
    {
        if ($this->isOwner($currentUser)) {
            return true;
        }

        $accessibleIds = $this->getUserDealershipIds($currentUser);
        $targetDealershipIds = $this->getAllUserDealershipIds($targetUser);

        // Если у целевого пользователя нет дилерств - доступ есть (orphan user)
        if (empty($targetDealershipIds)) {
            return true;
        }

        return ! empty(array_intersect($targetDealershipIds, $accessibleIds));
    }

    /**
     * Проверяет доступ к целевому пользователю и бросает исключение если доступа нет.
     *
     * @throws AccessDeniedException
     */
    public function validateUserAccess(User $currentUser, User $targetUser): void
    {
        if (! $this->hasAccessToUser($currentUser, $targetUser)) {
            throw new AccessDeniedException('Нет доступа к данному пользователю');
        }
    }

    /**
     * Применяет scope к запросу для фильтрации по доступным дилерствам.
     *
     * @param  string  $dealershipColumn  Название колонки с ID дилерства
     */
    public function scopeByAccessibleDealerships(
        Builder $query,
        User $user,
        string $dealershipColumn = 'dealership_id'
    ): Builder {
        if ($this->isOwner($user)) {
            return $query;
        }

        $accessibleIds = $this->getUserDealershipIds($user);

        return $query->whereIn($dealershipColumn, $accessibleIds);
    }

    /**
     * Применяет scope к запросу для фильтрации пользователей по доступным дилерствам.
     * Учитывает как основное дилерство, так и прикреплённые.
     */
    public function scopeUsersByAccessibleDealerships(Builder $query, User $user): Builder
    {
        if ($this->isOwner($user)) {
            return $query;
        }

        $accessibleIds = $this->getUserDealershipIds($user);

        return $query->where(function ($q) use ($accessibleIds) {
            $q->whereIn('dealership_id', $accessibleIds)
                ->orWhereHas('dealerships', function ($subQ) use ($accessibleIds) {
                    $subQ->whereIn('auto_dealerships.id', $accessibleIds);
                });
        });
    }

    /**
     * Применяет scope к запросу задач с учётом назначений и создателя.
     */
    public function scopeTasksByAccessibleDealerships(Builder $query, User $user): Builder
    {
        if ($this->isOwner($user)) {
            return $query;
        }

        $accessibleIds = $this->getUserDealershipIds($user);

        return $query->where(function ($q) use ($accessibleIds, $user) {
            $q->whereIn('dealership_id', $accessibleIds)
                ->orWhereHas('assignments', function ($subQ) use ($user) {
                    $subQ->where('user_id', $user->id);
                })
                ->orWhere('creator_id', $user->id);
        });
    }
}
