<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreDealershipRequest;
use App\Http\Requests\Api\V1\UpdateDealershipRequest;
use App\Http\Resources\DealershipResource;
use App\Models\AutoDealership;
use App\Traits\ApiResponses;
use App\Traits\HasDealershipAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Контроллер для управления автосалонами.
 *
 * Предоставляет CRUD операции для автосалонов.
 */
class DealershipController extends Controller
{
    use ApiResponses, HasDealershipAccess;

    /**
     * Получает список автосалонов с фильтрацией и пагинацией.
     *
     * @param  Request  $request  HTTP-запрос с параметрами фильтрации
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $perPage = min((int) $request->query('per_page', '15'), 100);
        $isActive = $request->query('is_active');
        $search = $request->query('search');

        $query = AutoDealership::query();

        if ($isActive !== null) {
            $query->where('is_active', (bool) $isActive);
        }

        // Search by name, address, description, and phone
        if ($search) {
            $escapedSearch = str_replace(['%', '_'], ['\%', '\_'], $search);
            $query->where(function ($q) use ($escapedSearch) {
                $q->where('name', 'ILIKE', "%{$escapedSearch}%")
                    ->orWhere('address', 'ILIKE', "%{$escapedSearch}%")
                    ->orWhere('description', 'ILIKE', "%{$escapedSearch}%")
                    ->orWhere('phone', 'ILIKE', "%{$escapedSearch}%");
            });
        }

        // Scope access for non-owners
        /** @var \App\Models\User $currentUser */
        $currentUser = $request->user();
        $this->scopeByAccessibleDealerships($query, $currentUser, 'id');

        $dealerships = $query->orderBy('name')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $dealerships->items(),
            'current_page' => $dealerships->currentPage(),
            'last_page' => $dealerships->lastPage(),
            'per_page' => $dealerships->perPage(),
            'total' => $dealerships->total(),
            'links' => [
                'first' => $dealerships->url(1),
                'last' => $dealerships->url($dealerships->lastPage()),
                'prev' => $dealerships->previousPageUrl(),
                'next' => $dealerships->nextPageUrl(),
            ],
        ]);
    }

    /**
     * Получает информацию о конкретном автосалоне.
     *
     * @param  Request  $request  HTTP-запрос
     * @param  int|string  $id  ID автосалона
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(Request $request, $id)
    {
        $dealership = AutoDealership::with(['users', 'shifts', 'tasks'])
            ->findOrFail($id);

        // Проверка доступа к дилерству via Policy
        $this->authorize('view', $dealership);

        return $this->successResponse(DealershipResource::make($dealership)->resolve());
    }

    /**
     * Создаёт новый автосалон.
     *
     * @param  Request  $request  HTTP-запрос с данными автосалона
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(StoreDealershipRequest $request)
    {
        $validated = $request->validated();
        Log::info('Dealership store request', ['dealership_name' => $validated['name'] ?? null]);

        $dealership = AutoDealership::create($validated);

        return $this->createdResponse(DealershipResource::make($dealership)->resolve(), 'Автосалон создан');
    }

    /**
     * Обновляет данные автосалона.
     *
     * @param  Request  $request  HTTP-запрос с данными для обновления
     * @param  int|string  $id  ID автосалона
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(UpdateDealershipRequest $request, $id)
    {
        $dealership = AutoDealership::findOrFail($id);

        $validated = $request->validated();

        $dealership->update($validated);

        return $this->successResponse(DealershipResource::make($dealership)->resolve());
    }

    /**
     * Удаляет автосалон.
     *
     * @param  int|string  $id  ID автосалона
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $dealership = AutoDealership::findOrFail($id);

        // Проверяем наличие связанных данных (одним запросом вместо 6)
        $dealership->loadCount(['users', 'shifts', 'tasks']);

        $relatedData = [];

        if ($dealership->users_count > 0) {
            $relatedData['users'] = $dealership->users_count;
        }

        if ($dealership->shifts_count > 0) {
            $relatedData['shifts'] = $dealership->shifts_count;
        }

        if ($dealership->tasks_count > 0) {
            $relatedData['tasks'] = $dealership->tasks_count;
        }

        if (! empty($relatedData)) {
            return $this->errorResponse(
                'Невозможно удалить автосалон с связанными данными',
                422,
                ['dealership' => ['Автосалон имеет связанные записи: '.implode(', ', array_keys($relatedData))]]
            );
        }

        try {
            $dealership->delete();

            return $this->deletedResponse('Автосалон успешно удален');
        } catch (\Exception $e) {
            Log::error('Dealership deletion failed', [
                'dealership_id' => $dealership->id,
                'error' => $e->getMessage(),
            ]);

            return $this->serverErrorResponse('Ошибка при удалении автосалона', $e);
        }
    }
}
