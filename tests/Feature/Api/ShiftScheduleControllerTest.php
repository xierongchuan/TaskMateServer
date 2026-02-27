<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\AutoDealership;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\User;

describe('ShiftSchedule API', function () {
    beforeEach(function () {
        $this->dealership = AutoDealership::factory()->create(['timezone' => '+00:00']);
        $this->owner = User::factory()->create([
            'role' => Role::OWNER->value,
            'dealership_id' => $this->dealership->id,
        ]);
        $this->manager = User::factory()->create([
            'role' => Role::MANAGER->value,
            'dealership_id' => $this->dealership->id,
        ]);
        $this->employee = User::factory()->create([
            'role' => Role::EMPLOYEE->value,
            'dealership_id' => $this->dealership->id,
        ]);
        $this->observer = User::factory()->create([
            'role' => Role::OBSERVER->value,
            'dealership_id' => $this->dealership->id,
        ]);
        $this->schedule = ShiftSchedule::create([
            'dealership_id' => $this->dealership->id,
            'name' => 'Смена 1',
            'sort_order' => 0,
            'start_time' => '09:00',
            'end_time' => '18:00',
            'is_active' => true,
        ]);
    });

    // ─── INDEX ─────────────────────────────────────────────

    describe('index', function () {
        it('returns schedules list', function () {
            $response = $this->actingAs($this->employee, 'sanctum')
                ->getJson("/api/v1/shift-schedules?dealership_id={$this->dealership->id}");

            $response->assertStatus(200);
            expect($response->json('success'))->toBeTrue();
            expect($response->json('data'))->toHaveCount(1);
        });

        it('filters by dealership_id', function () {
            $other = AutoDealership::factory()->create();
            ShiftSchedule::create([
                'dealership_id' => $other->id,
                'name' => 'Другая',
                'sort_order' => 0,
                'start_time' => '10:00',
                'end_time' => '19:00',
                'is_active' => true,
            ]);

            $response = $this->actingAs($this->employee, 'sanctum')
                ->getJson("/api/v1/shift-schedules?dealership_id={$this->dealership->id}");

            expect($response->json('data'))->toHaveCount(1);
        });

        it('filters by active_only', function () {
            ShiftSchedule::create([
                'dealership_id' => $this->dealership->id,
                'name' => 'Неактивная',
                'sort_order' => 1,
                'start_time' => '20:00',
                'end_time' => '04:00',
                'is_active' => false,
            ]);

            $response = $this->actingAs($this->employee, 'sanctum')
                ->getJson("/api/v1/shift-schedules?dealership_id={$this->dealership->id}&active_only=true");

            expect($response->json('data'))->toHaveCount(1);
        });

        it('excludes soft-deleted schedules', function () {
            $this->schedule->delete();

            $response = $this->actingAs($this->employee, 'sanctum')
                ->getJson("/api/v1/shift-schedules?dealership_id={$this->dealership->id}");

            expect($response->json('data'))->toHaveCount(0);
        });

        it('orders by sort_order', function () {
            ShiftSchedule::create([
                'dealership_id' => $this->dealership->id,
                'name' => 'Первая',
                'sort_order' => -1,
                'start_time' => '20:00',
                'end_time' => '04:00',
                'is_active' => true,
            ]);

            $response = $this->actingAs($this->employee, 'sanctum')
                ->getJson("/api/v1/shift-schedules?dealership_id={$this->dealership->id}");

            $data = $response->json('data');
            expect($data[0]['name'])->toBe('Первая');
        });

        it('requires authentication', function () {
            $this->getJson('/api/v1/shift-schedules')->assertStatus(401);
        });
    });

    // ─── SHOW ──────────────────────────────────────────────

    describe('show', function () {
        it('returns schedule with computed fields', function () {
            $night = ShiftSchedule::create([
                'dealership_id' => $this->dealership->id,
                'name' => 'Ночная',
                'sort_order' => 1,
                'start_time' => '22:00',
                'end_time' => '06:00',
                'is_active' => true,
            ]);

            $response = $this->actingAs($this->employee, 'sanctum')
                ->getJson("/api/v1/shift-schedules/{$night->id}");

            $response->assertStatus(200);
            expect($response->json('data.crosses_midnight'))->toBeTrue();
            expect($response->json('data.is_night_shift'))->toBeTrue();
        });

        it('returns 404 for soft-deleted schedule', function () {
            $id = $this->schedule->id;
            $this->schedule->delete();

            $this->actingAs($this->employee, 'sanctum')
                ->getJson("/api/v1/shift-schedules/{$id}")
                ->assertStatus(404);
        });
    });

    // ─── STORE ─────────────────────────────────────────────

    describe('store', function () {
        it('creates schedule as manager', function () {
            $response = $this->actingAs($this->manager, 'sanctum')
                ->postJson('/api/v1/shift-schedules', [
                    'dealership_id' => $this->dealership->id,
                    'name' => 'Вечерняя',
                    'start_time' => '19:00',
                    'end_time' => '23:00',
                ]);

            $response->assertStatus(201);
            expect($response->json('data.name'))->toBe('Вечерняя');
        });

        it('creates schedule as owner', function () {
            $this->actingAs($this->owner, 'sanctum')
                ->postJson('/api/v1/shift-schedules', [
                    'dealership_id' => $this->dealership->id,
                    'name' => 'Вечерняя',
                    'start_time' => '19:00',
                    'end_time' => '23:00',
                ])->assertStatus(201);
        });

        it('forbidden for employee', function () {
            $this->actingAs($this->employee, 'sanctum')
                ->postJson('/api/v1/shift-schedules', [
                    'dealership_id' => $this->dealership->id,
                    'name' => 'Test',
                    'start_time' => '19:00',
                    'end_time' => '23:00',
                ])->assertStatus(403);
        });

        it('forbidden for observer', function () {
            $this->actingAs($this->observer, 'sanctum')
                ->postJson('/api/v1/shift-schedules', [
                    'dealership_id' => $this->dealership->id,
                    'name' => 'Test',
                    'start_time' => '19:00',
                    'end_time' => '23:00',
                ])->assertStatus(403);
        });

        it('validates time format', function () {
            $this->actingAs($this->manager, 'sanctum')
                ->postJson('/api/v1/shift-schedules', [
                    'dealership_id' => $this->dealership->id,
                    'name' => 'Bad',
                    'start_time' => '25:00',
                    'end_time' => '18:00',
                ])->assertStatus(422);
        });

        it('enforces name uniqueness per dealership', function () {
            $this->actingAs($this->manager, 'sanctum')
                ->postJson('/api/v1/shift-schedules', [
                    'dealership_id' => $this->dealership->id,
                    'name' => 'Смена 1', // already exists
                    'start_time' => '19:00',
                    'end_time' => '23:00',
                ])->assertStatus(422);
        });

        it('allows same name in different dealership', function () {
            $other = AutoDealership::factory()->create();
            $this->actingAs($this->manager, 'sanctum')
                ->postJson('/api/v1/shift-schedules', [
                    'dealership_id' => $other->id,
                    'name' => 'Смена 1',
                    'start_time' => '09:00',
                    'end_time' => '18:00',
                ])->assertStatus(201);
        });

        it('detects overlapping schedule', function () {
            $response = $this->actingAs($this->manager, 'sanctum')
                ->postJson('/api/v1/shift-schedules', [
                    'dealership_id' => $this->dealership->id,
                    'name' => 'Overlap',
                    'start_time' => '10:00',
                    'end_time' => '15:00',
                ]);

            $response->assertStatus(422);
            expect($response->json('message'))->toContain('пересекается');
        });

        it('detects midnight-crossing overlap', function () {
            // Existing: 09:00-18:00. Create night shift, then try overlapping with it.
            $this->actingAs($this->manager, 'sanctum')
                ->postJson('/api/v1/shift-schedules', [
                    'dealership_id' => $this->dealership->id,
                    'name' => 'Ночная',
                    'start_time' => '22:00',
                    'end_time' => '06:00',
                ])->assertStatus(201);

            $response = $this->actingAs($this->manager, 'sanctum')
                ->postJson('/api/v1/shift-schedules', [
                    'dealership_id' => $this->dealership->id,
                    'name' => 'Overlap Night',
                    'start_time' => '23:00',
                    'end_time' => '07:00',
                ]);

            $response->assertStatus(422);
            expect($response->json('message'))->toContain('пересекается');
        });

        it('allows adjacent non-overlapping schedule', function () {
            $this->actingAs($this->manager, 'sanctum')
                ->postJson('/api/v1/shift-schedules', [
                    'dealership_id' => $this->dealership->id,
                    'name' => 'Вечерняя',
                    'start_time' => '18:00',
                    'end_time' => '22:00',
                ])->assertStatus(201);
        });
    });

    // ─── UPDATE ────────────────────────────────────────────

    describe('update', function () {
        it('updates schedule name', function () {
            $this->actingAs($this->manager, 'sanctum')
                ->putJson("/api/v1/shift-schedules/{$this->schedule->id}", [
                    'name' => 'Утренняя',
                ])->assertStatus(200);

            expect($this->schedule->fresh()->name)->toBe('Утренняя');
        });

        it('updates schedule times', function () {
            $this->actingAs($this->manager, 'sanctum')
                ->putJson("/api/v1/shift-schedules/{$this->schedule->id}", [
                    'start_time' => '08:00',
                    'end_time' => '17:00',
                ])->assertStatus(200);
        });

        it('prevents deactivating last active schedule', function () {
            $response = $this->actingAs($this->manager, 'sanctum')
                ->putJson("/api/v1/shift-schedules/{$this->schedule->id}", [
                    'is_active' => false,
                ]);

            $response->assertStatus(422);
            expect($response->json('message'))->toContain('единственную');
        });

        it('allows deactivating when other active schedules exist', function () {
            ShiftSchedule::create([
                'dealership_id' => $this->dealership->id,
                'name' => 'Другая',
                'sort_order' => 1,
                'start_time' => '19:00',
                'end_time' => '23:00',
                'is_active' => true,
            ]);

            $this->actingAs($this->manager, 'sanctum')
                ->putJson("/api/v1/shift-schedules/{$this->schedule->id}", [
                    'is_active' => false,
                ])->assertStatus(200);
        });

        it('enforces name uniqueness excluding self', function () {
            ShiftSchedule::create([
                'dealership_id' => $this->dealership->id,
                'name' => 'Другая',
                'sort_order' => 1,
                'start_time' => '19:00',
                'end_time' => '23:00',
                'is_active' => true,
            ]);

            $this->actingAs($this->manager, 'sanctum')
                ->putJson("/api/v1/shift-schedules/{$this->schedule->id}", [
                    'name' => 'Другая',
                ])->assertStatus(422);
        });

        it('allows keeping same name on self', function () {
            $this->actingAs($this->manager, 'sanctum')
                ->putJson("/api/v1/shift-schedules/{$this->schedule->id}", [
                    'name' => 'Смена 1',
                ])->assertStatus(200);
        });

        it('detects overlap after time change', function () {
            ShiftSchedule::create([
                'dealership_id' => $this->dealership->id,
                'name' => 'Другая',
                'sort_order' => 1,
                'start_time' => '19:00',
                'end_time' => '23:00',
                'is_active' => true,
            ]);

            $response = $this->actingAs($this->manager, 'sanctum')
                ->putJson("/api/v1/shift-schedules/{$this->schedule->id}", [
                    'start_time' => '08:00',
                    'end_time' => '20:00',
                ]);

            $response->assertStatus(422);
            expect($response->json('message'))->toContain('пересекается');
        });

        it('forbidden for employee', function () {
            $this->actingAs($this->employee, 'sanctum')
                ->putJson("/api/v1/shift-schedules/{$this->schedule->id}", [
                    'name' => 'Test',
                ])->assertStatus(403);
        });
    });

    // ─── DESTROY ───────────────────────────────────────────

    describe('destroy', function () {
        beforeEach(function () {
            // Ensure at least 2 schedules so we can delete one
            $this->schedule2 = ShiftSchedule::create([
                'dealership_id' => $this->dealership->id,
                'name' => 'Смена 2',
                'sort_order' => 1,
                'start_time' => '19:00',
                'end_time' => '23:00',
                'is_active' => true,
            ]);
        });

        it('soft deletes schedule', function () {
            $id = $this->schedule->id;

            $this->actingAs($this->manager, 'sanctum')
                ->deleteJson("/api/v1/shift-schedules/{$id}")
                ->assertStatus(200);

            expect(ShiftSchedule::find($id))->toBeNull();
            expect(ShiftSchedule::withTrashed()->find($id))->not->toBeNull();
        });

        it('prevents deleting last schedule', function () {
            // Delete schedule2 first
            $this->schedule2->forceDelete();

            $this->actingAs($this->manager, 'sanctum')
                ->deleteJson("/api/v1/shift-schedules/{$this->schedule->id}")
                ->assertStatus(422);
        });

        it('prevents deleting with open shifts', function () {
            Shift::factory()->create([
                'dealership_id' => $this->dealership->id,
                'shift_schedule_id' => $this->schedule->id,
                'status' => 'open',
            ]);

            $response = $this->actingAs($this->manager, 'sanctum')
                ->deleteJson("/api/v1/shift-schedules/{$this->schedule->id}");

            $response->assertStatus(422);
            expect($response->json('message'))->toContain('открытых смен');
        });

        it('prevents deleting with late shifts', function () {
            Shift::factory()->create([
                'dealership_id' => $this->dealership->id,
                'shift_schedule_id' => $this->schedule->id,
                'status' => 'late',
            ]);

            $this->actingAs($this->manager, 'sanctum')
                ->deleteJson("/api/v1/shift-schedules/{$this->schedule->id}")
                ->assertStatus(422);
        });

        it('allows deleting with only closed shifts', function () {
            Shift::factory()->closed()->create([
                'dealership_id' => $this->dealership->id,
                'shift_schedule_id' => $this->schedule->id,
            ]);

            $this->actingAs($this->manager, 'sanctum')
                ->deleteJson("/api/v1/shift-schedules/{$this->schedule->id}")
                ->assertStatus(200);
        });

        it('allows deleting with no shifts', function () {
            $this->actingAs($this->manager, 'sanctum')
                ->deleteJson("/api/v1/shift-schedules/{$this->schedule->id}")
                ->assertStatus(200);
        });

        it('forbidden for employee', function () {
            $this->actingAs($this->employee, 'sanctum')
                ->deleteJson("/api/v1/shift-schedules/{$this->schedule->id}")
                ->assertStatus(403);
        });

        it('returns 404 for nonexistent schedule', function () {
            $this->actingAs($this->manager, 'sanctum')
                ->deleteJson('/api/v1/shift-schedules/99999')
                ->assertStatus(404);
        });
    });
});
