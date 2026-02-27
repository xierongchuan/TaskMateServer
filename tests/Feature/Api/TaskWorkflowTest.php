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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

describe('Task Workflow', function () {
    beforeEach(function () {
        Storage::fake('local');
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

    it('completes full workflow: pending -> pending_review -> completed', function () {
        // Arrange: Создаём задачу completion (без доказательств для простоты)
        $task = Task::factory()->completion()->individual()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
            'deadline' => Carbon::now()->addDay(),
        ]);
        TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee->id]);

        // Assert initial status
        $task->load('responses', 'assignments');
        expect($task->status)->toBe('pending');

        // Act 1: Employee отправляет задачу на проверку (pending -> pending_review)
        $response = $this->actingAs($this->employee, 'sanctum')
            ->patchJson("/api/v1/tasks/{$task->id}/status", [
                'status' => 'pending_review',
            ]);

        $response->assertStatus(200);
        expect($response->json('status'))->toBe('pending_review');

        // Verify TaskResponse created
        $taskResponse = TaskResponse::where('task_id', $task->id)
            ->where('user_id', $this->employee->id)
            ->first();
        expect($taskResponse)->not->toBeNull();
        expect($taskResponse->status)->toBe('pending_review');
        expect($taskResponse->responded_at)->not->toBeNull();

        // Note: Submission history is only recorded when proof_files are uploaded
        // For completion tasks without proofs, no submission history is created

        // Act 2: Manager одобряет (pending_review -> completed)
        $approveResponse = $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/task-responses/{$taskResponse->id}/approve");

        $approveResponse->assertStatus(200);

        // Verify final state
        $taskResponse->refresh();
        expect($taskResponse->status)->toBe('completed');
        expect($taskResponse->verified_at)->not->toBeNull();
        expect($taskResponse->verified_by)->toBe($this->manager->id);

        // Verify task status
        $task->load('responses', 'assignments');
        expect($task->status)->toBe('completed');

        // Verify approval in history
        $approvalHistory = TaskVerificationHistory::where('task_response_id', $taskResponse->id)
            ->where('action', TaskVerificationHistory::ACTION_APPROVED)
            ->first();
        expect($approvalHistory)->not->toBeNull();
        expect($approvalHistory->previous_status)->toBe('pending_review');
        expect($approvalHistory->new_status)->toBe('completed');
    });

    it('handles rejection and resubmission workflow correctly', function () {
        // Arrange: Создаём задачу completion
        $task = Task::factory()->completion()->individual()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
            'deadline' => Carbon::now()->addDay(),
        ]);
        TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee->id]);

        // Employee отправляет задачу на проверку
        $submitResponse = $this->actingAs($this->employee, 'sanctum')
            ->patchJson("/api/v1/tasks/{$task->id}/status", [
                'status' => 'pending_review',
            ]);
        $submitResponse->assertStatus(200);

        $taskResponse = TaskResponse::where('task_id', $task->id)
            ->where('user_id', $this->employee->id)
            ->first();

        // Act 1: Manager отклоняет
        $rejectResponse = $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/task-responses/{$taskResponse->id}/reject", [
                'reason' => 'Неполное выполнение',
            ]);

        $rejectResponse->assertStatus(200);
        $taskResponse->refresh();

        expect($taskResponse->status)->toBe('rejected');
        expect($taskResponse->rejection_count)->toBe(1);
        expect($taskResponse->rejection_reason)->toBe('Неполное выполнение');
        expect($taskResponse->verified_at)->toBeNull();
        expect($taskResponse->verified_by)->toBeNull();

        // Verify rejection in history
        $rejectionHistory = TaskVerificationHistory::where('task_response_id', $taskResponse->id)
            ->where('action', TaskVerificationHistory::ACTION_REJECTED)
            ->first();
        expect($rejectionHistory)->not->toBeNull();
        expect($rejectionHistory->reason)->toBe('Неполное выполнение');

        // Act 2: Employee переотправляет
        $resubmitResponse = $this->actingAs($this->employee, 'sanctum')
            ->patchJson("/api/v1/tasks/{$task->id}/status", [
                'status' => 'pending_review',
            ]);

        $resubmitResponse->assertStatus(200);
        $taskResponse->refresh();

        expect($taskResponse->status)->toBe('pending_review');
        expect($taskResponse->rejection_count)->toBe(1); // Счётчик не изменился

        // Note: Resubmission history is only recorded when proof_files are uploaded
        // For completion tasks without proofs, no resubmission history is created

        // Act 3: Manager снова отклоняет
        $rejectResponse2 = $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/task-responses/{$taskResponse->id}/reject", [
                'reason' => 'Всё ещё неполно',
            ]);

        $rejectResponse2->assertStatus(200);
        $taskResponse->refresh();

        expect($taskResponse->status)->toBe('rejected');
        expect($taskResponse->rejection_count)->toBe(2);

        // Act 4: Employee переотправляет и manager одобряет
        $this->actingAs($this->employee, 'sanctum')
            ->patchJson("/api/v1/tasks/{$task->id}/status", [
                'status' => 'pending_review',
            ])
            ->assertStatus(200);

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/task-responses/{$taskResponse->id}/approve")
            ->assertStatus(200);

        $taskResponse->refresh();
        expect($taskResponse->status)->toBe('completed');
        expect($taskResponse->rejection_count)->toBe(2); // Счётчик сохранился

        // Verify full history (only rejection and approval are recorded for tasks without proofs)
        $historyCount = TaskVerificationHistory::where('task_response_id', $taskResponse->id)->count();
        // rejected, rejected, approved = 3 (submission/resubmission only recorded with proof_files)
        expect($historyCount)->toBe(3);

        // Verify task status is completed
        $task->load('responses', 'assignments');
        expect($task->status)->toBe('completed');
    });

    it('marks task as completed_late when completed after deadline', function () {
        // Arrange: Создаём задачу с прошедшим deadline
        $task = Task::factory()->completion()->individual()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
            'deadline' => Carbon::now()->subHour(), // Deadline прошёл
            'is_active' => true,
        ]);
        TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee->id]);

        // Verify initial status is overdue
        $task->load('responses', 'assignments');
        expect($task->status)->toBe('overdue');

        // Act: Employee выполняет задачу после deadline
        $response = $this->actingAs($this->employee, 'sanctum')
            ->patchJson("/api/v1/tasks/{$task->id}/status", [
                'status' => 'completed',
            ]);

        $response->assertStatus(200);

        // Verify task status is completed_late
        $task->load('responses', 'assignments');
        expect($task->status)->toBe('completed_late');

        // Verify response has correct responded_at
        $taskResponse = TaskResponse::where('task_id', $task->id)
            ->where('user_id', $this->employee->id)
            ->first();
        expect($taskResponse->responded_at->gt($task->deadline))->toBeTrue();
    });

    it('allows notification task to be acknowledged without proof', function () {
        // Arrange: Создаём notification задачу
        $task = Task::factory()->notification()->individual()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
            'deadline' => Carbon::now()->addDay(),
        ]);
        TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee->id]);

        // Act: Employee подтверждает уведомление
        $response = $this->actingAs($this->employee, 'sanctum')
            ->patchJson("/api/v1/tasks/{$task->id}/status", [
                'status' => 'acknowledged',
            ]);

        $response->assertStatus(200);
        expect($response->json('status'))->toBe('acknowledged');

        // Verify TaskResponse
        $taskResponse = TaskResponse::where('task_id', $task->id)
            ->where('user_id', $this->employee->id)
            ->first();
        expect($taskResponse)->not->toBeNull();
        expect($taskResponse->status)->toBe('acknowledged');
    });

    it('resets task to pending and removes all responses', function () {
        // Arrange: Создаём задачу с completed response
        $task = Task::factory()->completion()->individual()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
        ]);
        TaskResponse::create([
            'task_id' => $task->id,
            'user_id' => $this->employee->id,
            'status' => 'completed',
            'responded_at' => Carbon::now(),
        ]);

        $task->load('responses', 'assignments');
        expect($task->status)->toBe('completed');

        // Act: Manager сбрасывает статус
        $response = $this->actingAs($this->manager, 'sanctum')
            ->patchJson("/api/v1/tasks/{$task->id}/status", [
                'status' => 'pending',
            ]);

        $response->assertStatus(200);
        expect($response->json('status'))->toBe('pending');

        // Verify responses deleted
        expect(TaskResponse::where('task_id', $task->id)->count())->toBe(0);
    });

    it('preserves proofs when resetting with preserve_proofs flag', function () {
        // Arrange: Создаём задачу с pending_review response и proofs (напрямую в БД)
        $task = Task::factory()->completionWithProof()->individual()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
        ]);
        TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee->id]);

        // Создаём response и proof напрямую (без асинхронной загрузки)
        $taskResponse = TaskResponse::create([
            'task_id' => $task->id,
            'user_id' => $this->employee->id,
            'status' => 'pending_review',
            'responded_at' => Carbon::now(),
        ]);

        $proof = TaskProof::create([
            'task_response_id' => $taskResponse->id,
            'file_path' => 'test/proof.jpg',
            'original_filename' => 'proof.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 12345,
        ]);

        $proofCount = $taskResponse->proofs()->count();
        expect($proofCount)->toBe(1);

        // Act: Manager сбрасывает с сохранением proofs
        $response = $this->actingAs($this->manager, 'sanctum')
            ->patchJson("/api/v1/tasks/{$task->id}/status", [
                'status' => 'pending',
                'preserve_proofs' => true,
            ]);

        $response->assertStatus(200);

        // Verify: Response exists but verification fields cleared
        $taskResponse->refresh();
        expect($taskResponse->status)->toBe('pending');
        expect($taskResponse->verified_at)->toBeNull();
        expect($taskResponse->verified_by)->toBeNull();
        // Proofs should be preserved
        expect($taskResponse->proofs()->count())->toBe($proofCount);
    });

    it('dispatches job when uploading proof files', function () {
        Queue::fake();

        $task = Task::factory()->completionWithProof()->individual()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
            'deadline' => Carbon::now()->addDay(),
        ]);
        TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee->id]);

        // Act: Employee отправляет с файлами
        $response = $this->actingAs($this->employee, 'sanctum')
            ->patchJson("/api/v1/tasks/{$task->id}/status", [
                'status' => 'pending_review',
                'proof_files' => [
                    UploadedFile::fake()->image('proof.jpg', 100, 100),
                ],
            ]);

        $response->assertStatus(200);

        // Verify job was dispatched
        Queue::assertPushed(\App\Jobs\StoreTaskProofsJob::class);
    });

    it('allows approval of completion_with_proof task when proofs exist', function () {
        // Arrange: Создаём задачу completion_with_proof с proofs в БД
        $task = Task::factory()->completionWithProof()->individual()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
            'deadline' => Carbon::now()->addDay(),
        ]);
        TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee->id]);

        // Создаём response и proof напрямую
        $taskResponse = TaskResponse::create([
            'task_id' => $task->id,
            'user_id' => $this->employee->id,
            'status' => 'pending_review',
            'responded_at' => Carbon::now(),
        ]);

        TaskProof::create([
            'task_response_id' => $taskResponse->id,
            'file_path' => 'test/proof.jpg',
            'original_filename' => 'proof.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 12345,
        ]);

        // Act: Manager одобряет
        $approveResponse = $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/task-responses/{$taskResponse->id}/approve");

        $approveResponse->assertStatus(200);

        // Verify
        $taskResponse->refresh();
        expect($taskResponse->status)->toBe('completed');
        expect($taskResponse->verified_at)->not->toBeNull();
    });

    it('rejects approval when no proofs exist for completion_with_proof task', function () {
        // Arrange: Создаём задачу completion_with_proof БЕЗ proofs
        $task = Task::factory()->completionWithProof()->individual()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
        ]);

        $taskResponse = TaskResponse::create([
            'task_id' => $task->id,
            'user_id' => $this->employee->id,
            'status' => 'pending_review',
            'responded_at' => Carbon::now(),
        ]);

        // Act: Manager пытается одобрить без proofs
        $approveResponse = $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/task-responses/{$taskResponse->id}/approve");

        // Assert: Should fail with 422
        $approveResponse->assertStatus(422);
        expect($approveResponse->json('message'))->toContain('доказательств');
    });
});
