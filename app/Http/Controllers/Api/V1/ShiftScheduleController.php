<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ShiftScheduleResource;
use App\Models\ShiftSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ShiftScheduleController extends Controller
{
    /**
     * GET /api/v1/shift-schedules
     */
    public function index(Request $request): JsonResponse
    {
        $query = ShiftSchedule::query()->orderBy('sort_order');

        if ($request->filled('dealership_id')) {
            $query->where('dealership_id', (int) $request->query('dealership_id'));
        }

        if ($request->query('active_only') === 'true') {
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
    public function show(int $id): JsonResponse
    {
        $schedule = ShiftSchedule::findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => new ShiftScheduleResource($schedule),
        ]);
    }

    /**
     * POST /api/v1/shift-schedules
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'dealership_id' => ['required', 'integer', 'exists:auto_dealerships,id'],
            'name' => ['required', 'string', 'max:255'],
            'start_time' => ['required', 'string', 'regex:/^([0-1][0-9]|2[0-3]):[0-5][0-9]$/'],
            'end_time' => ['required', 'string', 'regex:/^([0-1][0-9]|2[0-3]):[0-5][0-9]$/'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        // Проверка уникальности имени в рамках автосалона
        $exists = ShiftSchedule::where('dealership_id', $data['dealership_id'])
            ->where('name', $data['name'])
            ->exists();

        if ($exists) {
            return response()->json([
                'success' => false,
                'message' => 'Смена с таким названием уже существует в этом автосалоне',
            ], 422);
        }

        // Проверка пересечения интервалов
        $newSchedule = new ShiftSchedule($data);
        $overlapping = $this->findOverlapping($newSchedule);

        if ($overlapping) {
            return response()->json([
                'success' => false,
                'message' => "Интервал пересекается со сменой \"{$overlapping->name}\" ({$overlapping->start_time}-{$overlapping->end_time})",
            ], 422);
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
    public function update(Request $request, int $id): JsonResponse
    {
        $schedule = ShiftSchedule::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => ['nullable', 'string', 'max:255'],
            'start_time' => ['nullable', 'string', 'regex:/^([0-1][0-9]|2[0-3]):[0-5][0-9]$/'],
            'end_time' => ['nullable', 'string', 'regex:/^([0-1][0-9]|2[0-3]):[0-5][0-9]$/'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

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

        // Проверка пересечения интервалов (если меняются времена)
        if (isset($data['start_time']) || isset($data['end_time'])) {
            $testSchedule = $schedule->replicate();
            $testSchedule->fill($data);
            $testSchedule->id = $schedule->id;

            $overlapping = $this->findOverlapping($testSchedule);
            if ($overlapping) {
                return response()->json([
                    'success' => false,
                    'message' => "Интервал пересекается со сменой \"{$overlapping->name}\" ({$overlapping->start_time}-{$overlapping->end_time})",
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
    public function destroy(int $id): JsonResponse
    {
        $schedule = ShiftSchedule::findOrFail($id);

        // Не разрешаем удалить единственную смену автосалона
        $totalCount = ShiftSchedule::where('dealership_id', $schedule->dealership_id)->count();

        if ($totalCount <= 1) {
            return response()->json([
                'success' => false,
                'message' => 'Нельзя удалить единственную смену автосалона',
            ], 422);
        }

        $schedule->delete();

        return response()->json([
            'success' => true,
            'message' => 'Смена удалена',
        ]);
    }

    /**
     * Найти активную смену, которая пересекается с данной.
     */
    private function findOverlapping(ShiftSchedule $schedule): ?ShiftSchedule
    {
        $others = ShiftSchedule::where('dealership_id', $schedule->dealership_id)
            ->where('is_active', true)
            ->when($schedule->id, fn ($q) => $q->where('id', '!=', $schedule->id))
            ->get();

        foreach ($others as $other) {
            if ($schedule->overlaps($other)) {
                return $other;
            }
        }

        return null;
    }
}
