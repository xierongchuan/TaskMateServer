<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Shift;
use App\Models\Task;
use App\Models\TaskResponse;
use App\Models\AutoDealership;
use App\Enums\Role;
use Carbon\Carbon;

describe('Dashboard API', function () {
    beforeEach(function () {
        $this->dealership = AutoDealership::factory()->create();
        $this->manager = User::factory()->create([
            'role' => Role::MANAGER->value,
            'dealership_id' => $this->dealership->id
        ]);
    });

    it('returns dashboard data for manager', function () {
        // Arrange
        $shift = Shift::factory()->create([
            'dealership_id' => $this->dealership->id,
            'status' => 'open',
            'shift_start' => Carbon::now()->subHour(),
        ]);

        $task = Task::factory()->create([
            'dealership_id' => $this->dealership->id,
            'is_active' => true,
        ]);

        // Act
        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/dashboard');

        // Assert
        // Assert
        $response->assertStatus(200)
            ->assertJsonStructure([
                'active_shifts',
                'active_tasks',
                'completed_tasks',
                'overdue_tasks',
                'late_shifts_today',
                'timestamp',
            ]);
    });

    it('filters dashboard data by dealership', function () {
        // Arrange
        $otherDealership = AutoDealership::factory()->create();

        Shift::factory()->create([
            'dealership_id' => $this->dealership->id,
            'status' => 'open',
        ]);

        Shift::factory()->create([
            'dealership_id' => $otherDealership->id,
            'status' => 'open',
        ]);

        // Act
        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/dashboard?dealership_id={$this->dealership->id}");

        // Assert
        $response->assertStatus(200);
        $data = $response->json();
        expect($data['active_shifts'])->toHaveCount(1);
    });

    it('calculates task statistics correctly', function () {
        // Arrange
        // Active task
        Task::factory()->create(['is_active' => true, 'dealership_id' => $this->dealership->id]);

        // Completed today - use UTC timezone to match TimeHelper::nowUtc()
        // Явно указываем task_type='individual' чтобы не требовались assignments
        $completedTask = Task::factory()->create(['is_active' => true, 'dealership_id' => $this->dealership->id, 'task_type' => 'individual']);
        TaskResponse::factory()->create([
            'task_id' => $completedTask->id,
            'status' => 'completed',
            'responded_at' => Carbon::now('UTC'),
        ]);

        // Overdue
        Task::factory()->create([
            'is_active' => true,
            'dealership_id' => $this->dealership->id,
            'deadline' => Carbon::now()->subHour(),
        ]);

        // Act
        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/dashboard');

        // Assert
        $response->assertStatus(200);
        $data = $response->json();

        expect($data['active_tasks'])->toBeGreaterThanOrEqual(3);
        expect($data['completed_tasks'])->toBeGreaterThanOrEqual(1);
        expect($data['overdue_tasks'])->toBeGreaterThanOrEqual(1);
    });
    it('returns overdue tasks list correctly', function () {
        // Arrange
        // Overdue task
        $overdueTask = Task::factory()->create([
            'title' => 'Important Overdue Task',
            'is_active' => true,
            'dealership_id' => $this->dealership->id,
            'deadline' => Carbon::now()->subHour(),
        ]);

        // Future task (not overdue)
        Task::factory()->create([
            'title' => 'Future Task',
            'is_active' => true,
            'dealership_id' => $this->dealership->id,
            'deadline' => Carbon::now()->addHour(),
        ]);

        // Completed overdue task (should not be in list)
        $completedOverdueTask = Task::factory()->create([
            'title' => 'Completed Overdue Task',
            'is_active' => true,
            'dealership_id' => $this->dealership->id,
            'deadline' => Carbon::now()->subHour(),
        ]);
        TaskResponse::factory()->create([
            'task_id' => $completedOverdueTask->id,
            'status' => 'completed',
        ]);

        // Act
        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/dashboard');

        // Assert
        $response->assertStatus(200);
        $data = $response->json();

        expect($data)->toHaveKey('overdue_tasks_list');
        expect($data['overdue_tasks_list'])->toBeArray();

        $list = collect($data['overdue_tasks_list']);
        $found = $list->firstWhere('id', $overdueTask->id);

        expect($found)->not->toBeNull();
        expect($found['status'])->toBe('overdue');
        expect($found['title'])->toBe('Important Overdue Task');
        expect($found)->toHaveKey('creator');
        expect($found)->toHaveKey('dealership');
        expect($found)->toHaveKey('assignments');

        // Ensure non-overdue and completed are not in the list
        expect($list->pluck('id'))->not->toContain($completedOverdueTask->id);
    });
});
