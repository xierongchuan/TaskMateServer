<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Task;
use App\Models\TaskResponse;
use App\Models\TaskProof;
use App\Models\TaskSharedProof;
use App\Models\AutoDealership;
use App\Services\TaskVerificationService;
use App\Services\TaskProofService;
use App\Enums\Role;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

describe('TaskVerificationService', function () {
    beforeEach(function () {
        Storage::fake('task_proofs');

        $this->dealership = AutoDealership::factory()->create();
        $this->manager = User::factory()->create([
            'role' => Role::MANAGER->value,
            'dealership_id' => $this->dealership->id
        ]);
        $this->employee = User::factory()->create([
            'role' => Role::EMPLOYEE->value,
            'dealership_id' => $this->dealership->id
        ]);

        $this->proofService = app(TaskProofService::class);
        $this->verificationService = app(TaskVerificationService::class);
    });

    describe('approve', function () {
        it('approves a pending_review response', function () {
            // Arrange
            $task = Task::factory()->create(['dealership_id' => $this->dealership->id]);
            $response = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'pending_review',
                'responded_at' => Carbon::now(),
            ]);

            // Act
            $updatedResponse = $this->verificationService->approve($response, $this->manager);

            // Assert
            expect($updatedResponse->status)->toBe('completed');
            expect($updatedResponse->verified_by)->toBe($this->manager->id);
            expect($updatedResponse->verified_at)->not->toBeNull();
            expect($updatedResponse->rejection_reason)->toBeNull();
        });

        it('records verification history on approve', function () {
            // Arrange
            $task = Task::factory()->create(['dealership_id' => $this->dealership->id]);
            $response = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'pending_review',
                'responded_at' => Carbon::now(),
            ]);

            // Act
            $this->verificationService->approve($response, $this->manager);

            // Assert
            $this->assertDatabaseHas('task_verification_history', [
                'task_response_id' => $response->id,
                'action' => 'approved',
                'performed_by' => $this->manager->id,
                'previous_status' => 'pending_review',
                'new_status' => 'completed',
            ]);
        });
    });

    describe('reject', function () {
        it('rejects a pending_review response with reason', function () {
            // Arrange
            $task = Task::factory()->create(['dealership_id' => $this->dealership->id]);
            $response = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'pending_review',
                'responded_at' => Carbon::now(),
            ]);

            // Act
            $updatedResponse = $this->verificationService->reject(
                $response,
                $this->manager,
                'Фото нечёткое'
            );

            // Assert
            expect($updatedResponse->status)->toBe('rejected');
            expect($updatedResponse->rejection_reason)->toBe('Фото нечёткое');
            expect($updatedResponse->rejection_count)->toBe(1);
            expect($updatedResponse->verified_at)->toBeNull();
            expect($updatedResponse->verified_by)->toBeNull();
        });

        it('increments rejection_count on multiple rejections', function () {
            // Arrange
            $task = Task::factory()->create(['dealership_id' => $this->dealership->id]);
            $response = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'pending_review',
                'responded_at' => Carbon::now(),
                'rejection_count' => 2,
            ]);

            // Act
            $updatedResponse = $this->verificationService->reject(
                $response,
                $this->manager,
                'Третье отклонение'
            );

            // Assert
            expect($updatedResponse->rejection_count)->toBe(3);
        });

        it('records verification history on reject', function () {
            // Arrange
            $task = Task::factory()->create(['dealership_id' => $this->dealership->id]);
            $response = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'pending_review',
                'responded_at' => Carbon::now(),
            ]);

            // Act
            $this->verificationService->reject($response, $this->manager, 'Причина отклонения');

            // Assert
            $this->assertDatabaseHas('task_verification_history', [
                'task_response_id' => $response->id,
                'action' => 'rejected',
                'performed_by' => $this->manager->id,
                'previous_status' => 'pending_review',
                'new_status' => 'rejected',
                'reason' => 'Причина отклонения',
            ]);
        });

        it('sets uses_shared_proofs to false on reject', function () {
            // Arrange
            $task = Task::factory()->create(['dealership_id' => $this->dealership->id]);
            $response = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'pending_review',
                'responded_at' => Carbon::now(),
                'uses_shared_proofs' => true,
            ]);

            // Act
            $updatedResponse = $this->verificationService->reject(
                $response,
                $this->manager,
                'Отклонено'
            );

            // Assert
            expect($updatedResponse->uses_shared_proofs)->toBeFalse();
        });
    });

    describe('rejectAllForTask', function () {
        it('rejects all pending_review responses for a task', function () {
            // Arrange
            $task = Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'task_type' => 'group',
            ]);

            $employee2 = User::factory()->create([
                'role' => Role::EMPLOYEE->value,
                'dealership_id' => $this->dealership->id
            ]);

            $response1 = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'pending_review',
                'responded_at' => Carbon::now(),
            ]);
            $response2 = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $employee2->id,
                'status' => 'pending_review',
                'responded_at' => Carbon::now(),
            ]);

            // Act
            $this->verificationService->rejectAllForTask($task, $this->manager, 'Общая причина');

            // Assert
            $response1->refresh();
            $response2->refresh();

            expect($response1->status)->toBe('rejected');
            expect($response2->status)->toBe('rejected');
            expect($response1->rejection_reason)->toBe('Общая причина');
            expect($response2->rejection_reason)->toBe('Общая причина');
        });

        it('does not reject already completed responses', function () {
            // Arrange
            $task = Task::factory()->create(['dealership_id' => $this->dealership->id]);

            $response1 = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'pending_review',
                'responded_at' => Carbon::now(),
            ]);
            $response2 = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->manager->id,
                'status' => 'completed',
                'responded_at' => Carbon::now(),
            ]);

            // Act
            $this->verificationService->rejectAllForTask($task, $this->manager, 'Причина');

            // Assert
            $response2->refresh();
            expect($response2->status)->toBe('completed');
        });

        it('records history for each rejected response', function () {
            // Arrange
            $task = Task::factory()->create(['dealership_id' => $this->dealership->id]);

            $employee2 = User::factory()->create([
                'role' => Role::EMPLOYEE->value,
                'dealership_id' => $this->dealership->id
            ]);

            $response1 = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'pending_review',
                'responded_at' => Carbon::now(),
            ]);
            $response2 = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $employee2->id,
                'status' => 'pending_review',
                'responded_at' => Carbon::now(),
            ]);

            // Act
            $this->verificationService->rejectAllForTask($task, $this->manager, 'Причина');

            // Assert
            $this->assertDatabaseHas('task_verification_history', [
                'task_response_id' => $response1->id,
                'action' => 'rejected',
            ]);
            $this->assertDatabaseHas('task_verification_history', [
                'task_response_id' => $response2->id,
                'action' => 'rejected',
            ]);
        });
    });

    describe('recordSubmission', function () {
        it('records first submission in history', function () {
            // Arrange
            $task = Task::factory()->create(['dealership_id' => $this->dealership->id]);
            $response = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'pending_review',
                'responded_at' => Carbon::now(),
            ]);

            // Act
            $this->verificationService->recordSubmission($response, $this->employee);

            // Assert
            $this->assertDatabaseHas('task_verification_history', [
                'task_response_id' => $response->id,
                'action' => 'submitted',
                'performed_by' => $this->employee->id,
                'previous_status' => 'pending',
                'new_status' => 'pending_review',
            ]);
        });
    });

    describe('recordResubmission', function () {
        it('records resubmission in history', function () {
            // Arrange
            $task = Task::factory()->create(['dealership_id' => $this->dealership->id]);
            $response = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'pending_review',
                'responded_at' => Carbon::now(),
            ]);

            // Act
            $this->verificationService->recordResubmission($response, $this->employee);

            // Assert
            $this->assertDatabaseHas('task_verification_history', [
                'task_response_id' => $response->id,
                'action' => 'resubmitted',
                'performed_by' => $this->employee->id,
                'previous_status' => 'rejected',
                'new_status' => 'pending_review',
            ]);
        });
    });
});
