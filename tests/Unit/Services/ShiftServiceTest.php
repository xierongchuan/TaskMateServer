<?php

declare(strict_types=1);

use App\Exceptions\ScheduleAmbiguousException;
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
            $this->settingsService->shouldReceive('getShiftReminderMinutes')->andReturn(15);

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
            $this->settingsService->shouldReceive('getShiftReminderMinutes')->andReturn(15);

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
            $this->settingsService->shouldReceive('getShiftReminderMinutes')->andReturn(15);

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
            $this->settingsService->shouldReceive('getShiftReminderMinutes')->andReturn(15);

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
            $this->settingsService->shouldReceive('getShiftReminderMinutes')->andReturn(15);

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
            $this->settingsService->shouldReceive('getShiftReminderMinutes')->andReturn(15);

            $shift = $this->service->openShift($this->user, UploadedFile::fake()->image('photo.jpg'));

            expect($shift->status)->toBe('open')
                ->and($shift->late_minutes)->toBe(10);
        });

        it('throws when no schedules configured', function () {
            Carbon::setTestNow(Carbon::parse('2026-01-31 09:00:00', 'UTC'));
            $this->settingsService->shouldReceive('getTimezone')->andReturn('+00:00');
            $this->settingsService->shouldReceive('getLateTolerance')->andReturn(15);
            $this->settingsService->shouldReceive('getShiftReminderMinutes')->andReturn(15);

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
            $this->settingsService->shouldReceive('getShiftReminderMinutes')->andReturn(15);

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
            $this->settingsService->shouldReceive('getShiftReminderMinutes')->andReturn(15);

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
            $this->settingsService->shouldReceive('getShiftReminderMinutes')->andReturn(15);

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
            $this->settingsService->shouldReceive('getShiftReminderMinutes')->andReturn(15);

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
            $this->settingsService->shouldReceive('getShiftReminderMinutes')->andReturn(15);

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
            $this->settingsService->shouldReceive('getShiftReminderMinutes')->andReturn(15);

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
            $this->settingsService->shouldReceive('getShiftReminderMinutes')->andReturn(15);

            $shift = $this->service->openShift($this->user, UploadedFile::fake()->image('photo.jpg'));

            expect($shift->status)->toBe('open');
        });
    });

    // ─── resolveAvailableSchedules / getAvailableSchedulesForNow ───

    describe('getAvailableSchedulesForNow', function () {
        it('returns single schedule when time is inside its interval', function () {
            ShiftSchedule::create([
                'dealership_id' => $this->dealership->id,
                'name' => 'Утренняя',
                'sort_order' => 0,
                'start_time' => '09:00',
                'end_time' => '18:00',
                'is_active' => true,
            ]);

            Carbon::setTestNow(Carbon::parse('2026-01-31 12:00:00', 'UTC'));
            $this->settingsService->shouldReceive('getTimezone')->andReturn('+00:00');
            $this->settingsService->shouldReceive('getLateTolerance')->andReturn(15);
            $this->settingsService->shouldReceive('getShiftReminderMinutes')->andReturn(15);

            $result = $this->service->getAvailableSchedulesForNow($this->dealership->id);

            expect($result)->toHaveCount(1)
                ->and($result->first()->name)->toBe('Утренняя');
        });

        it('returns multiple schedules when two overlap at current time', function () {
            ShiftSchedule::create([
                'dealership_id' => $this->dealership->id,
                'name' => 'Смена A',
                'sort_order' => 0,
                'start_time' => '08:00',
                'end_time' => '16:00',
                'is_active' => true,
            ]);
            ShiftSchedule::create([
                'dealership_id' => $this->dealership->id,
                'name' => 'Смена B',
                'sort_order' => 1,
                'start_time' => '09:00',
                'end_time' => '17:00',
                'is_active' => true,
            ]);

            Carbon::setTestNow(Carbon::parse('2026-01-31 10:00:00', 'UTC'));
            $this->settingsService->shouldReceive('getTimezone')->andReturn('+00:00');
            $this->settingsService->shouldReceive('getLateTolerance')->andReturn(15);
            $this->settingsService->shouldReceive('getShiftReminderMinutes')->andReturn(15);

            $result = $this->service->getAvailableSchedulesForNow($this->dealership->id);

            expect($result)->toHaveCount(2);
        });

        it('phase 1 (containment) is prioritized over phase 2 (tolerance)', function () {
            // Смена A: 08:00-12:00 (активна в 10:00 — phase 1)
            // Смена B: 10:05-18:00 (до начала 5 мин от 10:00 — phase 2 если не было phase 1)
            ShiftSchedule::create([
                'dealership_id' => $this->dealership->id,
                'name' => 'Смена A',
                'sort_order' => 0,
                'start_time' => '08:00',
                'end_time' => '12:00',
                'is_active' => true,
            ]);
            ShiftSchedule::create([
                'dealership_id' => $this->dealership->id,
                'name' => 'Смена B',
                'sort_order' => 1,
                'start_time' => '10:05',
                'end_time' => '18:00',
                'is_active' => true,
            ]);

            Carbon::setTestNow(Carbon::parse('2026-01-31 10:00:00', 'UTC'));
            $this->settingsService->shouldReceive('getTimezone')->andReturn('+00:00');
            $this->settingsService->shouldReceive('getLateTolerance')->andReturn(15);
            $this->settingsService->shouldReceive('getShiftReminderMinutes')->andReturn(15);

            $result = $this->service->getAvailableSchedulesForNow($this->dealership->id);

            // Phase 1 found Смена A — phase 2 is skipped
            expect($result)->toHaveCount(1)
                ->and($result->first()->name)->toBe('Смена A');
        });

        it('returns midnight-crossing schedule for time after midnight', function () {
            $schedule = ShiftSchedule::create([
                'dealership_id' => $this->dealership->id,
                'name' => 'Ночная',
                'sort_order' => 0,
                'start_time' => '22:00',
                'end_time' => '06:00',
                'is_active' => true,
            ]);

            Carbon::setTestNow(Carbon::parse('2026-02-01 03:00:00', 'UTC'));
            $this->settingsService->shouldReceive('getTimezone')->andReturn('+00:00');
            $this->settingsService->shouldReceive('getLateTolerance')->andReturn(15);
            $this->settingsService->shouldReceive('getShiftReminderMinutes')->andReturn(15);

            $result = $this->service->getAvailableSchedulesForNow($this->dealership->id);

            expect($result)->toHaveCount(1)
                ->and($result->first()->id)->toBe($schedule->id);
        });

        it('phase 2: returns schedules within tolerance before start', function () {
            ShiftSchedule::create([
                'dealership_id' => $this->dealership->id,
                'name' => 'Утренняя',
                'sort_order' => 0,
                'start_time' => '09:00',
                'end_time' => '18:00',
                'is_active' => true,
            ]);

            // 5 min before start, tolerance = 15
            Carbon::setTestNow(Carbon::parse('2026-01-31 08:55:00', 'UTC'));
            $this->settingsService->shouldReceive('getTimezone')->andReturn('+00:00');
            $this->settingsService->shouldReceive('getLateTolerance')->andReturn(15);
            $this->settingsService->shouldReceive('getShiftReminderMinutes')->andReturn(15);

            $result = $this->service->getAvailableSchedulesForNow($this->dealership->id);

            expect($result)->toHaveCount(1);
        });

        it('phase 3: returns schedule within tolerance after end', function () {
            ShiftSchedule::create([
                'dealership_id' => $this->dealership->id,
                'name' => 'Утренняя',
                'sort_order' => 0,
                'start_time' => '09:00',
                'end_time' => '18:00',
                'is_active' => true,
            ]);

            // 10 min after end, tolerance = 15
            Carbon::setTestNow(Carbon::parse('2026-01-31 18:10:00', 'UTC'));
            $this->settingsService->shouldReceive('getTimezone')->andReturn('+00:00');
            $this->settingsService->shouldReceive('getLateTolerance')->andReturn(15);
            $this->settingsService->shouldReceive('getShiftReminderMinutes')->andReturn(15);

            $result = $this->service->getAvailableSchedulesForNow($this->dealership->id);

            expect($result)->toHaveCount(1);
        });

        it('returns empty collection when no schedules match', function () {
            ShiftSchedule::create([
                'dealership_id' => $this->dealership->id,
                'name' => 'Утренняя',
                'sort_order' => 0,
                'start_time' => '09:00',
                'end_time' => '18:00',
                'is_active' => true,
            ]);

            // Far outside any schedule and tolerance
            Carbon::setTestNow(Carbon::parse('2026-01-31 20:00:00', 'UTC'));
            $this->settingsService->shouldReceive('getTimezone')->andReturn('+00:00');
            $this->settingsService->shouldReceive('getLateTolerance')->andReturn(15);
            $this->settingsService->shouldReceive('getShiftReminderMinutes')->andReturn(15);

            $result = $this->service->getAvailableSchedulesForNow($this->dealership->id);

            expect($result)->toHaveCount(0);
        });
    });

    describe('openShift with ambiguous schedules', function () {
        it('throws ScheduleAmbiguousException when multiple candidates and no schedule specified', function () {
            ShiftSchedule::create([
                'dealership_id' => $this->dealership->id,
                'name' => 'Смена A',
                'sort_order' => 0,
                'start_time' => '08:00',
                'end_time' => '16:00',
                'is_active' => true,
            ]);
            ShiftSchedule::create([
                'dealership_id' => $this->dealership->id,
                'name' => 'Смена B',
                'sort_order' => 1,
                'start_time' => '09:00',
                'end_time' => '17:00',
                'is_active' => true,
            ]);

            Carbon::setTestNow(Carbon::parse('2026-01-31 10:00:00', 'UTC'));
            $this->settingsService->shouldReceive('getTimezone')->andReturn('+00:00');
            $this->settingsService->shouldReceive('getLateTolerance')->andReturn(15);
            $this->settingsService->shouldReceive('getShiftReminderMinutes')->andReturn(15);

            expect(fn () => $this->service->openShift($this->user, UploadedFile::fake()->image('photo.jpg')))
                ->toThrow(ScheduleAmbiguousException::class);
        });

        it('opens shift with specified schedule_id when multiple candidates exist', function () {
            ShiftSchedule::create([
                'dealership_id' => $this->dealership->id,
                'name' => 'Смена A',
                'sort_order' => 0,
                'start_time' => '08:00',
                'end_time' => '16:00',
                'is_active' => true,
            ]);
            $scheduleB = ShiftSchedule::create([
                'dealership_id' => $this->dealership->id,
                'name' => 'Смена B',
                'sort_order' => 1,
                'start_time' => '09:00',
                'end_time' => '17:00',
                'is_active' => true,
            ]);

            Carbon::setTestNow(Carbon::parse('2026-01-31 10:00:00', 'UTC'));
            $this->settingsService->shouldReceive('getTimezone')->andReturn('+00:00');
            $this->settingsService->shouldReceive('getLateTolerance')->andReturn(15);
            $this->settingsService->shouldReceive('getShiftReminderMinutes')->andReturn(15);

            $shift = $this->service->openShift(
                $this->user,
                UploadedFile::fake()->image('photo.jpg'),
                null,
                null,
                null,
                $scheduleB->id
            );

            expect($shift->shift_schedule_id)->toBe($scheduleB->id);
        });

        it('throws InvalidArgumentException when specified schedule_id is not among candidates', function () {
            ShiftSchedule::create([
                'dealership_id' => $this->dealership->id,
                'name' => 'Утренняя',
                'sort_order' => 0,
                'start_time' => '09:00',
                'end_time' => '18:00',
                'is_active' => true,
            ]);

            $nightSchedule = ShiftSchedule::create([
                'dealership_id' => $this->dealership->id,
                'name' => 'Ночная',
                'sort_order' => 1,
                'start_time' => '20:00',
                'end_time' => '04:00',
                'is_active' => true,
            ]);

            // 10:00 is inside Утренняя only
            Carbon::setTestNow(Carbon::parse('2026-01-31 10:00:00', 'UTC'));
            $this->settingsService->shouldReceive('getTimezone')->andReturn('+00:00');
            $this->settingsService->shouldReceive('getLateTolerance')->andReturn(15);
            $this->settingsService->shouldReceive('getShiftReminderMinutes')->andReturn(15);

            expect(fn () => $this->service->openShift(
                $this->user,
                UploadedFile::fake()->image('photo.jpg'),
                null,
                null,
                null,
                $nightSchedule->id
            ))->toThrow(\InvalidArgumentException::class, 'недоступно');
        });

        it('getCandidates returns the ambiguous schedules in exception', function () {
            $scheduleA = ShiftSchedule::create([
                'dealership_id' => $this->dealership->id,
                'name' => 'Смена A',
                'sort_order' => 0,
                'start_time' => '08:00',
                'end_time' => '16:00',
                'is_active' => true,
            ]);
            $scheduleB = ShiftSchedule::create([
                'dealership_id' => $this->dealership->id,
                'name' => 'Смена B',
                'sort_order' => 1,
                'start_time' => '09:00',
                'end_time' => '17:00',
                'is_active' => true,
            ]);

            Carbon::setTestNow(Carbon::parse('2026-01-31 10:00:00', 'UTC'));
            $this->settingsService->shouldReceive('getTimezone')->andReturn('+00:00');
            $this->settingsService->shouldReceive('getLateTolerance')->andReturn(15);
            $this->settingsService->shouldReceive('getShiftReminderMinutes')->andReturn(15);

            try {
                $this->service->openShift($this->user, UploadedFile::fake()->image('photo.jpg'));
                expect(false)->toBeTrue('Expected exception not thrown');
            } catch (ScheduleAmbiguousException $e) {
                $candidates = $e->getCandidates();
                expect($candidates)->toHaveCount(2);
                expect($candidates->pluck('id')->sort()->values()->toArray())
                    ->toBe(collect([$scheduleA->id, $scheduleB->id])->sort()->values()->toArray());
            }
        });
    });

    describe('getShiftStatistics', function () {
        it('returns rounded average late minutes as a number', function () {
            Shift::factory()->closed()->create([
                'user_id' => $this->user->id,
                'dealership_id' => $this->dealership->id,
                'status' => 'late',
                'late_minutes' => 10,
                'shift_start' => Carbon::parse('2026-06-01 09:00:00', 'UTC'),
            ]);
            Shift::factory()->closed()->create([
                'user_id' => $this->user->id,
                'dealership_id' => $this->dealership->id,
                'status' => 'late',
                'late_minutes' => 21,
                'shift_start' => Carbon::parse('2026-06-02 09:00:00', 'UTC'),
            ]);

            $statistics = $this->service->getShiftStatistics(
                $this->dealership->id,
                Carbon::parse('2026-06-01 00:00:00', 'UTC'),
                Carbon::parse('2026-06-03 00:00:00', 'UTC'),
            );

            expect($statistics['total_shifts'])->toBe(2)
                ->and($statistics['late_shifts'])->toBe(2)
                ->and($statistics['avg_late_minutes'])->toBe(15.5);
        });
    });
});
