<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\CalendarController;
use Illuminate\Support\Facades\Route;

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
