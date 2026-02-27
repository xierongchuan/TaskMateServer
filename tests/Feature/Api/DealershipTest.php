<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\AutoDealership;
use App\Enums\Role;

describe('Dealership API', function () {
    beforeEach(function () {
        $this->manager = User::factory()->create(['role' => Role::OWNER->value]);
    });

    it('returns list of dealerships', function () {
        // Arrange
        // Clear existing dealerships or just count them
        $initialCount = AutoDealership::count();
        AutoDealership::factory(3)->create();

        // Act
        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/dealerships');

        // Assert
        $response->assertStatus(200);
        // We expect at least 3, or initial + 3 if pagination allows
        // Since pagination is 15, if initial + 3 <= 15, we get all.
        // If > 15, we get 15.
        $expectedCount = min($initialCount + 3, 15);
        expect($response->json('data'))->toHaveCount($expectedCount);
    });

    it('returns single dealership', function () {
        // Arrange
        $dealership = AutoDealership::factory()->create();

        // Act
        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/dealerships/{$dealership->id}");

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'id' => $dealership->id,
                'name' => $dealership->name,
            ]);
    });

    it('creates new dealership', function () {
        // Arrange
        $data = [
            'name' => 'New Dealership',
            'address' => '123 Main St',
        ];

        // Act
        $response = $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/v1/dealerships', $data);

        // Assert
        $response->assertStatus(201);
        $this->assertDatabaseHas('auto_dealerships', ['name' => 'New Dealership']);
    });

    it('updates dealership', function () {
        // Arrange
        $dealership = AutoDealership::factory()->create();
        $data = ['name' => 'Updated Name'];

        // Act
        $response = $this->actingAs($this->manager, 'sanctum')
            ->putJson("/api/v1/dealerships/{$dealership->id}", $data);

        // Assert
        $response->assertStatus(200);
        $this->assertDatabaseHas('auto_dealerships', ['id' => $dealership->id, 'name' => 'Updated Name']);
    });

    it('deletes dealership', function () {
        // Arrange
        $dealership = AutoDealership::factory()->create();

        // Act
        $response = $this->actingAs($this->manager, 'sanctum')
            ->deleteJson("/api/v1/dealerships/{$dealership->id}");

        // Assert
        $response->assertStatus(200);
        $this->assertDatabaseMissing('auto_dealerships', ['id' => $dealership->id]);
    });
});
