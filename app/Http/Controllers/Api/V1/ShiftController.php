<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\ShiftStatus;
use App\Exceptions\ScheduleAmbiguousException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreShiftRequest;
use App\Http\Requests\Api\V1\UpdateShiftRequest;
use App\Http\Resources\ShiftResource;
use App\Http\Resources\ShiftScheduleResource;
use App\Models\Shift;
use App\Models\User;
use App\Services\ShiftService;
use App\Traits\HasDealershipAccess;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ShiftController extends Controller
{
    use HasDealershipAccess;

    public function __construct(
        private readonly ShiftService $shiftService
    ) {}

    /**
     * Get list of shifts with filtering and pagination
     *
     * GET /api/v1/shifts
     */
    public function index(Request $request): JsonResponse
    {
        $currentUser = $request->user();
        if (! $currentUser) {
            return response()->json(['message' => 'Не авторизован'], 401);
        }

        $perPage = min((int) $request->query('per_page', '15'), 100);
        $dealershipId = $this->parseDealershipId($request);
        $status = $request->query('status');
        $isLate = $request->query('is_late');
        $date = $request->query('date');
        $userId = $request->query('user_id') !== null && $request->query('user_id') !== '' ? (int) $request->query('user_id') : null;

        // Проверка доступа к конкретному дилерству
        if ($dealershipId !== null) {
            $accessError = $this->validateDealershipAccess($currentUser, $dealershipId);
            if ($accessError) {
                return $accessError;
            }
        }

        $query = Shift::with(['user', 'dealership', 'schedule']);

        // Ограничение выборки доступными дилерствами
        $this->scopeByAccessibleDealerships($query, $currentUser);

        if ($dealershipId) {
            $query->where('dealership_id', $dealershipId);
        }

        if ($userId) {
            $query->where('user_id', $userId);
        }

        if ($status) {
            $query->where('status', $status);
        }

        if ($isLate !== null && $isLate !== '') {
            $isLateValue = filter_var($isLate, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($isLateValue !== null) {
                if ($isLateValue) {
                    // Опоздание: статус 'late' ИЛИ late_minutes > 0
                    $query->where(function ($q) {
                        $q->where('status', ShiftStatus::LATE->value)
                            ->orWhere('late_minutes', '>', 0);
                    });
                } else {
                    // Без опоздания: статус НЕ 'late' И late_minutes <= 0
                    $query->where('status', '!=', ShiftStatus::LATE->value)
                        ->where(function ($q) {
                            $q->where('late_minutes', '<=', 0)
                                ->orWhereNull('late_minutes');
                        });
                }
            }
        }

        if ($date) {
            $startOfDay = Carbon::parse($date)->startOfDay();
            $endOfDay = Carbon::parse($date)->endOfDay();
            $query->whereBetween('shift_start', [$startOfDay, $endOfDay]);
        }

        $shifts = $query->orderByDesc('shift_start')->paginate($perPage);

        return ShiftResource::collection($shifts)->response();
    }

    /**
     * Create a new shift
     *
     * POST /api/v1/shifts
     */
    public function store(StoreShiftRequest $request): JsonResponse
    {
        $currentUser = $request->user();
        if (! $currentUser) {
            return response()->json(['message' => 'Не авторизован'], 401);
        }

        $data = $request->validated();

        try {
            $user = User::findOrFail($data['user_id']);

            // Проверка прав через ShiftPolicy
            $this->authorize('createForOther', [Shift::class, $user]);

            // Validate user belongs to the specified dealership
            if (! $this->shiftService->validateUserDealership($user, (int) $data['dealership_id'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Пользователь не принадлежит указанному автосалону',
                ], 403);
            }

            $shift = $this->shiftService->openShift(
                $user,
                $data['opening_photo'],
                null,
                null,
                (int) $data['dealership_id'],
                isset($data['shift_schedule_id']) ? (int) $data['shift_schedule_id'] : null
            );

            $shift->load(['user', 'dealership']);

            return response()->json([
                'success' => true,
                'message' => 'Смена успешно открыта',
                'data' => new ShiftResource($shift),
            ], 201);

        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            throw $e;
        } catch (ScheduleAmbiguousException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => 'schedule_ambiguous',
                'candidates' => ShiftScheduleResource::collection($e->getCandidates())->resolve(),
            ], 409);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => config('app.debug') ? $e->getMessage() : 'Внутренняя ошибка сервера',
            ], 400);
        } catch (\Exception $e) {
            Log::error('Failed to open shift', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => config('app.debug') ? $e->getMessage() : 'Внутренняя ошибка сервера',
            ], 500);
        }
    }

    /**
     * Get a specific shift
     *
     * GET /api/v1/shifts/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $currentUser = $request->user();
        if (! $currentUser) {
            return response()->json(['message' => 'Не авторизован'], 401);
        }

        $shift = Shift::with(['user', 'dealership', 'schedule'])->findOrFail($id);

        // Проверка доступа к дилерству смены
        $accessError = $this->validateDealershipAccess($currentUser, $shift->dealership_id);
        if ($accessError) {
            return $accessError;
        }

        return response()->json([
            'success' => true,
            'data' => new ShiftResource($shift),
        ]);
    }

    /**
     * Update a shift (primarily for closing)
     *
     * PUT /api/v1/shifts/{id}
     */
    public function update(UpdateShiftRequest $request, int $id): JsonResponse
    {
        $shift = Shift::findOrFail($id);
        $currentUser = $request->user();

        if (! $currentUser) {
            return response()->json(['message' => 'Не авторизован'], 401);
        }

        // Проверка доступа к дилерству смены
        $accessError = $this->validateDealershipAccess($currentUser, $shift->dealership_id);
        if ($accessError) {
            return $accessError;
        }

        // Проверка прав через ShiftPolicy
        $this->authorize('update', $shift);

        $data = $request->validated();

        try {
            // If closing photo is provided, close the shift
            if (isset($data['closing_photo'])) {
                $updatedShift = $this->shiftService->closeShift($shift, $data['closing_photo']);
                $updatedShift->load(['user', 'dealership']);

                return response()->json([
                    'success' => true,
                    'message' => 'Смена успешно закрыта',
                    'data' => new ShiftResource($updatedShift),
                ]);
            }

            // If only status is being updated
            if (isset($data['status'])) {
                if ($data['status'] === ShiftStatus::CLOSED->value) {
                    $this->shiftService->closeShiftWithoutPhoto($shift, ShiftStatus::CLOSED->value);
                } else {
                    $shift->update(['status' => $data['status']]);
                }

                $shift->load(['user', 'dealership']);

                return response()->json([
                    'success' => true,
                    'message' => 'Смена успешно обновлена',
                    'data' => new ShiftResource($shift),
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Нет допустимых полей для обновления',
            ], 400);

        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => config('app.debug') ? $e->getMessage() : 'Внутренняя ошибка сервера',
            ], 400);
        } catch (\Exception $e) {
            Log::error('Shift update failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => config('app.debug') ? $e->getMessage() : 'Внутренняя ошибка сервера',
            ], 500);
        }
    }

    /**
     * Delete a shift
     *
     * DELETE /api/v1/shifts/{id}
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $currentUser = $request->user();
        if (! $currentUser) {
            return response()->json(['message' => 'Не авторизован'], 401);
        }

        $shift = Shift::findOrFail($id);

        // Проверка доступа к дилерству смены
        $accessError = $this->validateDealershipAccess($currentUser, $shift->dealership_id);
        if ($accessError) {
            return $accessError;
        }

        // Проверка прав через ShiftPolicy
        $this->authorize('delete', $shift);

        try {
            // Нельзя удалить активную смену
            if ($shift->status === ShiftStatus::OPEN->value && ! $shift->shift_end) {
                return response()->json([
                    'success' => false,
                    'message' => 'Невозможно удалить активную смену',
                ], 400);
            }

            $shift->delete();

            return response()->json([
                'success' => true,
                'message' => 'Смена успешно удалена',
            ]);

        } catch (\Exception $e) {
            Log::error('Shift deletion failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => config('app.debug') ? $e->getMessage() : 'Внутренняя ошибка сервера',
            ], 500);
        }
    }

    /**
     * Get current open shifts
     *
     * GET /api/v1/shifts/current
     */
    public function current(Request $request): JsonResponse
    {
        $dealershipId = $this->parseDealershipId($request);
        $currentShifts = $this->shiftService->getCurrentShifts($dealershipId);

        return response()->json([
            'success' => true,
            'data' => ShiftResource::collection($currentShifts),
        ]);
    }

    /**
     * Get shift statistics
     *
     * GET /api/v1/shifts/statistics
     */
    public function statistics(Request $request): JsonResponse
    {
        $dealershipId = $this->parseDealershipId($request);
        $startDate = $request->query('start_date')
            ? Carbon::parse($request->query('start_date'))
            : Carbon::now()->subDays(7);
        $endDate = $request->query('end_date')
            ? Carbon::parse($request->query('end_date'))
            : Carbon::now();

        $statistics = $this->shiftService->getShiftStatistics($dealershipId, $startDate, $endDate);

        return response()->json([
            'success' => true,
            'data' => $statistics,
        ]);
    }

    /**
     * Get shifts for the authenticated user
     *
     * GET /api/v1/shifts/my
     */
    public function myShifts(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Не авторизован',
            ], 401);
        }

        $filters = [
            'status' => $request->query('status'),
            'date_from' => $request->query('date_from')
                ? Carbon::parse($request->query('date_from'))
                : null,
            'date_to' => $request->query('date_to')
                ? Carbon::parse($request->query('date_to'))
                : null,
        ];

        $shifts = $this->shiftService->getUserShifts($user, $filters);

        return response()->json([
            'success' => true,
            'data' => ShiftResource::collection($shifts),
        ]);
    }

    /**
     * Get shift schedules available for opening right now
     *
     * GET /api/v1/shifts/available-schedules?dealership_id=...
     */
    public function availableSchedules(Request $request): JsonResponse
    {
        $currentUser = $request->user();
        if (! $currentUser) {
            return response()->json(['message' => 'Не авторизован'], 401);
        }

        $dealershipId = $request->query('dealership_id');
        if (! $dealershipId) {
            return response()->json([
                'success' => false,
                'message' => 'Параметр dealership_id обязателен',
            ], 422);
        }

        $dealershipId = (int) $dealershipId;

        $accessError = $this->validateDealershipAccess($currentUser, $dealershipId);
        if ($accessError) {
            return $accessError;
        }

        try {
            $candidates = $this->shiftService->getAvailableSchedulesForNow($dealershipId);

            return response()->json([
                'success' => true,
                'data' => ShiftScheduleResource::collection($candidates),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => [],
            ]);
        }
    }

    /**
     * Get current user's open shift
     *
     * GET /api/v1/shifts/my/current
     */
    public function myCurrentShift(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Не авторизован',
            ], 401);
        }

        $dealershipId = $this->parseDealershipId($request);
        $shift = $this->shiftService->getUserOpenShift($user, $dealershipId);

        if (! $shift) {
            return response()->json([
                'success' => true,
                'data' => null,
                'message' => 'Активная смена не найдена',
            ]);
        }

        $shift->load(['dealership']);

        return response()->json([
            'success' => true,
            'data' => new ShiftResource($shift),
        ]);
    }
}
