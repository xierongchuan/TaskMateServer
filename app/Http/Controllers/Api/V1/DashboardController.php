<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Контроллер дашборда для менеджеров.
 *
 * Предоставляет агрегированные данные о задачах, сменах и пользователях.
 */
class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardService $dashboardService
    ) {}

    /**
     * Получает данные дашборда.
     *
     * @param Request $request HTTP-запрос
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $dealershipId = $request->filled('dealership_id')
            ? $request->integer('dealership_id')
            : null;

        $data = $this->dashboardService->getDashboardData($dealershipId);

        return response()->json($data);
    }
}
