<?php

declare(strict_types=1);

use App\Services\ShiftService;
use App\Services\SettingsService;
use App\Models\User;
use App\Models\Shift;
use App\Models\AutoDealership;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

describe('ShiftService', function () {
    beforeEach(function () {
        $this->settingsService = Mockery::mock(SettingsService::class);
        // Bind the mock to the container so ShiftService can be resolved
        app()->instance(SettingsService::class, $this->settingsService);
        $this->service = app(ShiftService::class);
        Storage::fake('public');
    });

    it('opens a shift', function () {
        $dealership = AutoDealership::factory()->create();
        $user = User::factory()->create(['dealership_id' => $dealership->id]);
        $photo = UploadedFile::fake()->image('photo.jpg');

        // Создаём расписание смены для автосалона
        \App\Models\ShiftSchedule::create([
            'dealership_id' => $dealership->id,
            'name' => 'Смена 1',
            'sort_order' => 0,
            'start_time' => '09:00',
            'end_time' => '18:00',
            'is_active' => true,
        ]);

        // Устанавливаем время внутри допустимого окна смены (09:00 + 10 мин)
        Carbon::setTestNow(Carbon::today()->setHour(9)->setMinute(10));

        $this->settingsService->shouldReceive('getTimezone')->andReturn('+00:00');
        $this->settingsService->shouldReceive('getLateTolerance')->andReturn(15);

        $shift = $this->service->openShift($user, $photo);

        expect($shift)->toBeInstanceOf(Shift::class)
            ->and($shift->status)->toBe('open')
            ->and($shift->shift_schedule_id)->not->toBeNull();

        Carbon::setTestNow(); // Reset
    });

    it('closes a shift', function () {
        $dealership = AutoDealership::factory()->create();
        $user = User::factory()->create(['dealership_id' => $dealership->id]);
        $shift = Shift::factory()->create([
            'user_id' => $user->id,
            'dealership_id' => $dealership->id,
            'status' => 'open',
        ]);
        $photo = UploadedFile::fake()->image('closing.jpg');

        $closedShift = $this->service->closeShift($shift, $photo);

        expect($closedShift->status)->toBe('closed')
            ->and($closedShift->shift_end)->not->toBeNull();
    });
});
