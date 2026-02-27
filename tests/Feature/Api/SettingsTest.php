<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Setting;
use App\Models\AutoDealership;
use App\Enums\Role;
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
});
