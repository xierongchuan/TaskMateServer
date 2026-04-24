<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreShiftScheduleRequest;
use App\Http\Requests\Api\V1\UpdateShiftScheduleRequest;
use App\Http\Resources\ShiftScheduleResource;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Traits\HasDealershipAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShiftScheduleController extends Controller
{
    use HasDealershipAccess;

    /**
     * GET /api/v1/shift-schedules
     */
    public function index(Request $request): JsonResponse
    {
        $currentUser = $request->user();
        if (! $currentUser) {
            return response()->json(['message' => 'Не авторизован'], 401);
        }

        $query = ShiftSchedule::query()->orderBy('sort_order');
        $dealershipId = $this->parseDealershipId($request);

        if ($dealershipId !== null) {
            $accessError = $this->validateDealershipAccess($currentUser, $dealershipId);
            if ($accessError) {
                return $accessError;
            }

            $query->where('dealership_id', $dealershipId);
        }

        $deletedOnly = $request->boolean('deleted_only');

        if ($deletedOnly) {
            $query->onlyTrashed()->orderByDesc('deleted_at');
        } elseif ($request->query('active_only') === 'true') {
            $query->where('is_active', true);
        }

        $schedules = $query->get();

        return response()->json([
            'success' => true,
            'data' => ShiftScheduleResource::collection($schedules),
        ]);
    }

    /**
     * GET /api/v1/shift-schedules/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $currentUser = $request->user();
        if (! $currentUser) {
            return response()->json(['message' => 'Не авторизован'], 401);
        }

        $schedule = ShiftSchedule::findOrFail($id);

        // Проверка доступа к дилерству расписания
        $accessError = $this->validateDealershipAccess($currentUser, $schedule->dealership_id);
        if ($accessError) {
            return $accessError;
        }

        return response()->json([
            'success' => true,
            'data' => new ShiftScheduleResource($schedule),
        ]);
    }

    /**
     * POST /api/v1/shift-schedules
     */
    public function store(StoreShiftScheduleRequest $request): JsonResponse
    {
        $currentUser = $request->user();
        if (! $currentUser) {
            return response()->json(['message' => 'Не авторизован'], 401);
        }

        $data = $request->validated();
        $accessError = $this->validateDealershipAccess($currentUser, (int) $data['dealership_id']);
        if ($accessError) {
            return $accessError;
        }

        $existingSchedule = ShiftSchedule::withTrashed()
            ->where('dealership_id', $data['dealership_id'])
            ->where('name', $data['name'])
            ->first();

        if ($existingSchedule && ! $existingSchedule->trashed()) {
            return response()->json([
                'success' => false,
                'message' => 'Смена с таким названием уже существует в этом автосалоне',
            ], 422);
        }

        if ($existingSchedule && $existingSchedule->trashed()) {
            return response()->json([
                'success' => false,
                'message' => 'Расписание смены с таким названием уже есть в архиве',
                'error_code' => 'archived_duplicate',
                'archived_schedule' => new ShiftScheduleResource($existingSchedule),
            ], 409);
        }

        $schedule = ShiftSchedule::create($data);

        return response()->json([
            'success' => true,
            'message' => 'Расписание смены создано',
            'data' => new ShiftScheduleResource($schedule),
        ], 201);
    }

    /**
     * PUT /api/v1/shift-schedules/{id}
     */
    public function update(UpdateShiftScheduleRequest $request, int $id): JsonResponse
    {
        $currentUser = $request->user();
        if (! $currentUser) {
            return response()->json(['message' => 'Не авторизован'], 401);
        }

        $schedule = ShiftSchedule::findOrFail($id);

        // Проверка доступа к дилерству расписания
        $accessError = $this->validateDealershipAccess($currentUser, $schedule->dealership_id);
        if ($accessError) {
            return $accessError;
        }

        $data = $request->validated();

        // Проверка уникальности имени
        if (isset($data['name']) && $data['name'] !== $schedule->name) {
            $exists = ShiftSchedule::where('dealership_id', $schedule->dealership_id)
                ->where('name', $data['name'])
                ->where('id', '!=', $schedule->id)
                ->exists();

            if ($exists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Смена с таким названием уже существует в этом автосалоне',
                ], 422);
            }
        }

        // Не разрешаем деактивировать, если это единственная активная смена
        if (isset($data['is_active']) && $data['is_active'] === false) {
            $activeCount = ShiftSchedule::where('dealership_id', $schedule->dealership_id)
                ->where('is_active', true)
                ->where('id', '!=', $schedule->id)
                ->count();

            if ($activeCount === 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Нельзя деактивировать единственную активную смену',
                ], 422);
            }
        }

        $schedule->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Расписание смены обновлено',
            'data' => new ShiftScheduleResource($schedule),
        ]);
    }

    /**
     * DELETE /api/v1/shift-schedules/{id}
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $currentUser = $request->user();
        if (! $currentUser) {
            return response()->json(['message' => 'Не авторизован'], 401);
        }

        $schedule = ShiftSchedule::findOrFail($id);

        // Проверка доступа к дилерству расписания
        $accessError = $this->validateDealershipAccess($currentUser, $schedule->dealership_id);
        if ($accessError) {
            return $accessError;
        }

        // Не разрешаем удалить единственную смену автосалона
        $totalCount = ShiftSchedule::where('dealership_id', $schedule->dealership_id)->count();

        if ($totalCount <= 1) {
            return response()->json([
                'success' => false,
                'message' => 'Нельзя удалить единственную смену автосалона',
            ], 422);
        }

        // Не разрешаем удалить расписание с открытыми сменами
        $activeShiftsCount = Shift::where('shift_schedule_id', $schedule->id)
            ->whereIn('status', ['open', 'late'])
            ->count();

        if ($activeShiftsCount > 0) {
            return response()->json([
                'success' => false,
                'message' => "Нельзя удалить: есть {$activeShiftsCount} открытых смен",
            ], 422);
        }

        $schedule->delete();

        return response()->json([
            'success' => true,
            'message' => 'Смена удалена',
        ]);
    }

    /**
     * POST /api/v1/shift-schedules/{id}/restore
     */
    public function restore(Request $request, int $id): JsonResponse
    {
        $currentUser = $request->user();
        if (! $currentUser) {
            return response()->json(['message' => 'Не авторизован'], 401);
        }

        $schedule = ShiftSchedule::withTrashed()->findOrFail($id);

        if (! $schedule->trashed()) {
            return response()->json([
                'success' => false,
                'message' => 'Расписание смены уже активно',
            ], 422);
        }

        $accessError = $this->validateDealershipAccess($currentUser, $schedule->dealership_id);
        if ($accessError) {
            return $accessError;
        }

        $activeDuplicateExists = ShiftSchedule::query()
            ->where('dealership_id', $schedule->dealership_id)
            ->where('name', $schedule->name)
            ->where('id', '!=', $schedule->id)
            ->exists();

        if ($activeDuplicateExists) {
            return response()->json([
                'success' => false,
                'message' => 'Нельзя восстановить расписание: активная смена с таким названием уже существует',
                'error_code' => 'active_duplicate',
            ], 409);
        }

        $schedule->restore();

        return response()->json([
            'success' => true,
            'message' => 'Расписание смены восстановлено',
            'data' => new ShiftScheduleResource($schedule->fresh()),
        ]);
    }
}
