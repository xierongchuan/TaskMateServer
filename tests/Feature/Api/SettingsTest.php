<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\AutoDealership;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

describe('Settings API', function () {
    beforeEach(function () {
        $this->manager = User::factory()->create(['role' => Role::OWNER->value]);
        $this->dealership = AutoDealership::factory()->create();
        Cache::flush();
    });

    it('returns all settings', function () {
        // Arrange
        Setting::factory()->create(['key' => 'site_name', 'value' => 'TaskMate']);

        // Act
        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/settings');

        // Assert
        $response->assertStatus(200);
        expect($response->json())->toBeArray();
    });

    it('updates settings', function () {
        // Arrange
        Setting::factory()->create(['key' => 'site_name', 'value' => 'Old Name']);

        // Act
        $response = $this->actingAs($this->manager, 'sanctum')
            ->putJson('/api/v1/settings/site_name', ['value' => 'New Name']);

        // Assert
        $response->assertStatus(200);
        $this->assertDatabaseHas('settings', ['key' => 'site_name', 'value' => 'New Name']);
    });

    it('returns specific setting', function () {
        // Arrange
        Setting::factory()->create(['key' => 'specific_key', 'value' => 'specific_value']);

        // Act
        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/settings/specific_key');

        // Assert
        $response->assertStatus(200)
            ->assertJsonPath('data.value', 'specific_value');
    });

    it('returns notification config with dealership fallback', function () {
        Setting::factory()->create([
            'key' => 'rows_per_page',
            'value' => '25',
            'type' => 'integer',
            'dealership_id' => null,
        ]);
        Setting::factory()->create([
            'key' => 'notification_enabled',
            'value' => '0',
            'type' => 'boolean',
            'dealership_id' => $this->dealership->id,
        ]);

        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/settings/notification-config?dealership_id={$this->dealership->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.notification_enabled', false)
            ->assertJsonPath('data.rows_per_page', 25);
    });

    it('returns archive config defaults when settings are missing', function () {
        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/settings/archive-config?dealership_id={$this->dealership->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.archive_completed_time', '03:00')
            ->assertJsonPath('data.archive_overdue_day_of_week', 0)
            ->assertJsonPath('data.archive_overdue_time', '03:00');
    });

    it('returns task config with dealership and default fallback', function () {
        Setting::factory()->create([
            'key' => 'archive_overdue_hours_after_shift',
            'value' => '6',
            'type' => 'integer',
            'dealership_id' => null,
        ]);
        Setting::factory()->create([
            'key' => 'task_requires_open_shift',
            'value' => '1',
            'type' => 'boolean',
            'dealership_id' => $this->dealership->id,
        ]);

        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/settings/task-config?dealership_id={$this->dealership->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.task_requires_open_shift', true)
            ->assertJsonPath('data.archive_overdue_hours_after_shift', 6);
    });
});
