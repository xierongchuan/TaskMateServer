<?php

declare(strict_types=1);

use App\Models\Setting;
use App\Models\AutoDealership;

describe('Setting Model', function () {
    it('can create a setting with all fields', function () {
        // Act
        $setting = Setting::create([
            'key' => 'app_name',
            'value' => 'TaskMate',
            'type' => 'string',
            'description' => 'Application name',
        ]);

        // Assert
        expect($setting)
            ->toBeInstanceOf(Setting::class)
            ->and($setting->key)->toBe('app_name')
            ->and($setting->value)->toBe('TaskMate')
            ->and($setting->type)->toBe('string')
            ->and($setting->exists)->toBeTrue();
    });

    it('can create setting with minimum required fields', function () {
        // Act
        $setting = Setting::create([
            'key' => 'min_setting',
            'value' => 'value',
            'type' => 'string',
        ]);

        // Assert
        expect($setting)
            ->toBeInstanceOf(Setting::class)
            ->and($setting->exists)->toBeTrue();
    });

    it('can handle string type', function () {
        // Act
        $setting = Setting::create([
            'key' => 'string_setting',
            'value' => 'string_value',
            'type' => 'string',
        ]);

        // Assert
        expect($setting->type)->toBe('string')
            ->and($setting->value)->toBeString();
    });

    it('can handle integer type', function () {
        // Act
        $setting = Setting::create([
            'key' => 'int_setting',
            'value' => '42',
            'type' => 'integer',
        ]);

        // Assert
        expect($setting->type)->toBe('integer');
    });

    it('can handle boolean type', function () {
        // Act
        $setting = Setting::create([
            'key' => 'bool_setting',
            'value' => 'true',
            'type' => 'boolean',
        ]);

        // Assert
        expect($setting->type)->toBe('boolean');
    });

    it('can handle json type', function () {
        // Act
        $setting = Setting::create([
            'key' => 'json_setting',
            'value' => json_encode(['key' => 'value']),
            'type' => 'json',
        ]);

        // Assert
        expect($setting->type)->toBe('json')
            ->and($setting->value)->toBeString();
    });

    it('can handle time type', function () {
        // Act
        $setting = Setting::create([
            'key' => 'time_setting',
            'value' => '09:00',
            'type' => 'time',
        ]);

        // Assert
        expect($setting->type)->toBe('time')
            ->and($setting->value)->toBe('09:00');
    });

    it('can be global or dealership-specific', function () {
        // Act
        $globalSetting = Setting::create([
            'key' => 'global_setting',
            'value' => 'global',
            'type' => 'string',
            'dealership_id' => null,
        ]);

        $dealership = AutoDealership::factory()->create();
        $localSetting = Setting::create([
            'key' => 'local_setting',
            'value' => 'local',
            'type' => 'string',
            'dealership_id' => $dealership->id,
        ]);

        // Assert
        expect($globalSetting->dealership_id)->toBeNull()
            ->and($localSetting->dealership_id)->toBe($dealership->id);
    });

    it('can query settings by key', function () {
        // Arrange
        Setting::create([
            'key' => 'app_name',
            'value' => 'TaskMate',
            'type' => 'string',
        ]);

        // Act
        $setting = Setting::where('key', 'app_name')->first();

        // Assert
        expect($setting)->not->toBeNull()
            ->and($setting->value)->toBe('TaskMate');
    });

    it('can update setting value', function () {
        // Arrange
        $setting = Setting::create([
            'key' => 'test_setting',
            'value' => 'old_value',
            'type' => 'string',
        ]);

        // Act
        $setting->update(['value' => 'new_value']);

        // Assert
        expect($setting->value)->toBe('new_value');

        $this->assertDatabaseHas('settings', [
            'key' => 'test_setting',
            'value' => 'new_value',
        ]);
    });

    it('can delete setting', function () {
        // Arrange
        $setting = Setting::create([
            'key' => 'delete_me',
            'value' => 'will_be_deleted',
            'type' => 'string',
        ]);

        // Act
        $setting->delete();

        // Assert
        $this->assertDatabaseMissing('settings', ['key' => 'delete_me']);
    });

    it('can query settings by dealership', function () {
        // Arrange
        $dealership = AutoDealership::factory()->create();
        Setting::factory(3)->create(['dealership_id' => $dealership->id]);
        Setting::factory(2)->create(['dealership_id' => null]);

        // Act
        $dealershipSettings = Setting::where('dealership_id', $dealership->id)->get();

        // Assert
        expect($dealershipSettings)->toHaveCount(3);
    });

    it('stores and retrieves type correctly', function () {
        // Arrange
        $types = ['string', 'integer', 'boolean', 'json', 'time'];

        // Act
        foreach ($types as $type) {
            $setting = Setting::create([
                'key' => "test_{$type}",
                'value' => 'test_value',
                'type' => $type,
            ]);

            // Assert
            expect($setting->type)->toBe($type);
        }
    });

    it('can store description', function () {
        // Act
        $setting = Setting::create([
            'key' => 'documented_setting',
            'value' => 'value',
            'type' => 'string',
            'description' => 'This is a documented setting for system configuration',
        ]);

        // Assert
        expect($setting->description)
            ->toBe('This is a documented setting for system configuration');
    });
});
