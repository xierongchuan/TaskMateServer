<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\AutoDealership;
use App\Models\Shift;
use App\Models\User;
use Carbon\Carbon;

describe('Shift API', function () {
    beforeEach(function () {
        $this->dealership = AutoDealership::factory()->create(['timezone' => '+00:00']);
        $this->manager = User::factory()->create(['role' => Role::MANAGER->value, 'dealership_id' => $this->dealership->id]);
        $this->owner = User::factory()->create(['role' => Role::OWNER->value]);
        $this->employee = User::factory()->create(['role' => Role::EMPLOYEE->value, 'dealership_id' => $this->dealership->id]);
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

    // ─── GET /shifts/available-schedules ───────────────────

    describe('available-schedules', function () {
        it('returns single candidate when one schedule contains current time', function () {
            Carbon::setTestNow(Carbon::parse('2026-01-31 10:00:00', 'UTC'));

            $response = $this->actingAs($this->manager, 'sanctum')
                ->getJson("/api/v1/shifts/available-schedules?dealership_id={$this->dealership->id}");

            $response->assertStatus(200);
            expect($response->json('success'))->toBeTrue();
            expect($response->json('data'))->toHaveCount(1);
        });

        it('returns multiple candidates when schedules overlap at current time', function () {
            // Add second overlapping schedule
            \App\Models\ShiftSchedule::create([
                'dealership_id' => $this->dealership->id,
                'name' => 'Смена 2',
                'sort_order' => 1,
                'start_time' => '08:00',
                'end_time' => '16:00',
                'is_active' => true,
            ]);

            // 10:00 is inside both 09:00-18:00 and 08:00-16:00
            Carbon::setTestNow(Carbon::parse('2026-01-31 10:00:00', 'UTC'));

            $response = $this->actingAs($this->manager, 'sanctum')
                ->getJson("/api/v1/shifts/available-schedules?dealership_id={$this->dealership->id}");

            $response->assertStatus(200);
            expect($response->json('data'))->toHaveCount(2);
        });

        it('returns empty when no schedule matches current time', function () {
            // Current time is well outside tolerance of 09:00-18:00
            Carbon::setTestNow(Carbon::parse('2026-01-31 19:30:00', 'UTC'));

            $response = $this->actingAs($this->manager, 'sanctum')
                ->getJson("/api/v1/shifts/available-schedules?dealership_id={$this->dealership->id}");

            $response->assertStatus(200);
            expect($response->json('data'))->toHaveCount(0);
        });

        it('requires dealership_id parameter', function () {
            $response = $this->actingAs($this->manager, 'sanctum')
                ->getJson('/api/v1/shifts/available-schedules');

            $response->assertStatus(422);
        });
    });

    // ─── POST /shifts with overlap logic ───────────────────

    describe('store with overlapping schedules', function () {
        it('auto-resolves when single candidate exists', function () {
            // 09:00-18:00 is the only schedule, time is inside it
            Carbon::setTestNow(Carbon::parse('2026-01-31 10:00:00', 'UTC'));
            $user = User::factory()->create(['role' => Role::EMPLOYEE->value, 'dealership_id' => $this->dealership->id]);
            $file = \Illuminate\Http\Testing\File::image('photo.jpg');

            $response = $this->actingAs($this->owner, 'sanctum')
                ->postJson('/api/v1/shifts', [
                    'dealership_id' => $this->dealership->id,
                    'user_id' => $user->id,
                    'opening_photo' => $file,
                ]);

            $response->assertStatus(201);
        });

        it('returns 409 with candidates when multiple schedules overlap', function () {
            \App\Models\ShiftSchedule::create([
                'dealership_id' => $this->dealership->id,
                'name' => 'Смена 2',
                'sort_order' => 1,
                'start_time' => '08:00',
                'end_time' => '16:00',
                'is_active' => true,
            ]);

            Carbon::setTestNow(Carbon::parse('2026-01-31 10:00:00', 'UTC'));
            $user = User::factory()->create(['role' => Role::EMPLOYEE->value, 'dealership_id' => $this->dealership->id]);
            $file = \Illuminate\Http\Testing\File::image('photo.jpg');

            $response = $this->actingAs($this->owner, 'sanctum')
                ->postJson('/api/v1/shifts', [
                    'dealership_id' => $this->dealership->id,
                    'user_id' => $user->id,
                    'opening_photo' => $file,
                ]);

            $response->assertStatus(409);
            expect($response->json('error_code'))->toBe('schedule_ambiguous');
            expect($response->json('candidates'))->toHaveCount(2);
        });

        it('opens shift with explicit shift_schedule_id when multiple schedules overlap', function () {
            $schedule2 = \App\Models\ShiftSchedule::create([
                'dealership_id' => $this->dealership->id,
                'name' => 'Смена 2',
                'sort_order' => 1,
                'start_time' => '08:00',
                'end_time' => '16:00',
                'is_active' => true,
            ]);

            Carbon::setTestNow(Carbon::parse('2026-01-31 10:00:00', 'UTC'));
            $user = User::factory()->create(['role' => Role::EMPLOYEE->value, 'dealership_id' => $this->dealership->id]);
            $file = \Illuminate\Http\Testing\File::image('photo.jpg');

            $response = $this->actingAs($this->owner, 'sanctum')
                ->postJson('/api/v1/shifts', [
                    'dealership_id' => $this->dealership->id,
                    'user_id' => $user->id,
                    'opening_photo' => $file,
                    'shift_schedule_id' => $schedule2->id,
                ]);

            $response->assertStatus(201);
            $this->assertDatabaseHas('shifts', [
                'user_id' => $user->id,
                'shift_schedule_id' => $schedule2->id,
            ]);
        });

        it('returns 400 when shift_schedule_id is not among candidates', function () {
            // Only one overlapping schedule at current time
            Carbon::setTestNow(Carbon::parse('2026-01-31 10:00:00', 'UTC'));
            $user = User::factory()->create(['role' => Role::EMPLOYEE->value, 'dealership_id' => $this->dealership->id]);
            $file = \Illuminate\Http\Testing\File::image('photo.jpg');

            // Create a schedule that is NOT active at current time (20:00-04:00)
            $nightSchedule = \App\Models\ShiftSchedule::create([
                'dealership_id' => $this->dealership->id,
                'name' => 'Ночная',
                'sort_order' => 1,
                'start_time' => '20:00',
                'end_time' => '04:00',
                'is_active' => true,
            ]);

            $response = $this->actingAs($this->owner, 'sanctum')
                ->postJson('/api/v1/shifts', [
                    'dealership_id' => $this->dealership->id,
                    'user_id' => $user->id,
                    'opening_photo' => $file,
                    'shift_schedule_id' => $nightSchedule->id,
                ]);

            $response->assertStatus(400);
        });
    });
});
