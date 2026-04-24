<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\NotificationSettingController;
use App\Http\Controllers\Api\V1\SettingsController;
use Illuminate\Support\Facades\Route;

// Settings - READ операции
Route::get('/settings', [SettingsController::class, 'index']);
Route::get('/settings/shift-config', [SettingsController::class, 'getShiftConfig']);
Route::get('/settings/notification-config', [SettingsController::class, 'getNotificationConfig']);
Route::get('/settings/archive-config', [SettingsController::class, 'getArchiveConfig']);
Route::get('/settings/task-config', [SettingsController::class, 'getTaskConfig']);
Route::get('/settings/{key}', [SettingsController::class, 'show']);

// Settings - WRITE операции
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
Route::get('/notification-settings', [NotificationSettingController::class, 'index'])
    ->middleware('role:manager,owner');
Route::put('/notification-settings/{channelType}', [NotificationSettingController::class, 'update'])
    ->middleware('role:manager,owner');
Route::post('/notification-settings/bulk', [NotificationSettingController::class, 'bulkUpdate'])
    ->middleware('role:manager,owner');
Route::post('/notification-settings/reset', [NotificationSettingController::class, 'resetToDefaults'])
    ->middleware('role:manager,owner');
