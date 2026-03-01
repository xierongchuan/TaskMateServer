<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use App\Traits\ApiResponses;
use App\Traits\HasDealershipAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Контроллер дашборда для менеджеров.
 *
 * Предоставляет агрегированные данные о задачах, сменах и пользователях.
 */
class DashboardController extends Controller
{
    use ApiResponses, HasDealershipAccess;

    public function __construct(
        private readonly DashboardService $dashboardService
    ) {}

    /**
     * Получает данные дашборда.
     *
     * @param  Request  $request  HTTP-запрос
     */
    public function index(Request $request): JsonResponse
    {
        $currentUser = $request->user();
        if (! $currentUser) {
            return $this->errorResponse('Не авторизован', 401);
        }

        $dealershipId = $request->filled('dealership_id')
            ? $request->integer('dealership_id')
            : null;

        // Проверка доступа к указанному дилерству
        if ($dealershipId !== null) {
            $accessError = $this->validateDealershipAccess($currentUser, $dealershipId);
            if ($accessError) {
                return $accessError;
            }
        }

        $data = $this->dashboardService->getDashboardData($dealershipId);

        return $this->successResponse($data);
    }
}
