<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\FileConfigController;
use App\Http\Controllers\Api\V1\SessionController;
use App\Http\Controllers\Api\V1\ShiftPhotoController;
use App\Http\Controllers\Api\V1\TaskProofController;
use Illuminate\Support\Facades\Route;

// Открытие сессии (логин) - с rate limiting для защиты от brute-force
Route::post('/session', [SessionController::class, 'store'])
    ->middleware('throttle:login');

// Закрытие сессии (логаут)
Route::delete('/session', [SessionController::class, 'destroy'])
    ->middleware('auth:sanctum');

// Получение текущего пользователя
Route::get('/session/current', [SessionController::class, 'current'])
    ->middleware('auth:sanctum');

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
