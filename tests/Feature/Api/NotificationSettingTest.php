<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\AutoDealership;
use App\Models\NotificationSetting;
use App\Enums\Role;

describe('Notification Settings API', function () {
    beforeEach(function () {
        $this->dealership = AutoDealership::factory()->create();
        $this->manager = User::factory()->create([
            'role' => Role::MANAGER->value,
            'dealership_id' => $this->dealership->id
        ]);
    });

    it('returns settings for a dealership', function () {
        // Arrange
        NotificationSetting::create([
            'dealership_id' => $this->dealership->id,
            'channel_type' => 'task_assigned',
            'is_enabled' => true,
        ]);

        // Act
        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/notification-settings?dealership_id={$this->dealership->id}");

        // Assert
        $response->assertStatus(200);
        expect($response->json('data'))->toBeArray();
    });

    it('updates settings', function () {
        // Arrange
        $setting = NotificationSetting::factory()->create([
            'dealership_id' => $this->dealership->id,
            'channel_type' => 'task_assigned',
            'is_enabled' => true,
        ]);

        $data = [
            'is_enabled' => false,
        ];

        // Act
        $response = $this->actingAs($this->manager, 'sanctum')
            ->putJson("/api/v1/notification-settings/task_assigned", $data);

        // Assert
        $response->assertStatus(200);
        $this->assertDatabaseHas('notification_settings', [
            'dealership_id' => $this->dealership->id,
            'channel_type' => 'task_assigned',
            'is_enabled' => false,
        ]);
    });
});
