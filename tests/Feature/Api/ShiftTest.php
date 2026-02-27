<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Shift;
use App\Models\AutoDealership;
use App\Enums\Role;
use Carbon\Carbon;

describe('Shift API', function () {
    beforeEach(function () {
        $this->manager = User::factory()->create(['role' => Role::MANAGER->value]);
        $this->owner = User::factory()->create(['role' => Role::OWNER->value]);
        $this->employee = User::factory()->create(['role' => Role::EMPLOYEE->value]);
        $this->dealership = AutoDealership::factory()->create(['timezone' => '+00:00']);
        \App\Models\ShiftSchedule::create([
            'dealership_id' => $this->dealership->id,
            'name' => 'Смена 1',
            'sort_order' => 0,
            'start_time' => '09:00',
            'end_time' => '18:00',
            'is_active' => true,
        ]);
        \Illuminate\Support\Facades\Storage::fake('shift_photos');
    });

    it('returns shifts list', function () {
        // Arrange
        Shift::factory(3)->create(['dealership_id' => $this->dealership->id]);

        // Act
        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/shifts?dealership_id={$this->dealership->id}");

        // Assert
        $response->assertStatus(200);
        expect($response->json('data'))->toHaveCount(3);
    });

    it('owner can start a shift via API', function () {
        // Arrange
        Carbon::setTestNow(Carbon::parse('09:00:00'));
        $user = User::factory()->create(['role' => Role::EMPLOYEE->value, 'dealership_id' => $this->dealership->id]);
        $file = \Illuminate\Http\Testing\File::image('photo.jpg');

        // Act - Owner opening shift for employee
        $response = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/v1/shifts', [
                'dealership_id' => $this->dealership->id,
                'user_id' => $user->id,
                'opening_photo' => $file,
            ]);

        // Assert
        $response->assertStatus(201);
        $this->assertDatabaseHas('shifts', [
            'user_id' => $user->id,
            'dealership_id' => $this->dealership->id,
            'status' => 'open',
        ]);
    });

    it('employee can start their own shift via API', function () {
        // Arrange
        Carbon::setTestNow(Carbon::parse('09:00:00'));
        $user = User::factory()->create(['role' => Role::EMPLOYEE->value, 'dealership_id' => $this->dealership->id]);
        $file = \Illuminate\Http\Testing\File::image('photo.jpg');

        // Act - Employee opening their own shift via API
        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/shifts', [
                'dealership_id' => $this->dealership->id,
                'user_id' => $user->id,
                'opening_photo' => $file,
            ]);

        // Assert - Should be allowed (employees can open their own shifts)
        $response->assertStatus(201);
        $this->assertDatabaseHas('shifts', [
            'user_id' => $user->id,
            'dealership_id' => $this->dealership->id,
            'status' => 'open',
        ]);
    });

    it('employee cannot start a shift for another user via API', function () {
        // Arrange
        Carbon::setTestNow(Carbon::parse('09:00:00'));
        $employee1 = User::factory()->create(['role' => Role::EMPLOYEE->value, 'dealership_id' => $this->dealership->id]);
        $employee2 = User::factory()->create(['role' => Role::EMPLOYEE->value, 'dealership_id' => $this->dealership->id]);
        $file = \Illuminate\Http\Testing\File::image('photo.jpg');

        // Act - Employee trying to open shift for another employee (should be denied)
        $response = $this->actingAs($employee1, 'sanctum')
            ->postJson('/api/v1/shifts', [
                'dealership_id' => $this->dealership->id,
                'user_id' => $employee2->id,
                'opening_photo' => $file,
            ]);

        // Assert - Should be forbidden
        $response->assertStatus(403);
    });

    it('owner can end a shift via API', function () {
        // Arrange
        $user = User::factory()->create(['role' => Role::EMPLOYEE->value, 'dealership_id' => $this->dealership->id]);
        $shift = Shift::factory()->create([
            'user_id' => $user->id,
            'dealership_id' => $this->dealership->id,
            'status' => 'open',
            'shift_start' => Carbon::now()->subHours(8),
        ]);
        $file = \Illuminate\Http\Testing\File::image('closing.jpg');

        // Act - Owner closing shift
        $response = $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/v1/shifts/{$shift->id}", [
                'closing_photo' => $file,
                'status' => 'closed',
            ]);

        // Assert
        $response->assertStatus(200);
        $this->assertDatabaseHas('shifts', [
            'id' => $shift->id,
            'status' => 'closed',
        ]);
    });

    it('employee can end their own shift via API', function () {
        // Arrange
        $user = User::factory()->create(['role' => Role::EMPLOYEE->value, 'dealership_id' => $this->dealership->id]);
        $shift = Shift::factory()->create([
            'user_id' => $user->id,
            'dealership_id' => $this->dealership->id,
            'status' => 'open',
            'shift_start' => Carbon::now()->subHours(8),
        ]);
        $file = \Illuminate\Http\Testing\File::image('closing.jpg');

        // Act - Employee closing their own shift via API
        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/shifts/{$shift->id}", [
                'closing_photo' => $file,
                'status' => 'closed',
            ]);

        // Assert - Should be allowed (employees can close their own shifts)
        $response->assertStatus(200);
        $this->assertDatabaseHas('shifts', [
            'id' => $shift->id,
            'status' => 'closed',
        ]);
    });

    it('employee cannot end a shift of another user via API', function () {
        // Arrange
        $employee1 = User::factory()->create(['role' => Role::EMPLOYEE->value, 'dealership_id' => $this->dealership->id]);
        $employee2 = User::factory()->create(['role' => Role::EMPLOYEE->value, 'dealership_id' => $this->dealership->id]);
        $shift = Shift::factory()->create([
            'user_id' => $employee2->id,
            'dealership_id' => $this->dealership->id,
            'status' => 'open',
            'shift_start' => Carbon::now()->subHours(8),
        ]);
        $file = \Illuminate\Http\Testing\File::image('closing.jpg');

        // Act - Employee trying to close another employee's shift (should be denied)
        $response = $this->actingAs($employee1, 'sanctum')
            ->putJson("/api/v1/shifts/{$shift->id}", [
                'closing_photo' => $file,
                'status' => 'closed',
            ]);

        // Assert - Should be forbidden
        $response->assertStatus(403);
    });
});

