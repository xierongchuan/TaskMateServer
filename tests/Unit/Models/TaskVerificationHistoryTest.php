<?php

declare(strict_types=1);

use App\Models\Task;
use App\Models\TaskResponse;
use App\Models\TaskVerificationHistory;
use App\Models\AutoDealership;
use App\Models\User;
use App\Enums\Role;
use Carbon\Carbon;

describe('TaskVerificationHistory Model', function () {
    beforeEach(function () {
        $this->dealership = AutoDealership::factory()->create();
        $this->manager = User::factory()->create([
            'role' => Role::MANAGER->value,
            'dealership_id' => $this->dealership->id
        ]);
        $this->employee = User::factory()->create([
            'role' => Role::EMPLOYEE->value,
            'dealership_id' => $this->dealership->id
        ]);
    });

    it('can be created', function () {
        // Arrange
        $task = Task::factory()->create(['dealership_id' => $this->dealership->id]);
        $response = TaskResponse::create([
            'task_id' => $task->id,
            'user_id' => $this->employee->id,
            'status' => 'pending_review',
            'responded_at' => Carbon::now(),
        ]);

        // Act
        $history = TaskVerificationHistory::create([
            'task_response_id' => $response->id,
            'action' => 'approved',
            'performed_by' => $this->manager->id,
            'previous_status' => 'pending_review',
            'new_status' => 'completed',
            'proof_count' => 2,
        ]);

        // Assert
        expect($history)->toBeInstanceOf(TaskVerificationHistory::class);
        expect($history->action)->toBe('approved');
        expect($history->previous_status)->toBe('pending_review');
        expect($history->new_status)->toBe('completed');
    });

    describe('taskResponse relationship', function () {
        it('belongs to task response', function () {
            // Arrange
            $task = Task::factory()->create(['dealership_id' => $this->dealership->id]);
            $response = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'pending_review',
                'responded_at' => Carbon::now(),
            ]);
            $history = TaskVerificationHistory::create([
                'task_response_id' => $response->id,
                'action' => 'rejected',
                'performed_by' => $this->manager->id,
                'previous_status' => 'pending_review',
                'new_status' => 'rejected',
                'reason' => 'Фото нечёткое',
            ]);

            // Act & Assert
            expect($history->taskResponse)->toBeInstanceOf(TaskResponse::class);
            expect($history->taskResponse->id)->toBe($response->id);
        });
    });

    describe('performer relationship', function () {
        it('belongs to performer user', function () {
            // Arrange
            $task = Task::factory()->create(['dealership_id' => $this->dealership->id]);
            $response = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'pending_review',
                'responded_at' => Carbon::now(),
            ]);
            $history = TaskVerificationHistory::create([
                'task_response_id' => $response->id,
                'action' => 'approved',
                'performed_by' => $this->manager->id,
                'previous_status' => 'pending_review',
                'new_status' => 'completed',
            ]);

            // Act & Assert
            expect($history->performer)->toBeInstanceOf(User::class);
            expect($history->performer->id)->toBe($this->manager->id);
        });
    });

    describe('action constants', function () {
        it('has action constants defined', function () {
            // Assert
            expect(TaskVerificationHistory::ACTION_APPROVED)->toBe('approved');
            expect(TaskVerificationHistory::ACTION_REJECTED)->toBe('rejected');
            expect(TaskVerificationHistory::ACTION_SUBMITTED)->toBe('submitted');
            expect(TaskVerificationHistory::ACTION_RESUBMITTED)->toBe('resubmitted');
        });
    });

    describe('reason field', function () {
        it('stores rejection reason', function () {
            // Arrange
            $task = Task::factory()->create(['dealership_id' => $this->dealership->id]);
            $response = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'pending_review',
                'responded_at' => Carbon::now(),
            ]);
            $history = TaskVerificationHistory::create([
                'task_response_id' => $response->id,
                'action' => 'rejected',
                'performed_by' => $this->manager->id,
                'previous_status' => 'pending_review',
                'new_status' => 'rejected',
                'reason' => 'Пожалуйста, переснимите с лучшим освещением',
            ]);

            // Assert
            expect($history->reason)->toBe('Пожалуйста, переснимите с лучшим освещением');
        });
    });

    describe('proof_count field', function () {
        it('stores proof count', function () {
            // Arrange
            $task = Task::factory()->create(['dealership_id' => $this->dealership->id]);
            $response = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'pending_review',
                'responded_at' => Carbon::now(),
            ]);
            $history = TaskVerificationHistory::create([
                'task_response_id' => $response->id,
                'action' => 'submitted',
                'performed_by' => $this->employee->id,
                'previous_status' => 'pending',
                'new_status' => 'pending_review',
                'proof_count' => 3,
            ]);

            // Assert
            expect($history->proof_count)->toBe(3);
        });
    });
});
