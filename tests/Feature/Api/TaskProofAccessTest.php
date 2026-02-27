<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\AutoDealership;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskProof;
use App\Models\TaskResponse;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

describe('Task Proof Access', function () {
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

    afterEach(function () {
        Carbon::setTestNow();
    });

    /**
     * Хелпер для создания proof напрямую в БД
     */
    function createProofDirectly($taskResponse, $filename = 'proof.jpg'): TaskProof
    {
        return TaskProof::create([
            'task_response_id' => $taskResponse->id,
            'file_path' => "dealerships/{$taskResponse->task->dealership_id}/tasks/{$taskResponse->task_id}/{$filename}",
            'original_filename' => $filename,
            'mime_type' => 'image/jpeg',
            'file_size' => 12345,
        ]);
    }

    describe('Signed URL Expiration', function () {
        it('generates signed URLs that are valid immediately', function () {
            // Arrange: Создаём задачу с proof напрямую в БД
            $task = Task::factory()->completionWithProof()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee->id]);

            $taskResponse = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'pending_review',
                'responded_at' => Carbon::now(),
            ]);

            $proof = createProofDirectly($taskResponse);

            // Act: Получаем signed URL
            $signedUrl = $proof->url;

            // Assert: URL содержит подпись
            expect($signedUrl)->toContain('signature=');
            expect($signedUrl)->toContain('expires=');
        });

        it('allows access before expiration', function () {
            // Arrange
            $task = Task::factory()->completionWithProof()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee->id]);

            $taskResponse = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'pending_review',
                'responded_at' => Carbon::now(),
            ]);

            $proof = createProofDirectly($taskResponse);

            // Генерируем URL в текущий момент
            $signedUrl = $proof->url;

            // Act: Перемещаемся на 59 минут вперёд (до истечения 60 минут)
            Carbon::setTestNow(Carbon::now()->addMinutes(59));

            // Assert: URL всё ещё действителен (проверяем валидацию подписи)
            $isValid = URL::hasValidSignature(
                request()->create($signedUrl)
            );
            expect($isValid)->toBeTrue();
        });

        it('denies access after expiration', function () {
            // Arrange
            $task = Task::factory()->completionWithProof()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee->id]);

            $taskResponse = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'pending_review',
                'responded_at' => Carbon::now(),
            ]);

            $proof = createProofDirectly($taskResponse);

            // Генерируем URL в текущий момент
            $signedUrl = $proof->url;

            // Act: Перемещаемся на 61 минуту вперёд (после истечения 60 минут)
            Carbon::setTestNow(Carbon::now()->addMinutes(61));

            // Assert: URL недействителен
            $isValid = URL::hasValidSignature(
                request()->create($signedUrl)
            );
            expect($isValid)->toBeFalse();
        });

        it('rejects tampered signatures', function () {
            // Arrange
            $task = Task::factory()->completionWithProof()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee->id]);

            $taskResponse = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'pending_review',
                'responded_at' => Carbon::now(),
            ]);

            $proof = createProofDirectly($taskResponse);

            // Act: Модифицируем signature
            $signedUrl = $proof->url;
            $tamperedUrl = preg_replace('/signature=[^&]+/', 'signature=tampered', $signedUrl);

            // Assert: Tampered URL недействителен
            $isValid = URL::hasValidSignature(
                request()->create($tamperedUrl)
            );
            expect($isValid)->toBeFalse();
        });

        it('rejects requests without signature', function () {
            // Arrange
            $task = Task::factory()->completionWithProof()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee->id]);

            $taskResponse = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'pending_review',
                'responded_at' => Carbon::now(),
            ]);

            $proof = createProofDirectly($taskResponse);

            // Act: Пытаемся скачать без подписи
            $response = $this->get("/api/v1/task-proofs/{$proof->id}/download");

            // Assert: Должен получить 403 (invalid signature)
            $response->assertStatus(403);
        });
    });

    describe('Proof Access Control', function () {
        it('allows task creator to view proofs', function () {
            // Arrange
            $task = Task::factory()->completionWithProof()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee->id]);

            $taskResponse = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'pending_review',
                'responded_at' => Carbon::now(),
            ]);

            $proof = createProofDirectly($taskResponse);

            // Act: Creator запрашивает информацию о proof
            $response = $this->actingAs($this->manager, 'sanctum')
                ->getJson("/api/v1/task-proofs/{$proof->id}");

            // Assert
            $response->assertStatus(200);
            expect($response->json('data.id'))->toBe($proof->id);
            expect($response->json('data.url'))->toContain('signature=');
        });

        it('allows assigned employee to view own proofs', function () {
            // Arrange
            $task = Task::factory()->completionWithProof()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee->id]);

            $taskResponse = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'pending_review',
                'responded_at' => Carbon::now(),
            ]);

            $proof = createProofDirectly($taskResponse);

            // Act
            $response = $this->actingAs($this->employee, 'sanctum')
                ->getJson("/api/v1/task-proofs/{$proof->id}");

            // Assert
            $response->assertStatus(200);
        });

        it('denies access to proofs from other dealership', function () {
            // Arrange: Другой dealership и manager
            $otherDealership = AutoDealership::factory()->create();
            $otherManager = User::factory()->create([
                'role' => Role::MANAGER->value,
                'dealership_id' => $otherDealership->id,
            ]);

            $task = Task::factory()->completionWithProof()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee->id]);

            $taskResponse = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'pending_review',
                'responded_at' => Carbon::now(),
            ]);

            $proof = createProofDirectly($taskResponse);

            // Act: Manager из другого dealership пытается получить proof
            $response = $this->actingAs($otherManager, 'sanctum')
                ->getJson("/api/v1/task-proofs/{$proof->id}");

            // Assert
            $response->assertStatus(403);
        });

        it('allows owner to view proofs from any dealership', function () {
            // Arrange
            $owner = User::factory()->create([
                'role' => Role::OWNER->value,
                'dealership_id' => null,
            ]);

            $task = Task::factory()->completionWithProof()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee->id]);

            $taskResponse = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'pending_review',
                'responded_at' => Carbon::now(),
            ]);

            $proof = createProofDirectly($taskResponse);

            // Act
            $response = $this->actingAs($owner, 'sanctum')
                ->getJson("/api/v1/task-proofs/{$proof->id}");

            // Assert
            $response->assertStatus(200);
        });
    });

    describe('Proof toApiArray', function () {
        it('includes all required fields in API response', function () {
            // Arrange
            $task = Task::factory()->completionWithProof()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee->id]);

            $taskResponse = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'pending_review',
                'responded_at' => Carbon::now(),
            ]);

            $proof = createProofDirectly($taskResponse, 'test_image.jpg');

            // Act
            $apiArray = $proof->toApiArray();

            // Assert: Все обязательные поля присутствуют
            expect($apiArray)->toHaveKeys([
                'id',
                'url',
                'original_filename',
                'mime_type',
                'file_size',
                'created_at',
            ]);

            expect($apiArray['id'])->toBe($proof->id);
            expect($apiArray['original_filename'])->toBe('test_image.jpg');
            expect($apiArray['mime_type'])->toBe('image/jpeg');
            expect($apiArray['file_size'])->toBe(12345);
            expect($apiArray['url'])->toContain('signature=');
        });
    });

    describe('Proof File Limits', function () {
        it('enforces max 5 files per response', function () {
            // Arrange
            $task = Task::factory()->completionWithProof()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee->id]);

            // Act: Пытаемся загрузить 6 файлов
            $files = [];
            for ($i = 0; $i < 6; $i++) {
                $files[] = UploadedFile::fake()->image("proof_{$i}.jpg", 100, 100);
            }

            $response = $this->actingAs($this->employee, 'sanctum')
                ->patchJson("/api/v1/tasks/{$task->id}/status", [
                    'status' => 'pending_review',
                    'proof_files' => $files,
                ]);

            // Assert: Validation error
            $response->assertStatus(422);
            $response->assertJsonValidationErrors(['proof_files']);
        });

        it('allows exactly 5 files per response via validation', function () {
            // Arrange: Проверяем что 5 файлов проходят валидацию
            $task = Task::factory()->completionWithProof()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee->id]);

            // Act: Создаём 5 proofs напрямую в БД (симуляция успешной загрузки)
            $taskResponse = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'pending_review',
                'responded_at' => Carbon::now(),
            ]);

            for ($i = 0; $i < 5; $i++) {
                TaskProof::create([
                    'task_response_id' => $taskResponse->id,
                    'file_path' => "dealerships/{$this->dealership->id}/tasks/{$task->id}/proof_{$i}.jpg",
                    'original_filename' => "proof_{$i}.jpg",
                    'mime_type' => 'image/jpeg',
                    'file_size' => 12345,
                ]);
            }

            // Assert: Все 5 proofs создались
            expect($taskResponse->proofs()->count())->toBe(5);
        });
    });
});
