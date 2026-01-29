<?php

declare(strict_types=1);

use App\Jobs\AutoCloseShiftsJob;
use App\Models\AutoDealership;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\User;
use App\Enums\Role;
use Carbon\Carbon;

describe('AutoCloseShiftsJob', function () {
    beforeEach(function () {
        $this->dealership = AutoDealership::factory()->create();
        $this->employee = User::factory()->create([
            'role' => Role::EMPLOYEE->value,
            'dealership_id' => $this->dealership->id,
        ]);
    });

    it('closes open shifts past scheduled_end when auto_close_shifts is enabled', function () {
        Setting::factory()->create([
            'dealership_id' => $this->dealership->id,
            'key' => 'auto_close_shifts',
            'type' => 'boolean',
            'value' => '1',
        ]);

        $shift = Shift::factory()->create([
            'user_id' => $this->employee->id,
            'dealership_id' => $this->dealership->id,
            'shift_start' => Carbon::now()->subHours(10),
            'scheduled_end' => Carbon::now()->subHour(),
            'status' => 'open',
            'shift_end' => null,
        ]);

        (new AutoCloseShiftsJob())->handle(
            app(\App\Services\SettingsService::class),
            app(\App\Services\ShiftService::class),
        );

        $shift->refresh();
        expect($shift->status)->toBe('closed');
        expect($shift->shift_end)->not->toBeNull();
    });

    it('does not close shifts when auto_close_shifts is disabled', function () {
        Setting::factory()->create([
            'dealership_id' => $this->dealership->id,
            'key' => 'auto_close_shifts',
            'type' => 'boolean',
            'value' => '0',
        ]);

        $shift = Shift::factory()->create([
            'user_id' => $this->employee->id,
            'dealership_id' => $this->dealership->id,
            'shift_start' => Carbon::now()->subHours(10),
            'scheduled_end' => Carbon::now()->subHour(),
            'status' => 'open',
            'shift_end' => null,
        ]);

        (new AutoCloseShiftsJob())->handle(
            app(\App\Services\SettingsService::class),
            app(\App\Services\ShiftService::class),
        );

        $shift->refresh();
        expect($shift->status)->toBe('open');
        expect($shift->shift_end)->toBeNull();
    });

    it('does not close shifts when scheduled_end is in the future', function () {
        Setting::factory()->create([
            'dealership_id' => $this->dealership->id,
            'key' => 'auto_close_shifts',
            'type' => 'boolean',
            'value' => '1',
        ]);

        $shift = Shift::factory()->create([
            'user_id' => $this->employee->id,
            'dealership_id' => $this->dealership->id,
            'shift_start' => Carbon::now()->subHours(2),
            'scheduled_end' => Carbon::now()->addHour(),
            'status' => 'open',
            'shift_end' => null,
        ]);

        (new AutoCloseShiftsJob())->handle(
            app(\App\Services\SettingsService::class),
            app(\App\Services\ShiftService::class),
        );

        $shift->refresh();
        expect($shift->status)->toBe('open');
        expect($shift->shift_end)->toBeNull();
    });

    it('ignores already closed shifts', function () {
        Setting::factory()->create([
            'dealership_id' => $this->dealership->id,
            'key' => 'auto_close_shifts',
            'type' => 'boolean',
            'value' => '1',
        ]);

        $shift = Shift::factory()->closed()->create([
            'user_id' => $this->employee->id,
            'dealership_id' => $this->dealership->id,
        ]);

        $originalEnd = $shift->shift_end;

        (new AutoCloseShiftsJob())->handle(
            app(\App\Services\SettingsService::class),
            app(\App\Services\ShiftService::class),
        );

        $shift->refresh();
        expect($shift->status)->toBe('closed');
        expect($shift->shift_end->toIso8601ZuluString())->toBe($originalEnd->toIso8601ZuluString());
    });

    it('closes late shifts past scheduled_end', function () {
        Setting::factory()->create([
            'dealership_id' => $this->dealership->id,
            'key' => 'auto_close_shifts',
            'type' => 'boolean',
            'value' => '1',
        ]);

        $shift = Shift::factory()->late()->create([
            'user_id' => $this->employee->id,
            'dealership_id' => $this->dealership->id,
            'shift_start' => Carbon::now()->subHours(10),
            'scheduled_end' => Carbon::now()->subHour(),
            'shift_end' => null,
        ]);

        (new AutoCloseShiftsJob())->handle(
            app(\App\Services\SettingsService::class),
            app(\App\Services\ShiftService::class),
        );

        $shift->refresh();
        expect($shift->status)->toBe('closed');
        expect($shift->shift_end)->not->toBeNull();
    });
});
