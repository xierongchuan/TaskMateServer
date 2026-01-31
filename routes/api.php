<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\ArchivedTaskController;
use App\Http\Controllers\Api\V1\CalendarController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DealershipController;
use App\Http\Controllers\Api\V1\ImportantLinkController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\SessionController;
use App\Http\Controllers\Api\V1\SettingsController;
use App\Http\Controllers\Api\V1\ShiftController;
use App\Http\Controllers\Api\V1\ShiftScheduleController;
use App\Http\Controllers\Api\V1\TaskController;
use App\Http\Controllers\Api\V1\TaskGeneratorController;
use App\Http\Controllers\Api\V1\TaskProofController;
use App\Http\Controllers\Api\V1\TaskVerificationController;
use App\Http\Controllers\Api\V1\UserApiController;
use App\Http\Controllers\Api\V1\ShiftPhotoController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\FileConfigController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Открытие сессии (логин) - с rate limiting для защиты от brute-force
    Route::post(
        '/session',
        [SessionController::class, 'store']
    )->middleware('throttle:login');

    // Закрытие сессии (логаут)
    Route::delete(
        '/session',
        [SessionController::class, 'destroy']
    )->middleware('auth:sanctum');

    // Получение текущего пользователя
    Route::get(
        '/session/current',
        [SessionController::class, 'current']
    )->middleware('auth:sanctum');

    // Проверка работоспособности API
    Route::get('/up', function () {
        return response()->json(['success' => true], 200);
    });

    // File Upload Config - публичный endpoint для frontend
    Route::get('/config/file-upload', [FileConfigController::class, 'index'])
        ->middleware('throttle:api');
    Route::get('/config/file-upload/{preset}', [FileConfigController::class, 'show'])
        ->middleware('throttle:api');

    // Shift Photos - доступ по подписанному URL (без auth:sanctum)
    // Безопасность обеспечивается временной подписью URL (60 мин)
    Route::get('/shifts/{id}/photo/{type}', [ShiftPhotoController::class, 'download'])
        ->name('shift-photos.download')
        ->middleware('throttle:api');

    // Task Proofs - доступ по подписанному URL (без auth:sanctum)
    // Безопасность обеспечивается подписанным URL:
    // - URL генерируется только для авторизованных пользователей
    // - URL имеет ограниченное время жизни (60 мин)
    // - Проверка прав происходит при генерации URL, а не при скачивании
    Route::get('/task-proofs/{id}/download', [TaskProofController::class, 'download'])
        ->name('task-proofs.download')
        ->middleware('throttle:downloads');

    Route::get('/task-shared-proofs/{id}/download', [TaskProofController::class, 'downloadShared'])
        ->name('task-shared-proofs.download')
        ->middleware('throttle:downloads');

    Route::middleware(['auth:sanctum', 'throttle:api'])
        ->group(function () {
            // Users - READ операции
            Route::get('/users', [UserApiController::class, 'index']);
            Route::get('/users/{id}', [UserApiController::class, 'show']);
            Route::get('/users/{id}/status', [UserApiController::class, 'status']);
            Route::get('/users/{id}/stats', [UserApiController::class, 'stats']);

            // Users - WRITE операции (только managers и owners)
            Route::post('/users', [UserApiController::class, 'store'])
                ->middleware('role:manager,owner');
            Route::put('/users/{id}', [UserApiController::class, 'update'])
                ->middleware('role:manager,owner');
            Route::delete('/users/{id}', [UserApiController::class, 'destroy'])
                ->middleware('role:manager,owner');

            // Dealerships - READ операции
            Route::get('/dealerships', [DealershipController::class, 'index']);
            Route::get('/dealerships/{id}', [DealershipController::class, 'show']);

            // Dealerships - WRITE операции (только owner)
            Route::post('/dealerships', [DealershipController::class, 'store'])
                ->middleware('role:owner');
            Route::put('/dealerships/{id}', [DealershipController::class, 'update'])
                ->middleware('role:owner');
            Route::delete('/dealerships/{id}', [DealershipController::class, 'destroy'])
                ->middleware('role:owner');

            // Shifts - READ операции
            Route::get('/shifts', [ShiftController::class, 'index']);
            Route::get('/shifts/current', [ShiftController::class, 'current']);
            Route::get('/shifts/statistics', [ShiftController::class, 'statistics']);
            Route::get('/shifts/my', [ShiftController::class, 'myShifts']);
            Route::get('/shifts/my/current', [ShiftController::class, 'myCurrentShift']);
            Route::get('/shifts/{id}', [ShiftController::class, 'show']);

            // Shifts - WRITE операции
            Route::post('/shifts', [ShiftController::class, 'store']);
            Route::put('/shifts/{id}', [ShiftController::class, 'update']);
            Route::delete('/shifts/{id}', [ShiftController::class, 'destroy']);

            // Shift Schedules - READ операции
            Route::get('/shift-schedules', [ShiftScheduleController::class, 'index']);
            Route::get('/shift-schedules/{id}', [ShiftScheduleController::class, 'show']);

            // Shift Schedules - WRITE операции (только managers и owners)
            Route::post('/shift-schedules', [ShiftScheduleController::class, 'store'])
                ->middleware('role:manager,owner');
            Route::put('/shift-schedules/{id}', [ShiftScheduleController::class, 'update'])
                ->middleware('role:manager,owner');
            Route::delete('/shift-schedules/{id}', [ShiftScheduleController::class, 'destroy'])
                ->middleware('role:manager,owner');

            // Shift Photos - доступ с Bearer token авторизацией (stable URLs)
            Route::get('/shift-photos/{id}/{type}', [ShiftPhotoController::class, 'show'])
                ->where('type', 'opening|closing')
                ->name('shift-photos.show');

            // Tasks - READ операции
            Route::get('/tasks', [TaskController::class, 'index']);
            Route::get('/tasks/my-history', [TaskController::class, 'myHistory']);
            Route::get('/tasks/{id}', [TaskController::class, 'show']);

            // Tasks - WRITE операции (только managers и owners)
            Route::post('/tasks', [TaskController::class, 'store'])
                ->middleware('role:manager,owner');
            Route::put('/tasks/{id}', [TaskController::class, 'update'])
                ->middleware('role:manager,owner');
            Route::delete('/tasks/{id}', [TaskController::class, 'destroy'])
                ->middleware('role:manager,owner');

            // Task status update - доступно всем (сотрудники могут загружать доказательства)
            Route::patch('/tasks/{id}/status', [TaskController::class, 'updateStatus']);

            // Task Proofs - доказательства выполнения
            // (download вынесен за пределы auth:sanctum - доступ по подписанному URL)
            Route::get('/task-proofs/{id}', [TaskProofController::class, 'show']);
            Route::delete('/task-proofs/{id}', [TaskProofController::class, 'destroy']);
            Route::delete('/task-shared-proofs/{id}', [TaskProofController::class, 'destroyShared']);

            // Task Verification - верификация доказательств (только managers и owners)
            Route::post('/task-responses/{id}/approve', [TaskVerificationController::class, 'approve'])
                ->middleware('role:manager,owner');
            Route::post('/task-responses/{id}/reject', [TaskVerificationController::class, 'reject'])
                ->middleware('role:manager,owner');
            Route::post('/tasks/{id}/reject-all-responses', [TaskVerificationController::class, 'rejectAll'])
                ->middleware('role:manager,owner');

            // Task Generators - READ операции
            Route::get('/task-generators', [TaskGeneratorController::class, 'index']);
            Route::get('/task-generators/{id}', [TaskGeneratorController::class, 'show']);
            Route::get('/task-generators/{id}/tasks', [TaskGeneratorController::class, 'generatedTasks']);
            Route::get('/task-generators/{id}/stats', [TaskGeneratorController::class, 'statistics']);

            // Task Generators - WRITE операции (только managers и owners)
            Route::post('/task-generators', [TaskGeneratorController::class, 'store'])
                ->middleware('role:manager,owner');
            Route::put('/task-generators/{id}', [TaskGeneratorController::class, 'update'])
                ->middleware('role:manager,owner');
            Route::delete('/task-generators/{id}', [TaskGeneratorController::class, 'destroy'])
                ->middleware('role:manager,owner');
            Route::post('/task-generators/{id}/pause', [TaskGeneratorController::class, 'pause'])
                ->middleware('role:manager,owner');
            Route::post('/task-generators/{id}/resume', [TaskGeneratorController::class, 'resume'])
                ->middleware('role:manager,owner');
            Route::post('/task-generators/pause-all', [TaskGeneratorController::class, 'pauseAll'])
                ->middleware('role:owner');
            Route::post('/task-generators/resume-all', [TaskGeneratorController::class, 'resumeAll'])
                ->middleware('role:owner');

            // Archived Tasks
            Route::get('/archived-tasks', [ArchivedTaskController::class, 'index']);
            Route::get('/archived-tasks/statistics', [ArchivedTaskController::class, 'statistics']);
            Route::get('/archived-tasks/export', [ArchivedTaskController::class, 'export']);
            Route::post('/archived-tasks/{id}/restore', [ArchivedTaskController::class, 'restore'])
                ->middleware('role:manager,owner');

            // Important Links - READ операции
            Route::get('/links', [ImportantLinkController::class, 'index']);
            Route::get('/links/{id}', [ImportantLinkController::class, 'show']);

            // Important Links - WRITE операции (только managers и owners)
            Route::post('/links', [ImportantLinkController::class, 'store'])
                ->middleware('role:manager,owner');
            Route::put('/links/{id}', [ImportantLinkController::class, 'update'])
                ->middleware('role:manager,owner');
            Route::delete('/links/{id}', [ImportantLinkController::class, 'destroy'])
                ->middleware('role:manager,owner');

            // Dashboard
            Route::get('/dashboard', [DashboardController::class, 'index']);

            // Reports
            Route::get('/reports', [ReportController::class, 'index']);
            Route::get('/reports/issues/{type}', [ReportController::class, 'issueDetails']);

            // Settings - READ операции
            Route::get('/settings', [SettingsController::class, 'index']);
            Route::get('/settings/shift-config', [SettingsController::class, 'getShiftConfig']);
            Route::get('/settings/notification-config', [SettingsController::class, 'getNotificationConfig']);
            Route::get('/settings/archive-config', [SettingsController::class, 'getArchiveConfig']);
            Route::get('/settings/task-config', [SettingsController::class, 'getTaskConfig']);
            Route::get('/settings/{key}', [SettingsController::class, 'show']);

            // Settings - WRITE операции (только owner)
            Route::post('/settings/shift-config', [SettingsController::class, 'updateShiftConfig'])
                ->middleware('role:owner');
            Route::put('/settings/notification-config', [SettingsController::class, 'updateNotificationConfig'])
                ->middleware('role:manager,owner');
            Route::put('/settings/archive-config', [SettingsController::class, 'updateArchiveConfig'])
                ->middleware('role:manager,owner');
            Route::put('/settings/task-config', [SettingsController::class, 'updateTaskConfig'])
                ->middleware('role:manager,owner');
            Route::put('/settings/{key}', [SettingsController::class, 'update'])
                ->middleware('role:owner');

            // Notification Settings - managers and owners
            Route::get('/notification-settings', [\App\Http\Controllers\Api\V1\NotificationSettingController::class, 'index'])
                ->middleware('role:manager,owner');
            Route::put('/notification-settings/{channelType}', [\App\Http\Controllers\Api\V1\NotificationSettingController::class, 'update'])
                ->middleware('role:manager,owner');
            Route::post('/notification-settings/bulk', [\App\Http\Controllers\Api\V1\NotificationSettingController::class, 'bulkUpdate'])
                ->middleware('role:manager,owner');
            Route::post('/notification-settings/reset', [\App\Http\Controllers\Api\V1\NotificationSettingController::class, 'resetToDefaults'])
                ->middleware('role:manager,owner');

            // Calendar - READ операции
            Route::get('/calendar/{year}', [CalendarController::class, 'index']);
            Route::get('/calendar/{year}/holidays', [CalendarController::class, 'holidays']);
            Route::get('/calendar/check/{date}', [CalendarController::class, 'check']);

            // Calendar - WRITE операции (только managers и owners)
            Route::put('/calendar/{date}', [CalendarController::class, 'update'])
                ->middleware('role:manager,owner');
            Route::delete('/calendar/{date}', [CalendarController::class, 'destroy'])
                ->middleware('role:manager,owner');
            Route::post('/calendar/bulk', [CalendarController::class, 'bulkUpdate'])
                ->middleware('role:manager,owner');
            Route::delete('/calendar/{year}/reset', [CalendarController::class, 'resetToGlobal'])
                ->middleware('role:manager,owner');

            // Audit Logs - только owner
            Route::get('/audit-logs', [AuditLogController::class, 'index'])
                ->middleware('role:owner');
            // Список пользователей, совершавших действия (для фильтра)
            Route::get('/audit-logs/actors', [AuditLogController::class, 'actors'])
                ->middleware('role:owner');
            // История записи - managers и owners
            Route::get('/audit-logs/{tableName}/{recordId}', [AuditLogController::class, 'forRecord'])
                ->middleware('role:manager,owner');
        });
});

