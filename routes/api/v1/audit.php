<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuditLogController;
use Illuminate\Support\Facades\Route;

// Audit Logs - только owner
Route::get('/audit-logs', [AuditLogController::class, 'index'])
    ->middleware('role:owner');

// Список пользователей, совершавших действия (для фильтра)
Route::get('/audit-logs/actors', [AuditLogController::class, 'actors'])
    ->middleware('role:owner');

// История записи - managers и owners
Route::get('/audit-logs/{tableName}/{recordId}', [AuditLogController::class, 'forRecord'])
    ->middleware('role:manager,owner');
