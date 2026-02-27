<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\AutoDealership;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskProof;
use App\Models\TaskResponse;
use App\Models\TaskSharedProof;
use App\Models\TaskVerificationHistory;
use App\Models\User;
use App\Services\TaskProofService;
use App\Services\TaskVerificationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

describe('TaskVerificationService Transactions', function () {
    beforeEach(function () {
        Storage::fake('task_proofs');
        $this->dealership = AutoDealership::factory()->create();
        $this->manager = User::factory()->create([
            'role' => Role::MANAGER->value,
            'dealership_id' => $this->dealership->id,
        ]);
        $this->employees = User::factory()->count(3)->create([
            'role' => Role::EMPLOYEE->value,
            'dealership_id' => $this->dealership->id,
        ]);
        $this->verificationService = app(TaskVerificationService::class);
    });

    describe('Approve Transaction', function () {
        it('commits all changes on successful approval', function () {
            // Arrange
            $task = Task::factory()->completion()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            $response = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employees[0]->id,
                'status' => 'pending_review',
                'responded_at' => Carbon::now(),
            ]);

            // Act
            $result = $this->verificationService->approve($response, $this->manager);

            // Assert: All changes committed
            expect($result->status)->toBe('completed');
            expect($result->verified_at)->not->toBeNull();
            expect($result->verified_by)->toBe($this->manager->id);

            // History recorded
            $history = TaskVerificationHistory::where('task_response_id', $response->id)
                ->where('action', TaskVerificationHistory::ACTION_APPROVED)
                ->first();
            expect($history)->not->toBeNull();
        });
    });

    describe('Reject Transaction', function () {
        it('commits all changes on successful rejection', function () {
            // Arrange
            $task = Task::factory()->completionWithProof()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            $response = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employees[0]->id,
                'status' => 'pending_review',
                'responded_at' => Carbon::now(),
            ]);

            // Add proof
            $proof = TaskProof::create([
                'task_response_id' => $response->id,
                'file_path' => 'test/proof.jpg',
                'original_filename' => 'proof.jpg',
                'mime_type' => 'image/jpeg',
                'file_size' => 12345,
            ]);

            // Act
            $result = $this->verificationService->reject($response, $this->manager, 'Недостаточно качества');

            // Assert: All changes committed
            expect($result->status)->toBe('rejected');
            expect($result->rejection_reason)->toBe('Недостаточно качества');
            expect($result->rejection_count)->toBe(1);

            // History recorded
            $history = TaskVerificationHistory::where('task_response_id', $response->id)
                ->where('action', TaskVerificationHistory::ACTION_REJECTED)
                ->first();
            expect($history)->not->toBeNull();
            expect($history->reason)->toBe('Недостаточно качества');
        });

        it('increments rejection_count correctly on multiple rejections', function () {
            // Arrange
            $task = Task::factory()->completion()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            $response = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employees[0]->id,
                'status' => 'pending_review',
                'responded_at' => Carbon::now(),
                'rejection_count' => 2, // Already rejected twice
            ]);

            // Act
            $result = $this->verificationService->reject($response, $this->manager, 'Third rejection');

            // Assert
            expect($result->rejection_count)->toBe(3);
        });
    });

    describe('Bulk Reject Transaction', function () {
        it('rejects all pending_review responses for task', function () {
            // Arrange: Group task with 3 pending_review responses
            $task = Task::factory()->completion()->group()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);

            foreach ($this->employees as $employee) {
                TaskAssignment::create(['task_id' => $task->id, 'user_id' => $employee->id]);
                TaskResponse::create([
                    'task_id' => $task->id,
                    'user_id' => $employee->id,
                    'status' => 'pending_review',
                    'responded_at' => Carbon::now(),
                ]);
            }

            // Act
            $this->verificationService->rejectAllForTask($task, $this->manager, 'Общее отклонение');

            // Assert: All 3 responses rejected
            $responses = TaskResponse::where('task_id', $task->id)->get();
            expect($responses)->toHaveCount(3);
            foreach ($responses as $response) {
                expect($response->status)->toBe('rejected');
                expect($response->rejection_reason)->toBe('Общее отклонение');
                expect($response->rejection_count)->toBe(1);
            }

            // History recorded for all
            $historyCount = TaskVerificationHistory::whereIn('task_response_id', $responses->pluck('id'))
                ->where('action', TaskVerificationHistory::ACTION_REJECTED)
                ->count();
            expect($historyCount)->toBe(3);
        });

        it('deletes shared_proofs when bulk rejecting', function () {
            // Arrange
            $task = Task::factory()->completionWithProof()->group()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);

            foreach ($this->employees as $employee) {
                TaskAssignment::create(['task_id' => $task->id, 'user_id' => $employee->id]);
                TaskResponse::create([
                    'task_id' => $task->id,
                    'user_id' => $employee->id,
                    'status' => 'pending_review',
                    'responded_at' => Carbon::now(),
                    'uses_shared_proofs' => true,
                ]);
            }

            // Add shared proofs
            TaskSharedProof::create([
                'task_id' => $task->id,
                'file_path' => 'test/shared1.jpg',
                'original_filename' => 'shared1.jpg',
                'mime_type' => 'image/jpeg',
                'file_size' => 12345,
            ]);
            TaskSharedProof::create([
                'task_id' => $task->id,
                'file_path' => 'test/shared2.jpg',
                'original_filename' => 'shared2.jpg',
                'mime_type' => 'image/jpeg',
                'file_size' => 12345,
            ]);

            expect(TaskSharedProof::where('task_id', $task->id)->count())->toBe(2);

            // Act
            $this->verificationService->rejectAllForTask($task, $this->manager, 'Rejection');

            // Assert: Shared proofs deleted
            expect(TaskSharedProof::where('task_id', $task->id)->count())->toBe(0);
        });

        it('only rejects pending_review responses, not others', function () {
            // Arrange: Mixed statuses
            $task = Task::factory()->completion()->group()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);

            // 1 pending_review
            TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employees[0]->id,
                'status' => 'pending_review',
                'responded_at' => Carbon::now(),
            ]);

            // 1 already completed
            TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employees[1]->id,
                'status' => 'completed',
                'responded_at' => Carbon::now(),
            ]);

            // 1 rejected (previously rejected, can be resubmitted)
            TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employees[2]->id,
                'status' => 'rejected',
                'responded_at' => Carbon::now()->subHour(),
                'rejection_count' => 1,
            ]);

            // Act
            $this->verificationService->rejectAllForTask($task, $this->manager, 'Rejection');

            // Assert: Only pending_review was rejected
            $responses = TaskResponse::where('task_id', $task->id)->get();

            $rejected = $responses->where('user_id', $this->employees[0]->id)->first();
            expect($rejected->status)->toBe('rejected');

            $completed = $responses->where('user_id', $this->employees[1]->id)->first();
            expect($completed->status)->toBe('completed'); // Unchanged

            $alreadyRejected = $responses->where('user_id', $this->employees[2]->id)->first();
            expect($alreadyRejected->status)->toBe('rejected'); // Unchanged (already rejected)
        });

        it('is idempotent - calling twice has no additional effect', function () {
            // Arrange
            $task = Task::factory()->completion()->group()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);

            TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employees[0]->id,
                'status' => 'pending_review',
                'responded_at' => Carbon::now(),
            ]);

            // Act: Call twice
            $this->verificationService->rejectAllForTask($task, $this->manager, 'First');
            $this->verificationService->rejectAllForTask($task, $this->manager, 'Second');

            // Assert: rejection_count is 1 (only counted once)
            $response = TaskResponse::where('task_id', $task->id)->first();
            expect($response->rejection_count)->toBe(1);

            // Only 1 rejection history (second call had no pending_review to reject)
            $historyCount = TaskVerificationHistory::where('task_response_id', $response->id)
                ->where('action', TaskVerificationHistory::ACTION_REJECTED)
                ->count();
            expect($historyCount)->toBe(1);
        });
    });

    describe('History Recording', function () {
        it('records submission history', function () {
            // Arrange
            $task = Task::factory()->completion()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            $response = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employees[0]->id,
                'status' => 'pending_review',
                'responded_at' => Carbon::now(),
            ]);

            // Act
            $this->verificationService->recordSubmission($response, $this->employees[0]);

            // Assert
            $history = TaskVerificationHistory::where('task_response_id', $response->id)
                ->where('action', TaskVerificationHistory::ACTION_SUBMITTED)
                ->first();
            expect($history)->not->toBeNull();
            expect($history->performed_by)->toBe($this->employees[0]->id);
            expect($history->previous_status)->toBe('pending');
            expect($history->new_status)->toBe('pending_review');
        });

        it('records resubmission history', function () {
            // Arrange
            $task = Task::factory()->completion()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            $response = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employees[0]->id,
                'status' => 'pending_review',
                'responded_at' => Carbon::now(),
            ]);

            // Act
            $this->verificationService->recordResubmission($response, $this->employees[0]);

            // Assert
            $history = TaskVerificationHistory::where('task_response_id', $response->id)
                ->where('action', TaskVerificationHistory::ACTION_RESUBMITTED)
                ->first();
            expect($history)->not->toBeNull();
            expect($history->performed_by)->toBe($this->employees[0]->id);
            expect($history->previous_status)->toBe('rejected');
            expect($history->new_status)->toBe('pending_review');
        });
    });
});
