<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * RESTful Settings management API
 */
class SettingsController extends Controller
{
    public function __construct(
        private readonly SettingsService $settingsService
    ) {
    }

    /**
     * Get all global settings
     *
     * GET /api/v1/settings
     */
    public function index(): JsonResponse
    {
        $settings = Setting::whereNull('dealership_id')->get();

        return response()->json([
            'success' => true,
            'data' => $settings->mapWithKeys(function ($setting) {
                return [$setting->key => $setting->getTypedValue()];
            }),
        ]);
    }

    /**
     * Get a specific global setting
     *
     * GET /api/v1/settings/{key}
     */
    public function show(string $key): JsonResponse
    {
        $value = $this->settingsService->get($key);

        return response()->json([
            'success' => true,
            'data' => [
                'key' => $key,
                'value' => $value,
                'scope' => 'global'
            ],
        ]);
    }

    /**
     * Update a specific global setting
     *
     * PUT /api/v1/settings/{key}
     */
    public function update(Request $request, string $key): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'value' => 'required',
            'type' => 'nullable|in:string,integer,boolean,json,time',
            'description' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $data = $validator->validated();

            $setting = $this->settingsService->set(
                $key,
                $data['value'],
                null, // Global setting
                $data['type'] ?? 'string',
                $data['description'] ?? null
            );

            return response()->json([
                'success' => true,
                'message' => 'Setting updated successfully',
                'data' => [
                    'key' => $key,
                    'value' => $setting->getTypedValue(),
                    'scope' => 'global'
                ],
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Get shift configuration
     *
     * GET /api/v1/settings/shift-config
     *
     * Возвращает late_tolerance_minutes и список расписаний смен из shift_schedules.
     */
    public function getShiftConfig(Request $request): JsonResponse
    {
        $dealershipId = $request->query('dealership_id') !== null && $request->query('dealership_id') !== '' ? (int) $request->query('dealership_id') : null;

        $schedules = [];
        if ($dealershipId) {
            $schedules = \App\Models\ShiftSchedule::where('dealership_id', $dealershipId)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get()
                ->map(fn ($s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'start_time' => $s->start_time,
                    'end_time' => $s->end_time,
                    'sort_order' => $s->sort_order,
                ])
                ->values();
        }

        $shiftConfig = [
            'late_tolerance_minutes' => $this->settingsService->getLateTolerance($dealershipId),
            'schedules' => $schedules,
        ];

        return response()->json([
            'success' => true,
            'data' => $shiftConfig,
        ]);
    }

    /**
     * Update shift configuration (only late_tolerance_minutes)
     *
     * POST /api/v1/settings/shift-config
     *
     * Расписания смен теперь управляются через /api/v1/shift-schedules.
     */
    public function updateShiftConfig(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'late_tolerance_minutes' => ['nullable', 'integer', 'min:0', 'max:120'],
            'dealership_id' => ['nullable', 'integer'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $data = $validator->validated();
            $dealershipId = $data['dealership_id'] ?? null;

            $updatedSettings = [];
            if (isset($data['late_tolerance_minutes'])) {
                $this->settingsService->set('late_tolerance_minutes', $data['late_tolerance_minutes'], $dealershipId, 'integer');
                $updatedSettings['late_tolerance_minutes'] = $data['late_tolerance_minutes'];
            }

            return response()->json([
                'success' => true,
                'message' => 'Shift configuration updated successfully',
                'data' => $updatedSettings,
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Get notification configuration
     *
     * GET /api/v1/settings/notification-config
     */
    public function getNotificationConfig(Request $request): JsonResponse
    {
        $dealershipId = $request->query('dealership_id') !== null && $request->query('dealership_id') !== '' ? (int) $request->query('dealership_id') : null;

        $notificationConfig = [
            'notification_enabled' => (bool) $this->settingsService->getSettingWithFallback('notification_enabled', $dealershipId, true),
            'auto_close_shifts' => (bool) $this->settingsService->getSettingWithFallback('auto_close_shifts', $dealershipId, false),
            'shift_reminder_minutes' => (int) $this->settingsService->getSettingWithFallback('shift_reminder_minutes', $dealershipId, 15),
            'rows_per_page' => (int) $this->settingsService->getSettingWithFallback('rows_per_page', $dealershipId, 10),
            'notification_types' => $this->settingsService->getSettingWithFallback('notification_types', $dealershipId, [
                'task_overdue' => true,
                'shift_late' => true,
                'task_completed' => true,
                'system_errors' => true,
            ]),
        ];

        return response()->json([
            'success' => true,
            'data' => $notificationConfig,
        ]);
    }

    /**
     * Update notification configuration
     *
     * PUT /api/v1/settings/notification-config
     */
    public function updateNotificationConfig(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'notification_enabled' => ['nullable', 'boolean'],
            'auto_close_shifts' => ['nullable', 'boolean'],
            'shift_reminder_minutes' => ['nullable', 'integer', 'min:1', 'max:60'],
            'rows_per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
            'notification_types' => ['nullable', 'array'],
            'dealership_id' => ['nullable', 'integer'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $data = $validator->validated();
            $dealershipId = $data['dealership_id'] ?? null;
            unset($data['dealership_id']);

            $updatedSettings = [];
            foreach ($data as $key => $value) {
                if ($value !== null) {
                    $type = 'string';
                    if (is_bool($value)) $type = 'boolean';
                    elseif (is_int($value)) $type = 'integer';
                    elseif (is_array($value)) $type = 'json';

                    $this->settingsService->set($key, $value, $dealershipId, $type);
                    $updatedSettings[$key] = $value;
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Notification configuration updated successfully',
                'data' => $updatedSettings,
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Get archive configuration
     *
     * GET /api/v1/settings/archive-config
     */
    public function getArchiveConfig(Request $request): JsonResponse
    {
        $dealershipId = $request->query('dealership_id') !== null && $request->query('dealership_id') !== '' ? (int) $request->query('dealership_id') : null;

        $archiveConfig = [
            'archive_completed_time' => $this->settingsService->getSettingWithFallback('archive_completed_time', $dealershipId, '03:00'),
            'archive_overdue_day_of_week' => (int) $this->settingsService->getSettingWithFallback('archive_overdue_day_of_week', $dealershipId, 0),
            'archive_overdue_time' => $this->settingsService->getSettingWithFallback('archive_overdue_time', $dealershipId, '03:00'),
        ];

        return response()->json([
            'success' => true,
            'data' => $archiveConfig,
        ]);
    }

    /**
     * Update archive configuration
     *
     * PUT /api/v1/settings/archive-config
     */
    public function updateArchiveConfig(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'archive_completed_time' => ['nullable', 'string', 'regex:/^([0-1][0-9]|2[0-3]):[0-5][0-9]$/'],
            'archive_overdue_day_of_week' => ['nullable', 'integer', 'min:0', 'max:7'],
            'archive_overdue_time' => ['nullable', 'string', 'regex:/^([0-1][0-9]|2[0-3]):[0-5][0-9]$/'],
            'dealership_id' => ['nullable', 'integer'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $data = $validator->validated();
            $dealershipId = $data['dealership_id'] ?? null;
            unset($data['dealership_id']);

            $updatedSettings = [];
            foreach ($data as $key => $value) {
                if ($value !== null) {
                    $type = is_int($value) ? 'integer' : 'time';
                    $this->settingsService->set($key, $value, $dealershipId, $type);
                    $updatedSettings[$key] = $value;
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Archive configuration updated successfully',
                'data' => $updatedSettings,
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Get task configuration settings (shift requirements, archiving)
     *
     * GET /api/v1/settings/task-config
     */
    public function getTaskConfig(Request $request): JsonResponse
    {
        $dealershipId = $request->query('dealership_id') !== null && $request->query('dealership_id') !== ''
            ? (int) $request->query('dealership_id')
            : null;

        $taskConfig = [
            // Hybrid mode: require open shift to complete tasks
            'task_requires_open_shift' => (bool) $this->settingsService->getSettingWithFallback(
                'task_requires_open_shift',
                $dealershipId,
                false
            ),
            // Hours after shift close to archive overdue tasks
            'archive_overdue_hours_after_shift' => (int) $this->settingsService->getSettingWithFallback(
                'archive_overdue_hours_after_shift',
                $dealershipId,
                2
            ),
        ];

        return response()->json([
            'success' => true,
            'data' => $taskConfig,
        ]);
    }

    /**
     * Update task configuration settings
     *
     * PUT /api/v1/settings/task-config
     */
    public function updateTaskConfig(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'task_requires_open_shift' => ['nullable', 'boolean'],
            'archive_overdue_hours_after_shift' => ['nullable', 'integer', 'min:1', 'max:48'],
            'dealership_id' => ['nullable', 'integer'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $data = $validator->validated();
            $dealershipId = $data['dealership_id'] ?? null;
            unset($data['dealership_id']);

            $updatedSettings = [];
            foreach ($data as $key => $value) {
                if ($value !== null) {
                    $type = is_bool($value) ? 'boolean' : 'integer';
                    $this->settingsService->set($key, $value, $dealershipId, $type);
                    $updatedSettings[$key] = $value;
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Task configuration updated successfully',
                'data' => $updatedSettings,
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }
}
