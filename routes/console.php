<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Command to test workers manually
Artisan::command('workers:test {type=all}', function ($type) {
    $this->info("Testing worker: {$type}");
    $this->info("Current time: " . now()->format('Y-m-d H:i:s T'));
    $this->info('Use "php artisan queue:work --queue=notifications" to process the jobs');
})->purpose('Test notification workers manually');

// Process task generators - runs every 5 minutes to ensure tasks are generated promptly
Schedule::job(new \App\Jobs\ProcessTaskGeneratorsJob())->everyFiveMinutes();

// Check for completed tasks to archive - runs every 10 minutes (command handles settings and time logic)
Schedule::command('tasks:archive-completed --type=completed')->everyTenMinutes();

// Archive overdue tasks N hours after shift closes - runs hourly
Schedule::command('tasks:archive-overdue-after-shift')->hourly();

// Cleanup orphaned temp proof files - runs hourly
Schedule::command('proofs:cleanup-temp')->hourly();

// Auto-close shifts after scheduled_end - runs every 5 minutes
Schedule::job(new \App\Jobs\AutoCloseShiftsJob())->everyFiveMinutes();
