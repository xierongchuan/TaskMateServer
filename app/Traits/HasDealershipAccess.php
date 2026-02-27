<?php

declare(strict_types=1);

namespace App\Traits;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

/**
 * Трейт для унификации проверки доступа к дилерствам.
 *
 * Устраняет дублирование кода проверки доступа в контроллерах.
 */
trait HasDealershipAccess
{
    /**
     * Проверяет, является ли пользователь владельцем (owner).
     */
    protected function isOwner(User $user): bool
    {
        return $user->role === Role::OWNER;
    }

    /**
     * Получает список ID дилерств, доступных пользователю.
     */
    protected function getAccessibleDealershipIds(User $user): array
    {
        return $user->getAccessibleDealershipIds();
    }

    /**
     * Проверяет, имеет ли пользователь доступ к указанному дилерству.
     */
    protected function hasAccessToDealership(User $user, int $dealershipId): bool
    {
        if ($this->isOwner($user)) {
            return true;
        }

        return in_array($dealershipId, $this->getAccessibleDealershipIds($user), true);
    }

    /**
     * Проверяет доступ к дилерству и возвращает ошибку если доступа нет.
     *
     * @return JsonResponse|null Возвращает JsonResponse с ошибкой или null если доступ есть
     */
    protected function validateDealershipAccess(User $user, ?int $dealershipId): ?JsonResponse
    {
        if ($dealershipId === null || $this->isOwner($user)) {
            return null;
        }

        if (!$this->hasAccessToDealership($user, $dealershipId)) {
            return response()->json([
                'success' => false,
                'message' => 'Нет доступа к указанному дилерству',
            ], 403);
        }

        return null;
    }

    /**
     * Проверяет доступ к нескольким дилерствам.
     *
     * @param int[] $dealershipIds
     * @return JsonResponse|null Возвращает JsonResponse с ошибкой или null если доступ есть
     */
    protected function validateMultipleDealershipsAccess(User $user, array $dealershipIds): ?JsonResponse
    {
        if (empty($dealershipIds) || $this->isOwner($user)) {
            return null;
        }

        $accessibleIds = $this->getAccessibleDealershipIds($user);
        $inaccessible = array_diff($dealershipIds, $accessibleIds);

        if (!empty($inaccessible)) {
            return response()->json([
                'success' => false,
                'message' => 'Нет доступа к одному или нескольким дилерствам',
            ], 403);
        }

        return null;
    }

    /**
     * Проверяет, имеет ли текущий пользователь доступ к целевому пользователю
     * через общие дилерства.
     */
    protected function hasAccessToUser(User $currentUser, User $targetUser): bool
    {
        if ($this->isOwner($currentUser)) {
            return true;
        }

        $accessibleIds = $this->getAccessibleDealershipIds($currentUser);
        $targetDealershipIds = $this->getUserDealershipIds($targetUser);

        // Если у целевого пользователя нет дилерств - доступ есть (orphan user)
        if (empty($targetDealershipIds)) {
            return true;
        }

        return !empty(array_intersect($targetDealershipIds, $accessibleIds));
    }

    /**
     * Получает все ID дилерств пользователя (основное + прикреплённые).
     */
    protected function getUserDealershipIds(User $user): array
    {
        $ids = [];

        if ($user->dealership_id) {
            $ids[] = $user->dealership_id;
        }

        $attachedIds = $user->dealerships->pluck('id')->toArray();

        return array_unique(array_merge($ids, $attachedIds));
    }

    /**
     * Проверяет доступ к целевому пользователю и возвращает ошибку если доступа нет.
     *
     * @return JsonResponse|null Возвращает JsonResponse с ошибкой или null если доступ есть
     */
    protected function validateUserAccess(User $currentUser, User $targetUser): ?JsonResponse
    {
        if (!$this->hasAccessToUser($currentUser, $targetUser)) {
            return response()->json([
                'success' => false,
                'message' => 'Нет доступа к данному пользователю',
            ], 403);
        }

        return null;
    }

    /**
     * Применяет scope к запросу для фильтрации по доступным дилерствам.
     *
     * @param Builder $query
     * @param User $user
     * @param string $dealershipColumn Название колонки с ID дилерства
     */
    protected function scopeByAccessibleDealerships(
        Builder $query,
        User $user,
        string $dealershipColumn = 'dealership_id'
    ): Builder {
        if ($this->isOwner($user)) {
            return $query;
        }

        $accessibleIds = $this->getAccessibleDealershipIds($user);

        return $query->whereIn($dealershipColumn, $accessibleIds);
    }

    /**
     * Применяет scope к запросу для фильтрации пользователей по доступным дилерствам.
     * Учитывает как основное дилерство, так и прикреплённые.
     */
    protected function scopeUsersByAccessibleDealerships(Builder $query, User $user): Builder
    {
        if ($this->isOwner($user)) {
            return $query;
        }

        $accessibleIds = $this->getAccessibleDealershipIds($user);

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
    protected function scopeTasksByAccessibleDealerships(Builder $query, User $user): Builder
    {
        if ($this->isOwner($user)) {
            return $query;
        }

        $accessibleIds = $this->getAccessibleDealershipIds($user);

        return $query->where(function ($q) use ($accessibleIds, $user) {
            $q->whereIn('dealership_id', $accessibleIds)
                ->orWhereHas('assignments', function ($subQ) use ($user) {
                    $subQ->where('user_id', $user->id);
                })
                ->orWhere('creator_id', $user->id);
        });
    }
}
