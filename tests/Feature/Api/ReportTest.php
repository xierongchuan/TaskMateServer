<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\AutoDealership;
use App\Models\Task;
use App\Models\TaskResponse;
use App\Enums\Role;
use Carbon\Carbon;

describe('Report API', function () {
    beforeEach(function () {
        // Create dealership first
        $this->dealership = AutoDealership::factory()->create();

        // Create manager with access to the dealership
        $this->manager = User::factory()->create([
            'role' => Role::MANAGER->value,
            'dealership_id' => $this->dealership->id,
        ]);
    });

    it('returns reports', function () {
        // Arrange
        $dateFrom = Carbon::now()->subDays(7)->format('Y-m-d');
        $dateTo = Carbon::now()->format('Y-m-d');

        // Act
        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/reports?dealership_id={$this->dealership->id}&date_from={$dateFrom}&date_to={$dateTo}");

        // Assert
        $response->assertStatus(200);
        expect($response->json('summary'))->toBeArray();
    });

    it('shows all users with history in performance section', function () {
        // Arrange
        $dateFrom = Carbon::now()->subDays(7)->format('Y-m-d');
        $dateTo = Carbon::now()->format('Y-m-d');

        // Create users with different roles in the same dealership
        $employee = User::factory()->create([
            'role' => Role::EMPLOYEE->value,
            'dealership_id' => $this->dealership->id,
            'full_name' => 'Employee One'
        ]);
        $observer = User::factory()->create([
            'role' => Role::OBSERVER->value,
            'dealership_id' => $this->dealership->id,
            'full_name' => 'Observer User'
        ]);
        $managerWithNoHistory = User::factory()->create([
            'role' => Role::MANAGER->value,
            'dealership_id' => $this->dealership->id,
            'full_name' => 'Manager No History'
        ]);

        // Give the employee a task (history)
        $task1 = Task::factory()->create([
            'dealership_id' => $this->dealership->id,
            'response_type' => 'completion',
        ]);
        $task1->assignedUsers()->attach($employee->id);

        // Give the observer a task (history)
        $task2 = Task::factory()->create([
            'dealership_id' => $this->dealership->id,
            'response_type' => 'notification',
        ]);
        $task2->assignedUsers()->attach($observer->id);

        // managerWithNoHistory has no tasks or shifts — should NOT appear

        // Act
        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/reports?date_from={$dateFrom}&date_to={$dateTo}");

        // Assert
        $response->assertStatus(200);
        $employeesPerformance = $response->json('employees_performance');

        expect($employeesPerformance)->toBeArray();

        $employeeIds = array_column($employeesPerformance, 'employee_id');
        expect($employeeIds)->toContain($employee->id);
        expect($employeeIds)->toContain($observer->id);
        expect($employeeIds)->not->toContain($managerWithNoHistory->id);

        // Verify fields exist
        $first = $employeesPerformance[0];
        expect($first)->toHaveKeys([
            'employee_id', 'employee_name', 'total_tasks', 'completed_tasks',
            'completion_rate', 'overdue_tasks', 'total_shifts', 'late_shifts',
            'avg_late_minutes', 'performance_score',
            'completed_on_time', 'completed_late', 'avg_completion_time_hours',
            'pending_review', 'rejected_tasks', 'tasks_by_type', 'missed_shifts',
            'has_history',
        ]);
        expect($first['has_history'])->toBeTrue();
    });

    it('returns correct employees performance stats with tasks', function () {
        $dateFrom = Carbon::now()->subDays(7)->format('Y-m-d');
        $dateTo = Carbon::now()->format('Y-m-d');

        $employee = User::factory()->create([
            'role' => Role::EMPLOYEE->value,
            'dealership_id' => $this->dealership->id,
        ]);

        // Create a task assigned to the employee
        $task = Task::factory()->create([
            'dealership_id' => $this->dealership->id,
            'response_type' => 'completion',
            'deadline' => Carbon::now()->addDay(),
        ]);
        $task->assignedUsers()->attach($employee->id);

        // Create completed response
        TaskResponse::factory()->create([
            'task_id' => $task->id,
            'user_id' => $employee->id,
            'status' => 'completed',
            'responded_at' => Carbon::now(),
        ]);

        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/reports?date_from={$dateFrom}&date_to={$dateTo}");

        $response->assertStatus(200);
        $perf = collect($response->json('employees_performance'))
            ->firstWhere('employee_id', $employee->id);

        expect($perf['total_tasks'])->toBe(1);
        expect($perf['completed_tasks'])->toBe(1);
        expect($perf['completed_on_time'])->toBe(1);
        expect($perf['completed_late'])->toBe(0);
        expect($perf['tasks_by_type']['completion'])->toBe(1);
    });

    it('summary does not include total_replacements', function () {
        $dateFrom = Carbon::now()->subDays(7)->format('Y-m-d');
        $dateTo = Carbon::now()->format('Y-m-d');

        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/reports?dealership_id={$this->dealership->id}&date_from={$dateFrom}&date_to={$dateTo}");

        $response->assertStatus(200);
        expect($response->json('summary'))->not->toHaveKey('total_replacements');
    });
});
