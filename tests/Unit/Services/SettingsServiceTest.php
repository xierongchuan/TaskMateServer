<?php

declare(strict_types=1);

use App\Models\AutoDealership;
use App\Models\Setting;
use App\Services\SettingsService;

describe('SettingsService', function () {
    beforeEach(function () {
        $this->service = new SettingsService;
    });

    it('gets global setting', function () {
        Setting::create(['key' => 'test_key', 'value' => 'test_value']);

        $value = $this->service->get('test_key');

        expect($value)->toBe('test_value');
    });

    it('gets dealership setting', function () {
        $dealership = AutoDealership::factory()->create();
        Setting::create([
            'key' => 'test_key',
            'value' => 'dealership_value',
            'dealership_id' => $dealership->id,
        ]);

        $value = $this->service->get('test_key', $dealership->id);

        expect($value)->toBe('dealership_value');
    });

    it('falls back to global setting', function () {
        $dealership = AutoDealership::factory()->create();
        Setting::create(['key' => 'test_key', 'value' => 'global_value']);

        $value = $this->service->getSettingWithFallback('test_key', $dealership->id);

        expect($value)->toBe('global_value');
    });

    it('sets setting', function () {
        $this->service->set('new_key', 'new_value');

        $this->assertDatabaseHas('settings', ['key' => 'new_key', 'value' => 'new_value']);
    });
});
