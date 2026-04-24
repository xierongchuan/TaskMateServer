<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\ImportantLinkController;
use App\Http\Controllers\Api\V1\ReportController;
use Illuminate\Support\Facades\Route;

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
