<?php

declare(strict_types=1);

use App\Models\AutoDealership;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\User;
use App\Services\SettingsService;
use App\Services\ShiftService;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

describe('ShiftService', function () {
    beforeEach(function () {
        $this->settingsService = Mockery::mock(SettingsService::class);
        app()->instance(SettingsService::class, $this->settingsService);
        $this->service = app(ShiftService::class);
        Storage::fake('public');

        $this->dealership = AutoDealership::factory()->create(['timezone' => '+00:00']);
        $this->user = User::factory()->create(['dealership_id' => $this->dealership->id]);
    });

    afterEach(function () {
        Carbon::setTestNow();
    });

    describe('openShift', function () {
        it('opens a shift within schedule range', function () {
            ShiftSchedule::create([
                'dealership_id' => $this->dealership->id,
                'name' => 'Смена 1',
                'sort_order' => 0,
                'start_time' => '09:00',
                'end_time' => '18:00',
                'is_active' => true,
            ]);

            Carbon::setTestNow(Carbon::parse('2026-01-31 09:10:00', 'UTC'));
            $this->settingsService->shouldReceive('getTimezone')->andReturn('+00:00');
            $this->settingsService->shouldReceive('getLateTolerance')->andReturn(15);

            $shift = $this->service->openShift($this->user, UploadedFile::fake()->image('photo.jpg'));

            expect($shift)->toBeInstanceOf(Shift::class)
                ->and($shift->status)->toBe('open')
                ->and($shift->shift_schedule_id)->not->toBeNull();
        });

        it('closes a shift', function () {
            $shift = Shift::factory()->create([
                'user_id' => $this->user->id,
                'dealership_id' => $this->dealership->id,
                'status' => 'open',
            ]);

            $closedShift = $this->service->closeShift($shift, UploadedFile::fake()->image('closing.jpg'));

            expect($closedShift->status)->toBe('closed')
                ->and($closedShift->shift_end)->not->toBeNull();
        });

        it('resolves midnight-crossing schedule before midnight', function () {
            $schedule = ShiftSchedule::create([
                'dealership_id' => $this->dealership->id,
                'name' => 'Ночная',
                'sort_order' => 0,
                'start_time' => '22:00',
                'end_time' => '06:00',
                'is_active' => true,
            ]);

            Carbon::setTestNow(Carbon::parse('2026-01-31 23:00:00', 'UTC'));
            $this->settingsService->shouldReceive('getTimezone')->andReturn('+00:00');
            $this->settingsService->shouldReceive('getLateTolerance')->andReturn(15);

            $shift = $this->service->openShift($this->user, UploadedFile::fake()->image('photo.jpg'));

            expect($shift->shift_schedule_id)->toBe($schedule->id);
        });

        it('resolves midnight-crossing schedule after midnight', function () {
            $schedule = ShiftSchedule::create([
                'dealership_id' => $this->dealership->id,
                'name' => 'Ночная',
                'sort_order' => 0,
                'start_time' => '22:00',
                'end_time' => '06:00',
                'is_active' => true,
            ]);

            Carbon::setTestNow(Carbon::parse('2026-01-31 02:00:00', 'UTC'));
            $this->settingsService->shouldReceive('getTimezone')->andReturn('+00:00');
            $this->settingsService->shouldReceive('getLateTolerance')->andReturn(15);

            $shift = $this->service->openShift($this->user, UploadedFile::fake()->image('photo.jpg'));

            expect($shift->shift_schedule_id)->toBe($schedule->id);
        });

        it('allows early opening within tolerance', function () {
            $schedule = ShiftSchedule::create([
                'dealership_id' => $this->dealership->id,
                'name' => 'Смена 1',
                'sort_order' => 0,
                'start_time' => '09:00',
                'end_time' => '18:00',
                'is_active' => true,
            ]);

            // 10 minutes before start, tolerance = 15
            Carbon::setTestNow(Carbon::parse('2026-01-31 08:50:00', 'UTC'));
            $this->settingsService->shouldReceive('getTimezone')->andReturn('+00:00');
            $this->settingsService->shouldReceive('getLateTolerance')->andReturn(15);

            $shift = $this->service->openShift($this->user, UploadedFile::fake()->image('photo.jpg'));

            expect($shift->shift_schedule_id)->toBe($schedule->id)
                ->and($shift->late_minutes)->toBe(0)
                ->and($shift->status)->toBe('open');
        });

        it('marks as late when beyond tolerance', function () {
            ShiftSchedule::create([
                'dealership_id' => $this->dealership->id,
                'name' => 'Смена 1',
                'sort_order' => 0,
                'start_time' => '09:00',
                'end_time' => '18:00',
                'is_active' => true,
            ]);

            // 25 minutes after start, tolerance = 15
            Carbon::setTestNow(Carbon::parse('2026-01-31 09:25:00', 'UTC'));
            $this->settingsService->shouldReceive('getTimezone')->andReturn('+00:00');
            $this->settingsService->shouldReceive('getLateTolerance')->andReturn(15);

            $shift = $this->service->openShift($this->user, UploadedFile::fake()->image('photo.jpg'));

            expect($shift->status)->toBe('late')
                ->and($shift->late_minutes)->toBe(25);
        });

        it('marks as open when within tolerance', function () {
            ShiftSchedule::create([
                'dealership_id' => $this->dealership->id,
                'name' => 'Смена 1',
                'sort_order' => 0,
                'start_time' => '09:00',
                'end_time' => '18:00',
                'is_active' => true,
            ]);

            // 10 minutes after start, tolerance = 15
            Carbon::setTestNow(Carbon::parse('2026-01-31 09:10:00', 'UTC'));
            $this->settingsService->shouldReceive('getTimezone')->andReturn('+00:00');
            $this->settingsService->shouldReceive('getLateTolerance')->andReturn(15);

            $shift = $this->service->openShift($this->user, UploadedFile::fake()->image('photo.jpg'));

            expect($shift->status)->toBe('open')
                ->and($shift->late_minutes)->toBe(10);
        });

        it('throws when no schedules configured', function () {
            Carbon::setTestNow(Carbon::parse('2026-01-31 09:00:00', 'UTC'));
            $this->settingsService->shouldReceive('getTimezone')->andReturn('+00:00');
            $this->settingsService->shouldReceive('getLateTolerance')->andReturn(15);

            expect(fn () => $this->service->openShift($this->user, UploadedFile::fake()->image('photo.jpg')))
                ->toThrow(\InvalidArgumentException::class, 'Не настроены смены');
        });

        it('throws when too early for any schedule', function () {
            ShiftSchedule::create([
                'dealership_id' => $this->dealership->id,
                'name' => 'Смена 1',
                'sort_order' => 0,
                'start_time' => '09:00',
                'end_time' => '18:00',
                'is_active' => true,
            ]);

            // 2 hours before start, tolerance = 15 min
            Carbon::setTestNow(Carbon::parse('2026-01-31 07:00:00', 'UTC'));
            $this->settingsService->shouldReceive('getTimezone')->andReturn('+00:00');
            $this->settingsService->shouldReceive('getLateTolerance')->andReturn(15);

            expect(fn () => $this->service->openShift($this->user, UploadedFile::fake()->image('photo.jpg')))
                ->toThrow(\InvalidArgumentException::class);
        });

        it('prevents duplicate open shift', function () {
            ShiftSchedule::create([
                'dealership_id' => $this->dealership->id,
                'name' => 'Смена 1',
                'sort_order' => 0,
                'start_time' => '09:00',
                'end_time' => '18:00',
                'is_active' => true,
            ]);

            Shift::factory()->create([
                'user_id' => $this->user->id,
                'dealership_id' => $this->dealership->id,
                'status' => 'open',
            ]);

            Carbon::setTestNow(Carbon::parse('2026-01-31 09:00:00', 'UTC'));
            $this->settingsService->shouldReceive('getTimezone')->andReturn('+00:00');
            $this->settingsService->shouldReceive('getLateTolerance')->andReturn(15);

            expect(fn () => $this->service->openShift($this->user, UploadedFile::fake()->image('photo.jpg')))
                ->toThrow(\InvalidArgumentException::class, 'already has an open shift');
        });

        it('sets scheduled_end next day for midnight-crossing shift', function () {
            ShiftSchedule::create([
                'dealership_id' => $this->dealership->id,
                'name' => 'Ночная',
                'sort_order' => 0,
                'start_time' => '22:00',
                'end_time' => '06:00',
                'is_active' => true,
            ]);

            Carbon::setTestNow(Carbon::parse('2026-01-31 22:30:00'));
            $this->settingsService->shouldReceive('getTimezone')->andReturn('+00:00');
            $this->settingsService->shouldReceive('getLateTolerance')->andReturn(15);

            $shift = $this->service->openShift($this->user, UploadedFile::fake()->image('photo.jpg'));

            // scheduled_start = 2026-01-31 22:00, scheduled_end = 2026-02-01 06:00
            expect($shift->scheduled_end->gt($shift->scheduled_start))->toBeTrue();
        });

        it('selects correct schedule among multiple', function () {
            ShiftSchedule::create([
                'dealership_id' => $this->dealership->id,
                'name' => 'Утренняя',
                'sort_order' => 0,
                'start_time' => '06:00',
                'end_time' => '14:00',
                'is_active' => true,
            ]);
            $evening = ShiftSchedule::create([
                'dealership_id' => $this->dealership->id,
                'name' => 'Вечерняя',
                'sort_order' => 1,
                'start_time' => '14:00',
                'end_time' => '22:00',
                'is_active' => true,
            ]);

            Carbon::setTestNow(Carbon::parse('2026-01-31 15:00:00', 'UTC'));
            $this->settingsService->shouldReceive('getTimezone')->andReturn('+00:00');
            $this->settingsService->shouldReceive('getLateTolerance')->andReturn(15);

            $shift = $this->service->openShift($this->user, UploadedFile::fake()->image('photo.jpg'));

            expect($shift->shift_schedule_id)->toBe($evening->id);
        });

        it('opens at exact end_time via after-end tolerance', function () {
            $schedule = ShiftSchedule::create([
                'dealership_id' => $this->dealership->id,
                'name' => 'Смена 1',
                'sort_order' => 0,
                'start_time' => '09:00',
                'end_time' => '18:00',
                'is_active' => true,
            ]);

            // Exactly at end_time — containsTime returns false (exclusive end)
            // But after-end tolerance logic (diff=0 <= tolerance) binds to this schedule
            Carbon::setTestNow(Carbon::parse('2026-01-31 18:00:00', 'UTC'));
            $this->settingsService->shouldReceive('getTimezone')->andReturn('+00:00');
            $this->settingsService->shouldReceive('getLateTolerance')->andReturn(15);

            $shift = $this->service->openShift($this->user, UploadedFile::fake()->image('photo.jpg'));

            expect($shift->shift_schedule_id)->toBe($schedule->id);
        });

        it('opens at 00:00 for midnight-crossing schedule', function () {
            $schedule = ShiftSchedule::create([
                'dealership_id' => $this->dealership->id,
                'name' => 'Ночная',
                'sort_order' => 0,
                'start_time' => '22:00',
                'end_time' => '06:00',
                'is_active' => true,
            ]);

            Carbon::setTestNow(Carbon::parse('2026-01-31 00:00:00', 'UTC'));
            $this->settingsService->shouldReceive('getTimezone')->andReturn('+00:00');
            $this->settingsService->shouldReceive('getLateTolerance')->andReturn(15);

            $shift = $this->service->openShift($this->user, UploadedFile::fake()->image('photo.jpg'));

            expect($shift->shift_schedule_id)->toBe($schedule->id);
        });

        it('handles timezone offset for schedule resolution', function () {
            ShiftSchedule::create([
                'dealership_id' => $this->dealership->id,
                'name' => 'Смена 1',
                'sort_order' => 0,
                'start_time' => '09:00',
                'end_time' => '18:00',
                'is_active' => true,
            ]);

            // UTC 06:00 = local 09:00 in +03:00
            Carbon::setTestNow(Carbon::parse('2026-01-31 06:00:00', 'UTC'));
            $this->settingsService->shouldReceive('getTimezone')->andReturn('+03:00');
            $this->settingsService->shouldReceive('getLateTolerance')->andReturn(15);

            $shift = $this->service->openShift($this->user, UploadedFile::fake()->image('photo.jpg'));

            expect($shift->status)->toBe('open');
        });
    });
});
