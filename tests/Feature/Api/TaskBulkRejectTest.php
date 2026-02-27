<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Enums\TaskType;
use App\Models\AutoDealership;
use App\Models\Task;
use App\Models\TaskProof;
use App\Models\TaskSharedProof;
use App\Models\TaskVerificationHistory;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

describe('Task Bulk Reject', function () {
    beforeEach(function () {
        Storage::fake('task_proofs');
        Storage::fake('local');
        $this->dealership = AutoDealership::factory()->create();
        $this->manager = User::factory()->create([
            'role' => Role::MANAGER->value,
            'dealership_id' => $this->dealership->id,
        ]);
    });

    it('rejects all pending_review responses for group task without shared_proofs', function () {
        $employees = User::factory()->count(3)->create([
            'role' => Role::EMPLOYEE->value,
            'dealership_id' => $this->dealership->id,
        ]);

        $task = Task::factory()->create([
            'dealership_id' => $this->dealership->id,
            'task_type' => TaskType::GROUP->value,
            'response_type' => 'completion_with_proof',
        ]);

        foreach ($employees as $employee) {
            $task->assignments()->create(['user_id' => $employee->id]);
        }

        // Создаем responses с индивидуальными proofs
        foreach ($employees as $employee) {
            $response = $task->responses()->create([
                'user_id' => $employee->id,
                'status' => 'pending_review',
                'responded_at' => now(),
            ]);

            $file = UploadedFile::fake()->image("proof_{$employee->id}.jpg");
            $path = $file->store("dealerships/{$this->dealership->id}/tasks/{$task->id}", 'task_proofs');

            TaskProof::create([
                'task_response_id' => $response->id,
                'file_path' => $path,
                'original_filename' => "proof_{$employee->id}.jpg",
                'mime_type' => 'image/jpeg',
                'file_size' => $file->getSize(),
            ]);
        }

        // Проверяем начальное состояние
        expect($task->responses()->where('status', 'pending_review')->count())->toBe(3);
        expect(TaskProof::whereIn('task_response_id', $task->responses->pluck('id'))->count())->toBe(3);

        // Вызываем bulk reject
        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/tasks/{$task->id}/reject-all-responses", [
                'reason' => 'Плохое качество фото',
            ])
            ->assertOk()
            ->assertJson(['message' => 'Все ответы отклонены']);

        // Проверяем, что ВСЕ responses отклонены
        $task->refresh();
        expect($task->responses()->where('status', 'rejected')->count())->toBe(3);
        expect($task->responses()->where('status', 'pending_review')->count())->toBe(0);

        // Проверяем, что причина отклонения одинакова для всех
        foreach ($task->responses as $response) {
            expect($response->rejection_reason)->toBe('Плохое качество фото');
            expect($response->rejection_count)->toBe(1);
        }

        // Проверяем, что все proofs удалены из БД
        expect(TaskProof::whereIn('task_response_id', $task->responses->pluck('id'))->count())->toBe(0);
    });

    it('rejects all responses and deletes shared_proofs for group task with shared_proofs', function () {
        Queue::fake();

        $employees = User::factory()->count(3)->create([
            'role' => Role::EMPLOYEE->value,
            'dealership_id' => $this->dealership->id,
        ]);

        $task = Task::factory()->create([
            'dealership_id' => $this->dealership->id,
            'task_type' => TaskType::GROUP->value,
            'response_type' => 'completion_with_proof',
        ]);

        foreach ($employees as $employee) {
            $task->assignments()->create(['user_id' => $employee->id]);
            $task->responses()->create([
                'user_id' => $employee->id,
                'status' => 'pending_review',
                'responded_at' => now(),
            ]);
        }

        // Создаем shared proof
        $file = UploadedFile::fake()->image('shared.jpg');
        $path = $file->store("private/task_proofs/{$this->dealership->id}", 'local');

        TaskSharedProof::create([
            'task_id' => $task->id,
            'file_path' => $path,
            'original_filename' => 'shared.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => $file->getSize(),
        ]);

        // Bulk reject
        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/tasks/{$task->id}/reject-all-responses", [
                'reason' => 'Необходимо переснять',
            ])
            ->assertOk();

        // Все responses отклонены
        $task->refresh();
        expect($task->responses()->where('status', 'rejected')->count())->toBe(3);

        // Shared proofs удалены из БД
        expect($task->sharedProofs()->count())->toBe(0);

        // Job для удаления файла поставлен в очередь
        Queue::assertPushed(\App\Jobs\DeleteProofFileJob::class);
    });

    it('returns 422 when no pending_review responses exist', function () {
        $task = Task::factory()->create([
            'dealership_id' => $this->dealership->id,
            'task_type' => TaskType::GROUP->value,
        ]);

        $employee = User::factory()->create([
            'role' => Role::EMPLOYEE->value,
            'dealership_id' => $this->dealership->id,
        ]);

        $task->assignments()->create(['user_id' => $employee->id]);
        $task->responses()->create([
            'user_id' => $employee->id,
            'status' => 'completed',
            'responded_at' => now(),
        ]);

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/tasks/{$task->id}/reject-all-responses", [
                'reason' => 'Test',
            ])
            ->assertStatus(422)
            ->assertJson(['message' => 'Нет ответов, ожидающих проверки']);
    });

    it('returns 403 when manager has no dealership access', function () {
        $otherDealership = AutoDealership::factory()->create();
        $task = Task::factory()->create([
            'dealership_id' => $otherDealership->id,
            'task_type' => TaskType::GROUP->value,
        ]);

        $employee = User::factory()->create([
            'role' => Role::EMPLOYEE->value,
            'dealership_id' => $otherDealership->id,
        ]);

        $task->assignments()->create(['user_id' => $employee->id]);
        $task->responses()->create([
            'user_id' => $employee->id,
            'status' => 'pending_review',
            'responded_at' => now(),
        ]);

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/tasks/{$task->id}/reject-all-responses", [
                'reason' => 'Test',
            ])
            ->assertStatus(403);
    });

    it('returns 404 for non-existent task', function () {
        $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/v1/tasks/99999/reject-all-responses', [
                'reason' => 'Test',
            ])
            ->assertStatus(404);
    });

    it('only rejects pending_review responses, skipping completed ones', function () {
        $employees = User::factory()->count(3)->create([
            'role' => Role::EMPLOYEE->value,
            'dealership_id' => $this->dealership->id,
        ]);

        $task = Task::factory()->create([
            'dealership_id' => $this->dealership->id,
            'task_type' => TaskType::GROUP->value,
            'response_type' => 'completion_with_proof',
        ]);

        foreach ($employees as $employee) {
            $task->assignments()->create(['user_id' => $employee->id]);
        }

        // 2 pending_review, 1 completed
        $task->responses()->create([
            'user_id' => $employees[0]->id,
            'status' => 'pending_review',
            'responded_at' => now(),
        ]);
        $task->responses()->create([
            'user_id' => $employees[1]->id,
            'status' => 'pending_review',
            'responded_at' => now(),
        ]);
        $task->responses()->create([
            'user_id' => $employees[2]->id,
            'status' => 'completed',
            'responded_at' => now(),
            'verified_at' => now(),
            'verified_by' => $this->manager->id,
        ]);

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/tasks/{$task->id}/reject-all-responses", [
                'reason' => 'Плохое качество',
            ])
            ->assertOk();

        $task->refresh();
        expect($task->responses()->where('status', 'rejected')->count())->toBe(2);
        expect($task->responses()->where('status', 'completed')->count())->toBe(1);
    });

    it('increments rejection_count correctly on bulk reject', function () {
        $employee = User::factory()->create([
            'role' => Role::EMPLOYEE->value,
            'dealership_id' => $this->dealership->id,
        ]);

        $task = Task::factory()->create([
            'dealership_id' => $this->dealership->id,
            'task_type' => TaskType::GROUP->value,
        ]);

        $task->assignments()->create(['user_id' => $employee->id]);

        // Response с уже имеющимся rejection_count = 1
        $task->responses()->create([
            'user_id' => $employee->id,
            'status' => 'pending_review',
            'responded_at' => now(),
            'rejection_count' => 1,
        ]);

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/tasks/{$task->id}/reject-all-responses", [
                'reason' => 'Повторное отклонение',
            ])
            ->assertOk();

        $task->refresh();
        $response = $task->responses()->first();
        expect($response->rejection_count)->toBe(2);
    });

    it('records verification history for each rejected response', function () {
        $employees = User::factory()->count(2)->create([
            'role' => Role::EMPLOYEE->value,
            'dealership_id' => $this->dealership->id,
        ]);

        $task = Task::factory()->create([
            'dealership_id' => $this->dealership->id,
            'task_type' => TaskType::GROUP->value,
        ]);

        foreach ($employees as $employee) {
            $task->assignments()->create(['user_id' => $employee->id]);
            $task->responses()->create([
                'user_id' => $employee->id,
                'status' => 'pending_review',
                'responded_at' => now(),
            ]);
        }

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/tasks/{$task->id}/reject-all-responses", [
                'reason' => 'Причина отклонения',
            ])
            ->assertOk();

        // Проверяем историю верификации
        $responseIds = $task->responses->pluck('id');
        $historyCount = TaskVerificationHistory::whereIn('task_response_id', $responseIds)
            ->where('action', 'rejected')
            ->count();

        expect($historyCount)->toBe(2);

        // Каждая запись содержит правильные данные
        $histories = TaskVerificationHistory::whereIn('task_response_id', $responseIds)->get();
        foreach ($histories as $history) {
            expect($history->action)->toBe('rejected');
            expect($history->performed_by)->toBe($this->manager->id);
            expect($history->reason)->toBe('Причина отклонения');
            expect($history->previous_status)->toBe('pending_review');
            expect($history->new_status)->toBe('rejected');
        }
    });

    it('requires reason field for bulk reject', function () {
        $task = Task::factory()->create([
            'dealership_id' => $this->dealership->id,
        ]);

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/tasks/{$task->id}/reject-all-responses", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);
    });

    it('individual reject does not affect other pending responses', function () {
        $employees = User::factory()->count(3)->create([
            'role' => Role::EMPLOYEE->value,
            'dealership_id' => $this->dealership->id,
        ]);

        $task = Task::factory()->create([
            'dealership_id' => $this->dealership->id,
            'task_type' => TaskType::GROUP->value,
            'response_type' => 'completion_with_proof',
        ]);

        $responses = [];
        foreach ($employees as $employee) {
            $task->assignments()->create(['user_id' => $employee->id]);
            $response = $task->responses()->create([
                'user_id' => $employee->id,
                'status' => 'pending_review',
                'responded_at' => now(),
            ]);
            $responses[] = $response;
        }

        // Отклоняем только первый response через индивидуальный endpoint
        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/task-responses/{$responses[0]->id}/reject", [
                'reason' => 'Individual reject',
            ])
            ->assertOk();

        $task->refresh();
        // Только один отклонён
        expect($task->responses()->where('status', 'rejected')->count())->toBe(1);
        // Остальные 2 всё ещё pending_review
        expect($task->responses()->where('status', 'pending_review')->count())->toBe(2);
    });

    it('deletes individual proof files on bulk reject', function () {
        Queue::fake();

        $employees = User::factory()->count(2)->create([
            'role' => Role::EMPLOYEE->value,
            'dealership_id' => $this->dealership->id,
        ]);

        $task = Task::factory()->create([
            'dealership_id' => $this->dealership->id,
            'task_type' => TaskType::GROUP->value,
            'response_type' => 'completion_with_proof',
        ]);

        $proofPaths = [];
        foreach ($employees as $employee) {
            $task->assignments()->create(['user_id' => $employee->id]);
            $response = $task->responses()->create([
                'user_id' => $employee->id,
                'status' => 'pending_review',
                'responded_at' => now(),
            ]);

            // Создаем proof файл
            $file = UploadedFile::fake()->image("proof_{$employee->id}.jpg");
            $path = $file->store("dealerships/{$this->dealership->id}/tasks/{$task->id}", 'task_proofs');
            $proofPaths[] = $path;

            TaskProof::create([
                'task_response_id' => $response->id,
                'file_path' => $path,
                'original_filename' => "proof_{$employee->id}.jpg",
                'mime_type' => 'image/jpeg',
                'file_size' => $file->getSize(),
            ]);
        }

        // Bulk reject
        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/tasks/{$task->id}/reject-all-responses", [
                'reason' => 'Удаление файлов',
            ])
            ->assertOk();

        // Проверяем, что записи proofs удалены из БД
        expect(TaskProof::whereIn('task_response_id', $task->responses->pluck('id'))->count())->toBe(0);

        // Проверяем, что Jobs для удаления файлов поставлены в очередь
        Queue::assertPushed(\App\Jobs\DeleteProofFileJob::class, count($proofPaths));
    });
});
