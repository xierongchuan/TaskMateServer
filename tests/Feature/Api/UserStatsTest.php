<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\AutoDealership;
use App\Models\Task;
use App\Models\TaskResponse;
use App\Models\Shift;
use App\Enums\Role;
use Carbon\Carbon;

describe('User Stats API - GET /api/v1/users/{id}/stats', function () {
    beforeEach(function () {
        $this->dealership = AutoDealership::factory()->create();

        $this->manager = User::factory()->create([
            'role' => Role::MANAGER->value,
            'dealership_id' => $this->dealership->id,
        ]);

        $this->employee = User::factory()->create([
            'role' => Role::EMPLOYEE->value,
            'dealership_id' => $this->dealership->id,
        ]);
    });

    it('returns stats for employee', function () {
        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/users/{$this->employee->id}/stats");

        $response->assertStatus(200);
        $data = $response->json();

        expect($data)->toHaveKeys([
            'employee_id', 'employee_name', 'total_tasks', 'completed_tasks',
            'completed_on_time', 'completed_late', 'completion_rate',
            'overdue_tasks', 'pending_review', 'rejected_tasks',
            'avg_completion_time_hours', 'tasks_by_type',
            'total_shifts', 'late_shifts', 'avg_late_minutes',
            'missed_shifts', 'performance_score',
        ]);
        expect($data['tasks_by_type'])->toHaveKeys([
            'notification', 'completion', 'completion_with_proof',
        ]);
    });

    it('returns zeros when user has no tasks', function () {
        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/users/{$this->employee->id}/stats");

        $response->assertStatus(200);
        $data = $response->json();

        expect($data['total_tasks'])->toBe(0);
        expect($data['completed_tasks'])->toBe(0);
        expect($data['completed_on_time'])->toBe(0);
        expect($data['overdue_tasks'])->toBe(0);
        expect($data['performance_score'])->toBe(100);
    });

    it('calculates stats correctly with tasks', function () {
        $now = Carbon::now();

        // Task completed on time
        $task1 = Task::factory()->create([
            'dealership_id' => $this->dealership->id,
            'response_type' => 'completion',
            'deadline' => $now->copy()->addDay(),
        ]);
        $task1->assignedUsers()->attach($this->employee->id);
        TaskResponse::factory()->create([
            'task_id' => $task1->id,
            'user_id' => $this->employee->id,
            'status' => 'completed',
            'responded_at' => $now,
        ]);

        // Task completed late
        $task2 = Task::factory()->create([
            'dealership_id' => $this->dealership->id,
            'response_type' => 'completion_with_proof',
            'deadline' => $now->copy()->subHours(2),
        ]);
        $task2->assignedUsers()->attach($this->employee->id);
        TaskResponse::factory()->create([
            'task_id' => $task2->id,
            'user_id' => $this->employee->id,
            'status' => 'completed',
            'responded_at' => $now,
        ]);

        // Task pending review
        $task3 = Task::factory()->create([
            'dealership_id' => $this->dealership->id,
            'response_type' => 'notification',
        ]);
        $task3->assignedUsers()->attach($this->employee->id);
        TaskResponse::factory()->create([
            'task_id' => $task3->id,
            'user_id' => $this->employee->id,
            'status' => 'pending_review',
            'responded_at' => $now,
        ]);

        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/users/{$this->employee->id}/stats");

        $response->assertStatus(200);
        $data = $response->json();

        expect($data['total_tasks'])->toBe(3);
        expect($data['completed_tasks'])->toBe(2);
        expect($data['completed_on_time'])->toBe(1);
        expect($data['completed_late'])->toBe(1);
        expect($data['pending_review'])->toBe(1);
        expect($data['tasks_by_type']['completion'])->toBe(1);
        expect($data['tasks_by_type']['completion_with_proof'])->toBe(1);
        expect($data['tasks_by_type']['notification'])->toBe(1);
    });

    it('supports custom date range', function () {
        $dateFrom = Carbon::now()->subDays(7)->format('Y-m-d');
        $dateTo = Carbon::now()->format('Y-m-d');

        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/users/{$this->employee->id}/stats?date_from={$dateFrom}&date_to={$dateTo}");

        $response->assertStatus(200);
    });

    it('returns 404 for non-existent user', function () {
        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/users/99999/stats');

        $response->assertStatus(404);
    });

    it('requires authentication', function () {
        $response = $this->getJson("/api/v1/users/{$this->employee->id}/stats");

        $response->assertStatus(401);
    });
});
