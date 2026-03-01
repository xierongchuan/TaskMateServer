<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreDealershipRequest;
use App\Http\Requests\Api\V1\UpdateDealershipRequest;
use App\Http\Resources\DealershipResource;
use App\Models\AutoDealership;
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
    use HasDealershipAccess;

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

        return response()->json($dealerships);
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
            ->find($id);

        if (! $dealership) {
            return response()->json([
                'message' => 'Автосалон не найден',
            ], 404);
        }

        // Проверка доступа к дилерству via Policy
        $this->authorize('view', $dealership);

        return response()->json(DealershipResource::make($dealership)->resolve());
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

        return response()->json(DealershipResource::make($dealership)->resolve(), 201);
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
        $dealership = AutoDealership::find($id);

        if (! $dealership) {
            return response()->json([
                'message' => 'Автосалон не найден',
            ], 404);
        }

        $validated = $request->validated();

        $dealership->update($validated);

        return response()->json(DealershipResource::make($dealership)->resolve());
    }

    /**
     * Удаляет автосалон.
     *
     * @param  int|string  $id  ID автосалона
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $dealership = AutoDealership::find($id);

        if (! $dealership) {
            return response()->json([
                'message' => 'Автосалон не найден',
            ], 404);
        }

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
            return response()->json([
                'message' => 'Невозможно удалить автосалон с связанными данными',
                'related_data' => $relatedData,
                'errors' => [
                    'dealership' => ['Автосалон имеет связанные записи: '.implode(', ', array_keys($relatedData))],
                ],
            ], 422);
        }

        try {
            $dealership->delete();

            return response()->json([
                'success' => true,
                'message' => 'Автосалон успешно удален',
            ], 200);
        } catch (\Exception $e) {
            Log::error('Dealership deletion failed', [
                'dealership_id' => $dealership->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка при удалении автосалона',
                'error' => config('app.debug') ? $e->getMessage() : 'Внутренняя ошибка сервера',
            ], 500);
        }
    }
}
