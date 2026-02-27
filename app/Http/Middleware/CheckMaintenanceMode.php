<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Role;
use App\Services\SettingsService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckMaintenanceMode
{
    public function __construct(
        private readonly SettingsService $settingsService
    ) {
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Проверяем глобальную настройку maintenance_mode (только dealership_id = null)
        $maintenanceMode = (bool) $this->settingsService->get('maintenance_mode', null, false);

        if (!$maintenanceMode) {
            // Режим обслуживания выключен - пропускаем все запросы
            return $next($request);
        }

        // Режим обслуживания включен - проверяем пользователя
        // Сначала пытаемся аутентифицировать через Sanctum
        $user = $request->user('sanctum');

        // Если пользователь не найден или не является владельцем - блокируем
        if (!$user || $user->role !== Role::OWNER) {
            return response()->json([
                'success' => false,
                'message' => 'Система временно недоступна. Проводятся технические работы.',
                'error_type' => 'maintenance_mode',
            ], 503);
        }

        // Пользователь - владелец, пропускаем
        return $next($request);
    }
}
