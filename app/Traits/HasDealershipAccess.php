<?php

declare(strict_types=1);

namespace App\Traits;

use App\Models\User;
use App\Services\DealershipAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Трейт для унификации проверки доступа к дилерствам в контроллерах.
 *
 * Предоставляет HTTP-уровень обёртки над DealershipAccessService:
 * методы validate* возвращают JsonResponse|null для удобного использования
 * в контроллерах. Бизнес-логика сосредоточена в DealershipAccessService.
 *
 * Используйте DealershipAccessService напрямую в сервисном слое.
 */
trait HasDealershipAccess
{
    /**
     * Возвращает экземпляр DealershipAccessService через IoC-контейнер.
     */
    private function dealershipAccessService(): DealershipAccessService
    {
        return app(DealershipAccessService::class);
    }

    /**
     * Разбирает dealership_id из query-параметров запроса.
     *
     * Заменяет повторяющийся паттерн:
     * $request->query('dealership_id') !== null && $request->query('dealership_id') !== ''
     *     ? (int) $request->query('dealership_id')
     *     : null
     */
    protected function parseDealershipId(Request $request): ?int
    {
        return $request->filled('dealership_id') ? $request->integer('dealership_id') : null;
    }

    /**
     * Проверяет, является ли пользователь владельцем (owner).
     */
    protected function isOwner(User $user): bool
    {
        return $this->dealershipAccessService()->isOwner($user);
    }

    /**
     * Получает список ID дилерств, доступных пользователю.
     *
     * @return array<int>
     */
    protected function getAccessibleDealershipIds(User $user): array
    {
        return $this->dealershipAccessService()->getUserDealershipIds($user);
    }

    /**
     * Проверяет, имеет ли пользователь доступ к указанному дилерству.
     */
    protected function hasAccessToDealership(User $user, int $dealershipId): bool
    {
        return $this->dealershipAccessService()->hasAccessToDealership($user, $dealershipId);
    }

    /**
     * Проверяет доступ к дилерству и возвращает ошибку если доступа нет.
     *
     * @return JsonResponse|null Возвращает JsonResponse с ошибкой или null если доступ есть
     */
    protected function validateDealershipAccess(User $user, ?int $dealershipId): ?JsonResponse
    {
        if ($dealershipId === null || $this->dealershipAccessService()->isOwner($user)) {
            return null;
        }

        if (! $this->dealershipAccessService()->hasAccessToDealership($user, $dealershipId)) {
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
     * @param  int[]  $dealershipIds
     * @return JsonResponse|null Возвращает JsonResponse с ошибкой или null если доступ есть
     */
    protected function validateMultipleDealershipsAccess(User $user, array $dealershipIds): ?JsonResponse
    {
        if (empty($dealershipIds) || $this->dealershipAccessService()->isOwner($user)) {
            return null;
        }

        $accessibleIds = $this->dealershipAccessService()->getUserDealershipIds($user);
        $inaccessible = array_diff($dealershipIds, $accessibleIds);

        if (! empty($inaccessible)) {
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
        return $this->dealershipAccessService()->hasAccessToUser($currentUser, $targetUser);
    }

    /**
     * Получает все ID дилерств пользователя (основное + прикреплённые).
     *
     * @return array<int>
     */
    protected function getUserDealershipIds(User $user): array
    {
        return $this->dealershipAccessService()->getAllUserDealershipIds($user);
    }

    /**
     * Проверяет доступ к целевому пользователю и возвращает ошибку если доступа нет.
     *
     * @return JsonResponse|null Возвращает JsonResponse с ошибкой или null если доступ есть
     */
    protected function validateUserAccess(User $currentUser, User $targetUser): ?JsonResponse
    {
        if (! $this->dealershipAccessService()->hasAccessToUser($currentUser, $targetUser)) {
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
     * @param  string  $dealershipColumn  Название колонки с ID дилерства
     */
    protected function scopeByAccessibleDealerships(
        Builder $query,
        User $user,
        string $dealershipColumn = 'dealership_id'
    ): Builder {
        return $this->dealershipAccessService()->scopeByAccessibleDealerships($query, $user, $dealershipColumn);
    }

    /**
     * Применяет scope к запросу для фильтрации пользователей по доступным дилерствам.
     * Учитывает как основное дилерство, так и прикреплённые.
     */
    protected function scopeUsersByAccessibleDealerships(Builder $query, User $user): Builder
    {
        return $this->dealershipAccessService()->scopeUsersByAccessibleDealerships($query, $user);
    }

    /**
     * Применяет scope к запросу задач с учётом назначений и создателя.
     */
    protected function scopeTasksByAccessibleDealerships(Builder $query, User $user): Builder
    {
        return $this->dealershipAccessService()->scopeTasksByAccessibleDealerships($query, $user);
    }
}
