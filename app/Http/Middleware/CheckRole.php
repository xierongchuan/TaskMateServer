<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Role;
use Closure;
use Illuminate\Http\Request;

/**
 * Middleware для проверки роли пользователя
 *
 * Иерархия ролей (от высшей к низшей):
 * 1. owner - владелец (полный доступ ко всему)
 * 2. manager - управляющий (управление задачами, сменами, сотрудниками)
 * 3. observer - наблюдатель (только чтение)
 * 4. employee - сотрудник (базовые операции)
 *
 * Использование:
 * Route::get('/admin', ...)->middleware('role:owner');
 * Route::get('/manage', ...)->middleware('role:manager');
 * Route::get('/view', ...)->middleware('role:observer,manager,owner');
 */
class CheckRole
{
    /**
     * Иерархия ролей (от низшей к высшей)
     */
    private const ROLE_HIERARCHY = [
        'employee' => 1,
        'observer' => 2,
        'manager' => 3,
        'owner' => 4,
    ];

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  string  ...$roles Разрешенные роли (можно несколько через запятую)
     * @return mixed
     */
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        if (!$request->user()) {
            return response()->json([
                'message' => 'Не авторизован',
            ], 401);
        }

        /** @var \App\Models\User $user */
        $user = $request->user();
        $userRole = $user->role;

        // Если роль - Enum, получаем значение
        if ($userRole instanceof Role) {
            $userRole = $userRole->value;
        }

        // Проверяем, что у пользователя есть одна из разрешенных ролей
        if (!in_array($userRole, $roles)) {
            return response()->json([
                'message' => 'Недостаточно прав для выполнения этого действия',
                'required_roles' => $roles,
                'your_role' => $userRole,
            ], 403);
        }

        return $next($request);
    }

    /**
     * Проверяет, имеет ли роль более высокий или равный уровень доступа
     *
     * @param string $userRole Роль пользователя
     * @param string $requiredRole Требуемая роль
     * @return bool
     */
    public static function hasRoleOrHigher(string $userRole, string $requiredRole): bool
    {
        $userLevel = self::ROLE_HIERARCHY[$userRole] ?? 0;
        $requiredLevel = self::ROLE_HIERARCHY[$requiredRole] ?? 999;

        return $userLevel >= $requiredLevel;
    }
}
