<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    require __DIR__.'/api/v1/public.php';

    Route::middleware(['auth:sanctum', 'throttle:api'])
        ->group(function (): void {
            require __DIR__.'/api/v1/users.php';
            require __DIR__.'/api/v1/shifts.php';
            require __DIR__.'/api/v1/tasks.php';
            require __DIR__.'/api/v1/operations.php';
            require __DIR__.'/api/v1/settings.php';
            require __DIR__.'/api/v1/calendar.php';
            require __DIR__.'/api/v1/audit.php';
        });
});
