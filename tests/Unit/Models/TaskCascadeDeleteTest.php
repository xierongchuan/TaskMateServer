<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\AutoDealership;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskProof;
use App\Models\TaskResponse;
use App\Models\TaskVerificationHistory;
use App\Models\User;
use Carbon\Carbon;

describe('Task Cascade Delete Behavior', function () {
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

    it('does not cascade delete related records when task is soft deleted', function () {
        // Arrange: Создаём задачу со всеми связями
        $task = Task::factory()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
        ]);

        // Assignment
        $assignment = TaskAssignment::create([
            'task_id' => $task->id,
            'user_id' => $this->employee->id,
        ]);

        // Response
        $response = TaskResponse::create([
            'task_id' => $task->id,
            'user_id' => $this->employee->id,
            'status' => 'pending_review',
            'responded_at' => Carbon::now(),
        ]);

        // Proof
        $proof = TaskProof::create([
            'task_response_id' => $response->id,
            'file_path' => 'test/proof.jpg',
            'original_filename' => 'proof.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 12345,
        ]);

        // Verification History
        $history = TaskVerificationHistory::create([
            'task_response_id' => $response->id,
            'action' => TaskVerificationHistory::ACTION_SUBMITTED,
            'performed_by' => $this->employee->id,
            'previous_status' => 'pending',
            'new_status' => 'pending_review',
            'proof_count' => 1,
            'created_at' => Carbon::now(),
        ]);

        // Act: Soft delete task
        $task->delete();

        // Assert: Task is soft deleted
        expect($task->deleted_at)->not->toBeNull();
        expect(Task::find($task->id))->toBeNull();
        expect(Task::withTrashed()->find($task->id))->not->toBeNull();

        // Assert: Related records still exist
        expect(TaskAssignment::find($assignment->id))->not->toBeNull();
        expect(TaskResponse::find($response->id))->not->toBeNull();
        expect(TaskProof::find($proof->id))->not->toBeNull();
        expect(TaskVerificationHistory::find($history->id))->not->toBeNull();
    });

    it('allows restoring soft deleted task with all relations intact', function () {
        // Arrange
        $task = Task::factory()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
        ]);
        TaskAssignment::create([
            'task_id' => $task->id,
            'user_id' => $this->employee->id,
        ]);
        TaskResponse::create([
            'task_id' => $task->id,
            'user_id' => $this->employee->id,
            'status' => 'pending_review',
            'responded_at' => Carbon::now(),
        ]);

        // Soft delete
        $task->delete();

        // Act: Restore
        $task->restore();

        // Assert: Task is restored
        expect($task->deleted_at)->toBeNull();
        expect(Task::find($task->id))->not->toBeNull();

        // Assert: Relations are still accessible
        $task->load('assignments', 'responses');
        expect($task->assignments)->toHaveCount(1);
        expect($task->responses)->toHaveCount(1);
    });

    it('preserves task archive state separately from soft delete', function () {
        // Arrange
        $task = Task::factory()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
            'is_active' => true,
            'archived_at' => null,
        ]);

        // Act: Archive (business logic)
        $task->archive('completed');

        // Assert: Archived but not soft deleted
        expect($task->is_active)->toBeFalse();
        expect($task->archived_at)->not->toBeNull();
        expect($task->archive_reason)->toBe('completed');
        expect($task->deleted_at)->toBeNull();
        expect(Task::find($task->id))->not->toBeNull();
    });

    it('can restore from archive without affecting soft delete', function () {
        // Arrange: Archived task
        $task = Task::factory()->archived()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
        ]);

        // Act: Restore from archive
        $task->restoreFromArchive();

        // Assert
        expect($task->is_active)->toBeTrue();
        expect($task->archived_at)->toBeNull();
        expect($task->archive_reason)->toBeNull();
    });

    it('handles soft deleted assignment separately', function () {
        // Arrange
        $task = Task::factory()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
        ]);
        $assignment = TaskAssignment::create([
            'task_id' => $task->id,
            'user_id' => $this->employee->id,
        ]);

        // Act: Soft delete assignment (not task)
        $assignment->delete();

        // Assert: Task still exists, assignment is soft deleted
        expect(Task::find($task->id))->not->toBeNull();
        expect(TaskAssignment::find($assignment->id))->toBeNull();
        expect(TaskAssignment::withTrashed()->find($assignment->id))->not->toBeNull();

        // Assignment can be restored
        $assignment->restore();
        expect(TaskAssignment::find($assignment->id))->not->toBeNull();
    });

    it('maintains referential integrity after soft delete', function () {
        // Arrange
        $task = Task::factory()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
        ]);
        $response = TaskResponse::create([
            'task_id' => $task->id,
            'user_id' => $this->employee->id,
            'status' => 'pending_review',
            'responded_at' => Carbon::now(),
        ]);

        // Act: Soft delete task
        $task->delete();

        // Assert: Response still references task
        $response->refresh();
        expect($response->task_id)->toBe($task->id);

        // Can still access task through relation (with trashed)
        $response->load(['task' => function ($q) {
            $q->withTrashed();
        }]);
        expect($response->task)->not->toBeNull();
        expect($response->task->id)->toBe($task->id);
    });
});
