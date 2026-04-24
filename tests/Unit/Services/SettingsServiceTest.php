<?php

declare(strict_types=1);

use App\Models\AutoDealership;
use App\Models\NotificationSetting;
use App\Models\Setting;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Cache;

describe('SettingsService', function () {
    beforeEach(function () {
        $this->service = new SettingsService;
        Cache::flush();
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

    it('gets multiple settings with fallback and defaults', function () {
        $dealership = AutoDealership::factory()->create();
        Setting::create(['key' => 'global_only', 'value' => 'global']);
        Setting::create([
            'key' => 'dealership_only',
            'value' => 'local',
            'dealership_id' => $dealership->id,
        ]);

        $settings = $this->service->getMultipleSettingsWithFallback(
            ['global_only', 'dealership_only', 'missing_key'],
            $dealership->id,
            ['missing_key' => 'default']
        );

        expect($settings['global_only'])->toBe('global');
        expect($settings['dealership_only'])->toBe('local');
        expect($settings['missing_key'])->toBe('default');
    });

    it('returns notification channel helpers correctly', function () {
        $dealership = AutoDealership::factory()->create();

        NotificationSetting::create([
            'dealership_id' => $dealership->id,
            'channel_type' => NotificationSetting::CHANNEL_WEEKLY_REPORT,
            'is_enabled' => true,
            'notification_time' => '09:00',
            'notification_offset' => 60,
            'recipient_roles' => ['manager', 'owner'],
        ]);

        expect(NotificationSetting::isChannelEnabled($dealership->id, NotificationSetting::CHANNEL_WEEKLY_REPORT))->toBeTrue();
        expect(NotificationSetting::getNotificationTime($dealership->id, NotificationSetting::CHANNEL_WEEKLY_REPORT))->toBe('09:00:00');
        expect(NotificationSetting::getNotificationOffset($dealership->id, NotificationSetting::CHANNEL_WEEKLY_REPORT))->toBe(60);
        expect(NotificationSetting::getRecipientRoles($dealership->id, NotificationSetting::CHANNEL_WEEKLY_REPORT))->toBe(['manager', 'owner']);
    });
});
