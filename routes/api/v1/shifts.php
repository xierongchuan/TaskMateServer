<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\ShiftController;
use App\Http\Controllers\Api\V1\ShiftPhotoController;
use App\Http\Controllers\Api\V1\ShiftScheduleController;
use Illuminate\Support\Facades\Route;

// Shifts - READ операции
Route::get('/shifts', [ShiftController::class, 'index']);
Route::get('/shifts/current', [ShiftController::class, 'current']);
Route::get('/shifts/statistics', [ShiftController::class, 'statistics']);
Route::get('/shifts/my', [ShiftController::class, 'myShifts']);
Route::get('/shifts/my/current', [ShiftController::class, 'myCurrentShift']);
Route::get('/shifts/available-schedules', [ShiftController::class, 'availableSchedules']);
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
Route::post('/shift-schedules/{id}/restore', [ShiftScheduleController::class, 'restore'])
    ->middleware('role:manager,owner');

// Shift Photos - доступ с Bearer token авторизацией (stable URLs)
Route::get('/shift-photos/{id}/{type}', [ShiftPhotoController::class, 'show'])
    ->where('type', 'opening|closing')
    ->name('shift-photos.show');
