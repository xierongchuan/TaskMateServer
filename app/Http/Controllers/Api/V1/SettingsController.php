<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateArchiveConfigRequest;
use App\Http\Requests\Api\V1\UpdateNotificationConfigRequest;
use App\Http\Requests\Api\V1\UpdateSettingRequest;
use App\Http\Requests\Api\V1\UpdateShiftConfigRequest;
use App\Http\Requests\Api\V1\UpdateTaskConfigRequest;
use App\Models\Setting;
use App\Services\SettingsService;
use App\Traits\HasDealershipAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * RESTful Settings management API
 */
class SettingsController extends Controller
{
    use HasDealershipAccess;

    public function __construct(
        private readonly SettingsService $settingsService
    ) {}

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
                'scope' => 'global',
            ],
        ]);
    }

    /**
     * Update a specific global setting
     *
     * PUT /api/v1/settings/{key}
     */
    public function update(UpdateSettingRequest $request, string $key): JsonResponse
    {
        try {
            $data = $request->validated();

            $setting = $this->settingsService->set(
                $key,
                $data['value'],
                null, // Global setting
                $data['type'] ?? 'string',
                $data['description'] ?? null
            );

            return response()->json([
                'success' => true,
                'message' => 'Настройка успешно обновлена',
                'data' => [
                    'key' => $key,
                    'value' => $setting->getTypedValue(),
                    'scope' => 'global',
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
        $dealershipId = $this->parseDealershipId($request);

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
    public function updateShiftConfig(UpdateShiftConfigRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();
            $dealershipId = $data['dealership_id'] ?? null;

            $updatedSettings = [];
            if (isset($data['late_tolerance_minutes'])) {
                $this->settingsService->set('late_tolerance_minutes', $data['late_tolerance_minutes'], $dealershipId, 'integer');
                $updatedSettings['late_tolerance_minutes'] = $data['late_tolerance_minutes'];
            }

            return response()->json([
                'success' => true,
                'message' => 'Конфигурация смен успешно обновлена',
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
        $dealershipId = $this->parseDealershipId($request);
        $settings = $this->settingsService->getMultipleSettingsWithFallback(
            ['notification_enabled', 'auto_close_shifts', 'shift_reminder_minutes', 'rows_per_page', 'notification_types'],
            $dealershipId,
            [
                'notification_enabled' => true,
                'auto_close_shifts' => false,
                'shift_reminder_minutes' => 15,
                'rows_per_page' => 10,
                'notification_types' => [
                    'task_overdue' => true,
                    'shift_late' => true,
                    'task_completed' => true,
                    'system_errors' => true,
                ],
            ]
        );

        $notificationConfig = [
            'notification_enabled' => (bool) $settings['notification_enabled'],
            'auto_close_shifts' => (bool) $settings['auto_close_shifts'],
            'shift_reminder_minutes' => (int) $settings['shift_reminder_minutes'],
            'rows_per_page' => (int) $settings['rows_per_page'],
            'notification_types' => $settings['notification_types'],
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
    public function updateNotificationConfig(UpdateNotificationConfigRequest $request): JsonResponse
    {
        return $this->updateConfigSettings($request, 'Конфигурация уведомлений успешно обновлена');
    }

    /**
     * Get archive configuration
     *
     * GET /api/v1/settings/archive-config
     */
    public function getArchiveConfig(Request $request): JsonResponse
    {
        $dealershipId = $this->parseDealershipId($request);
        $settings = $this->settingsService->getMultipleSettingsWithFallback(
            ['archive_completed_time', 'archive_overdue_day_of_week', 'archive_overdue_time'],
            $dealershipId,
            [
                'archive_completed_time' => '03:00',
                'archive_overdue_day_of_week' => 0,
                'archive_overdue_time' => '03:00',
            ]
        );

        $archiveConfig = [
            'archive_completed_time' => $settings['archive_completed_time'],
            'archive_overdue_day_of_week' => (int) $settings['archive_overdue_day_of_week'],
            'archive_overdue_time' => $settings['archive_overdue_time'],
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
    public function updateArchiveConfig(UpdateArchiveConfigRequest $request): JsonResponse
    {
        return $this->updateConfigSettings($request, 'Конфигурация архивации успешно обновлена');
    }

    /**
     * Get task configuration settings (shift requirements, archiving)
     *
     * GET /api/v1/settings/task-config
     */
    public function getTaskConfig(Request $request): JsonResponse
    {
        $dealershipId = $this->parseDealershipId($request);
        $settings = $this->settingsService->getMultipleSettingsWithFallback(
            ['task_requires_open_shift', 'archive_overdue_hours_after_shift'],
            $dealershipId,
            [
                'task_requires_open_shift' => false,
                'archive_overdue_hours_after_shift' => 2,
            ]
        );

        $taskConfig = [
            // Hybrid mode: require open shift to complete tasks
            'task_requires_open_shift' => (bool) $settings['task_requires_open_shift'],
            // Hours after shift close to archive overdue tasks
            'archive_overdue_hours_after_shift' => (int) $settings['archive_overdue_hours_after_shift'],
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
    public function updateTaskConfig(UpdateTaskConfigRequest $request): JsonResponse
    {
        return $this->updateConfigSettings($request, 'Конфигурация задач успешно обновлена');
    }

    /**
     * Общий метод обновления настроек конфигурации.
     *
     * Извлекает dealership_id, определяет тип каждого значения и сохраняет.
     */
    private function updateConfigSettings(Request $request, string $successMessage): JsonResponse
    {
        try {
            $data = method_exists($request, 'validated') ? $request->validated() : $request->all();
            $dealershipId = $data['dealership_id'] ?? null;
            unset($data['dealership_id']);

            $updatedSettings = [];
            foreach ($data as $key => $value) {
                if ($value !== null) {
                    $type = $this->resolveSettingType($value);
                    $this->settingsService->set($key, $value, $dealershipId, $type);
                    $updatedSettings[$key] = $value;
                }
            }

            return response()->json([
                'success' => true,
                'message' => $successMessage,
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
     * Определить тип настройки по значению.
     */
    private function resolveSettingType(mixed $value): string
    {
        if (is_bool($value)) {
            return 'boolean';
        }
        if (is_int($value)) {
            return 'integer';
        }
        if (is_array($value)) {
            return 'json';
        }

        return 'string';
    }
}
