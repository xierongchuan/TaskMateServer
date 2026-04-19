<?php

declare(strict_types=1);

namespace App\StateMachines;

use App\Enums\Role;
use App\Exceptions\InvalidStatusTransitionException;
use App\Models\User;

/**
 * Декларативная state machine для статусов TaskResponse.
 *
 * Инкапсулирует матрицу допустимых переходов статусов,
 * исключая hardcoded switch/match из сервисного слоя.
 *
 * Матрица переходов (базовая, для сотрудников):
 * - pending        → acknowledged, pending_review, completed
 * - acknowledged   → pending_review, completed
 * - pending_review → (пусто — только через approve/reject верификации)
 * - rejected       → pending_review, completed (повторная отправка)
 * - completed      → (финальный статус)
 *
 * Дополнительные переходы для менеджеров и владельцев:
 * - любой статус   → pending (сброс задачи)
 * - acknowledged   → pending
 * - pending_review → pending, completed (без верификации)
 */
class TaskStatusMachine
{
    /**
     * Базовая матрица допустимых переходов для всех ролей.
     *
     * Ключ — текущий статус (null означает отсутствие response — первый переход).
     * Значение — список разрешённых целевых статусов.
     *
     * @var array<string|null, list<string>>
     */
    private const BASE_TRANSITIONS = [
        'pending' => ['acknowledged', 'pending_review', 'completed'],
        'acknowledged' => ['pending_review', 'completed'],
        'pending_review' => [],
        'rejected' => ['pending_review', 'completed'],
        'completed' => [],
    ];

    /**
     * Дополнительные переходы, доступные менеджерам и владельцам.
     *
     * Расширяют базовую матрицу для привилегированных ролей.
     *
     * @var array<string, list<string>>
     */
    private const MANAGER_EXTRA_TRANSITIONS = [
        'acknowledged' => ['pending'],
        'pending_review' => ['pending', 'completed', 'pending_review'],
    ];

    /**
     * Проверяет, допустим ли переход из одного статуса в другой для данного пользователя.
     *
     * Если $from равен null (нет существующего response), переход всегда разрешён
     * — это первичная установка статуса.
     *
     * @param  string|null  $from  Текущий статус (null — response ещё не существует)
     * @param  string  $to  Целевой статус
     * @param  User  $user  Пользователь, инициирующий переход
     */
    public function canTransition(?string $from, string $to, User $user): bool
    {
        // Первичная установка статуса — response ещё не создан
        if ($from === null) {
            return true;
        }

        $isPrivileged = in_array($user->role, [Role::MANAGER, Role::OWNER], true);

        // Менеджеры и владельцы могут сбросить любой статус в pending
        if ($to === 'pending' && $isPrivileged) {
            return true;
        }

        $allowed = $this->getAllowedTransitions($from, $user);

        return in_array($to, $allowed, true);
    }

    /**
     * Валидирует переход и выбрасывает исключение при недопустимом переходе.
     *
     * @param  string|null  $from  Текущий статус
     * @param  string  $to  Целевой статус
     * @param  User  $user  Пользователь, инициирующий переход
     *
     * @throws InvalidStatusTransitionException При недопустимом переходе
     */
    public function validateTransition(?string $from, string $to, User $user): void
    {
        if (! $this->canTransition($from, $to, $user)) {
            throw new InvalidStatusTransitionException($from, $to);
        }
    }

    /**
     * Возвращает список допустимых целевых статусов из текущего для данного пользователя.
     *
     * @param  string|null  $from  Текущий статус (null — нет response)
     * @param  User  $user  Пользователь для учёта роли
     * @return list<string>
     */
    public function getAllowedTransitions(?string $from, User $user): array
    {
        if ($from === null) {
            return array_keys(self::BASE_TRANSITIONS);
        }

        $base = self::BASE_TRANSITIONS[$from] ?? [];

        $isPrivileged = in_array($user->role, [Role::MANAGER, Role::OWNER], true);

        if (! $isPrivileged) {
            return $base;
        }

        // Менеджеры: базовые переходы + pending (сброс) + дополнительные для данного статуса
        $extra = self::MANAGER_EXTRA_TRANSITIONS[$from] ?? [];

        return array_values(array_unique([...$base, ...$extra, 'pending']));
    }
}
