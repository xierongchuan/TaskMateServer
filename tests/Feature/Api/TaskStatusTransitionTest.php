<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\AutoDealership;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskResponse;
use App\Models\User;

beforeEach(function () {
    $this->dealership = AutoDealership::factory()->create();

    $this->owner = User::factory()->create([
        'role' => Role::OWNER,
        'dealership_id' => $this->dealership->id,
    ]);

    $this->manager = User::factory()->create([
        'role' => Role::MANAGER,
        'dealership_id' => $this->dealership->id,
    ]);

    $this->employee = User::factory()->create([
        'role' => Role::EMPLOYEE,
        'dealership_id' => $this->dealership->id,
    ]);
});

describe('Task Status Transitions (State Machine)', function () {
    describe('employee transitions', function () {
        it('allows pending -> acknowledged transition', function () {
            $task = Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'task_type' => 'individual',
                'response_type' => 'notification',
            ]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee->id]);

            $this->actingAs($this->employee, 'sanctum')
                ->patchJson("/api/v1/tasks/{$task->id}/status", [
                    'status' => 'acknowledged',
                ])
                ->assertOk();

            $task->refresh();
            expect($task->status)->toBe('acknowledged');
        });

        it('allows pending -> pending_review transition with proofs', function () {
            $task = Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'task_type' => 'individual',
                'response_type' => 'completion',
            ]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee->id]);

            $this->actingAs($this->employee, 'sanctum')
                ->patchJson("/api/v1/tasks/{$task->id}/status", [
                    'status' => 'completed',
                ])
                ->assertOk();

            $task->refresh();
            expect($task->status)->toBe('completed');
        });

        it('allows rejected -> pending_review transition (resubmission)', function () {
            $task = Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'task_type' => 'individual',
                'response_type' => 'completion',
            ]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee->id]);

            // Create rejected response
            TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'rejected',
                'rejection_reason' => 'Bad quality',
                'rejection_count' => 1,
            ]);

            $this->actingAs($this->employee, 'sanctum')
                ->patchJson("/api/v1/tasks/{$task->id}/status", [
                    'status' => 'completed',
                ])
                ->assertOk();

            $response = TaskResponse::where('task_id', $task->id)
                ->where('user_id', $this->employee->id)
                ->first();

            expect($response->status)->toBe('completed');
            expect($response->submission_source)->toBe('resubmitted');
        });

        it('denies completed -> acknowledged transition for employee', function () {
            $task = Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'task_type' => 'individual',
                'response_type' => 'completion',
            ]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee->id]);

            // Create completed response
            TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'completed',
            ]);

            $this->actingAs($this->employee, 'sanctum')
                ->patchJson("/api/v1/tasks/{$task->id}/status", [
                    'status' => 'acknowledged',
                ])
                ->assertStatus(422)
                ->assertJsonFragment(['message' => 'Недопустимый переход статуса: completed -> acknowledged']);
        });

        it('denies pending_review -> acknowledged transition for employee', function () {
            $task = Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'task_type' => 'individual',
                'response_type' => 'completion_with_proof',
            ]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee->id]);

            // Create pending_review response
            TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'pending_review',
            ]);

            $this->actingAs($this->employee, 'sanctum')
                ->patchJson("/api/v1/tasks/{$task->id}/status", [
                    'status' => 'acknowledged',
                ])
                ->assertStatus(422);
        });
    });

    describe('manager transitions', function () {
        it('allows manager to reset any status to pending', function () {
            $task = Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'task_type' => 'individual',
                'response_type' => 'completion',
            ]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee->id]);

            // Create completed response
            TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'completed',
            ]);

            $this->actingAs($this->manager, 'sanctum')
                ->patchJson("/api/v1/tasks/{$task->id}/status", [
                    'status' => 'pending',
                ])
                ->assertOk();

            $task->refresh();
            expect($task->status)->toBe('pending');
        });

        it('allows manager to reset pending_review to pending', function () {
            $task = Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'task_type' => 'individual',
                'response_type' => 'completion_with_proof',
            ]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee->id]);

            TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'pending_review',
            ]);

            $this->actingAs($this->manager, 'sanctum')
                ->patchJson("/api/v1/tasks/{$task->id}/status", [
                    'status' => 'pending',
                    'preserve_proofs' => true,
                ])
                ->assertOk();

            $response = TaskResponse::where('task_id', $task->id)->first();
            expect($response->status)->toBe('pending');
        });

        it('allows manager to complete pending_review without verification API', function () {
            $task = Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'task_type' => 'individual',
                'response_type' => 'completion',
            ]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee->id]);

            TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'pending_review',
            ]);

            $this->actingAs($this->manager, 'sanctum')
                ->patchJson("/api/v1/tasks/{$task->id}/status", [
                    'status' => 'completed',
                ])
                ->assertOk();

            $task->refresh();
            expect($task->status)->toBe('completed');
        });
    });

    describe('owner transitions', function () {
        it('allows owner same privileges as manager', function () {
            $task = Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'task_type' => 'individual',
                'response_type' => 'completion',
            ]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee->id]);

            TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'completed',
            ]);

            $this->actingAs($this->owner, 'sanctum')
                ->patchJson("/api/v1/tasks/{$task->id}/status", [
                    'status' => 'pending',
                ])
                ->assertOk();

            $task->refresh();
            expect($task->status)->toBe('pending');
        });
    });

    describe('invalid status values', function () {
        it('rejects invalid status value', function () {
            $task = Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'task_type' => 'individual',
            ]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee->id]);

            $this->actingAs($this->employee, 'sanctum')
                ->patchJson("/api/v1/tasks/{$task->id}/status", [
                    'status' => 'rejected', // rejected is not allowed via updateStatus
                ])
                ->assertStatus(422);
        });

        it('rejects completed_late status (computed only)', function () {
            $task = Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'task_type' => 'individual',
            ]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee->id]);

            $this->actingAs($this->employee, 'sanctum')
                ->patchJson("/api/v1/tasks/{$task->id}/status", [
                    'status' => 'completed_late',
                ])
                ->assertStatus(422);
        });

        it('rejects overdue status (computed only)', function () {
            $task = Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'task_type' => 'individual',
            ]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee->id]);

            $this->actingAs($this->employee, 'sanctum')
                ->patchJson("/api/v1/tasks/{$task->id}/status", [
                    'status' => 'overdue',
                ])
                ->assertStatus(422);
        });
    });
});
