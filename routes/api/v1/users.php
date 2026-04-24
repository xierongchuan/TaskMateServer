<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\DealershipController;
use App\Http\Controllers\Api\V1\UserApiController;
use Illuminate\Support\Facades\Route;

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
