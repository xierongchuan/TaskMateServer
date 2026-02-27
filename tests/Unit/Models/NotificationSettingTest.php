<?php

declare(strict_types=1);

use App\Models\NotificationSetting;
use App\Models\AutoDealership;

describe('NotificationSetting Model', function () {
    it('belongs to dealership', function () {
        $dealership = AutoDealership::factory()->create();
        $setting = NotificationSetting::factory()->create(['dealership_id' => $dealership->id]);

        expect($setting->dealership->id)->toBe($dealership->id);
    });

    it('has channel label helper', function () {
        expect(NotificationSetting::getChannelLabel('task_assigned'))->toBe('Назначение задачи');
    });
});
