<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Enums\TaskType;
use App\Jobs\StoreTaskSharedProofsJob;
use App\Models\AutoDealership;
use App\Models\Task;
use App\Models\TaskSharedProof;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

describe('Task Shared Proofs', function () {
    beforeEach(function () {
        Storage::fake('local');
        Storage::fake('task_proofs');
        $this->dealership = AutoDealership::factory()->create();
        $this->manager = User::factory()->create([
            'role' => Role::MANAGER->value,
            'dealership_id' => $this->dealership->id
        ]);
    });

    it('dispatches job when manager completes task for all with files', function () {
        Queue::fake();

        $employees = User::factory()->count(3)->create([
            'role' => Role::EMPLOYEE->value,
            'dealership_id' => $this->dealership->id
        ]);

        $task = Task::factory()->create([
            'dealership_id' => $this->dealership->id,
            'task_type' => TaskType::GROUP->value,
            'response_type' => 'completion_with_proof',
        ]);

        foreach ($employees as $employee) {
            $task->assignments()->create(['user_id' => $employee->id]);
        }

        $files = [
            UploadedFile::fake()->image('proof1.jpg'),
            UploadedFile::fake()->image('proof2.jpg'),
        ];

        $this->actingAs($this->manager, 'sanctum')
            ->patch("/api/v1/tasks/{$task->id}/status", [
                'status' => 'completed',
                'complete_for_all' => true,
                'proof_files' => $files,
            ])
            ->assertOk();

        // Проверяем, что Job был отправлен
        Queue::assertPushed(StoreTaskSharedProofsJob::class, function ($job) {
            return count($job->filesData) === 2;
        });

        // Проверяем, что созданы responses для всех
        $task->refresh();
        expect($task->responses)->toHaveCount(3);
    });

    it('stores shared proof files correctly', function () {
        $task = Task::factory()->create([
            'task_type' => TaskType::GROUP->value,
            'dealership_id' => $this->dealership->id,
        ]);

        $file = UploadedFile::fake()->image('test.jpg', 100, 100);
        $tempPath = $file->store('temp/task_proofs');

        $filesData = [[
            'path' => $tempPath,
            'original_name' => 'test.jpg',
            'mime' => 'image/jpeg',
            'size' => $file->getSize(),
        ]];

        $job = new StoreTaskSharedProofsJob(
            $task->id,
            $filesData,
            $task->dealership_id
        );
        // Вызов handle() через контейнер для внедрения зависимостей
        app()->call([$job, 'handle']);

        // Проверяем результат
        $task->refresh();
        expect($task->sharedProofs)->toHaveCount(1);

        $proof = $task->sharedProofs->first();
        expect($proof->original_filename)->toBe('test.jpg');
        expect($proof->mime_type)->toBe('image/jpeg');

        // Проверяем физическое существование файла на диске task_proofs
        Storage::disk('task_proofs')->assertExists($proof->file_path);
    });

    it('includes shared proofs in task API response', function () {
        $task = Task::factory()->create([
            'dealership_id' => $this->dealership->id,
            'task_type' => TaskType::GROUP->value,
        ]);

        // Создаем общий файл
        $file = UploadedFile::fake()->image('shared.jpg');
        $path = $file->store("private/task_proofs/{$this->dealership->id}");

        TaskSharedProof::create([
            'task_id' => $task->id,
            'file_path' => $path,
            'original_filename' => 'shared.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => $file->getSize(),
        ]);

        // Запрос к API
        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/tasks/{$task->id}")
            ->assertOk();

        // Проверяем наличие shared_proofs в ответе
        $json = $response->json();
        expect($json)->toHaveKey('shared_proofs');
        expect($json['shared_proofs'])->toHaveCount(1);
        expect($json['shared_proofs'][0]['original_filename'])->toBe('shared.jpg');
    });

    it('respects max files limit for shared proofs', function () {
        $task = Task::factory()->create([
            'task_type' => TaskType::GROUP->value,
            'dealership_id' => $this->dealership->id,
        ]);

        // Создаем 5 файлов (максимум)
        for ($i = 1; $i <= 5; $i++) {
            $file = UploadedFile::fake()->image("file{$i}.jpg");
            $path = $file->store("private/task_proofs/{$this->dealership->id}");

            TaskSharedProof::create([
                'task_id' => $task->id,
                'file_path' => $path,
                'original_filename' => "file{$i}.jpg",
                'mime_type' => 'image/jpeg',
                'file_size' => $file->getSize(),
            ]);
        }

        // Попытка добавить 6-й файл
        $file = UploadedFile::fake()->image('file6.jpg');
        $tempPath = $file->store('temp/task_proofs');

        $filesData = [[
            'path' => $tempPath,
            'original_name' => 'file6.jpg',
            'mime' => 'image/jpeg',
            'size' => $file->getSize(),
        ]];

        $job = new StoreTaskSharedProofsJob(
            $task->id,
            $filesData,
            $task->dealership_id
        );
        // Вызов handle() через контейнер для внедрения зависимостей
        app()->call([$job, 'handle']);

        // Проверяем, что файл не был добавлен
        $task->refresh();
        expect($task->sharedProofs)->toHaveCount(5); // Только 5 файлов
    });

    it('validates MIME types for shared proofs', function () {
        $task = Task::factory()->create([
            'task_type' => TaskType::GROUP->value,
            'dealership_id' => $this->dealership->id,
        ]);

        // Попытка загрузить неподдерживаемый тип файла
        $file = UploadedFile::fake()->create('test.exe', 1000);
        $tempPath = $file->store('temp/task_proofs');

        $filesData = [[
            'path' => $tempPath,
            'original_name' => 'test.exe',
            'mime' => 'application/x-msdownload',
            'size' => $file->getSize(),
        ]];

        $job = new StoreTaskSharedProofsJob(
            $task->id,
            $filesData,
            $task->dealership_id
        );
        // Вызов handle() через контейнер для внедрения зависимостей
        app()->call([$job, 'handle']);

        // Проверяем, что файл не был добавлен
        $task->refresh();
        expect($task->sharedProofs)->toHaveCount(0);
    });

    it('preserves shared proofs when manager rejects single response', function () {
        $task = Task::factory()->create([
            'dealership_id' => $this->dealership->id,
            'task_type' => TaskType::GROUP->value,
            'response_type' => 'completion_with_proof',
        ]);

        $employee = User::factory()->create([
            'role' => Role::EMPLOYEE->value,
            'dealership_id' => $this->dealership->id,
        ]);

        $task->assignments()->create(['user_id' => $employee->id]);

        // Создаем response со shared proofs
        $response = $task->responses()->create([
            'user_id' => $employee->id,
            'status' => 'pending_review',
            'responded_at' => now(),
            'submission_source' => 'shared',
            'uses_shared_proofs' => true,
        ]);

        // Создаем общий файл
        $file = UploadedFile::fake()->image('shared.jpg');
        $path = $file->store("private/task_proofs/{$this->dealership->id}", 'local');

        TaskSharedProof::create([
            'task_id' => $task->id,
            'file_path' => $path,
            'original_filename' => 'shared.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => $file->getSize(),
        ]);

        Storage::disk('local')->assertExists($path);
        expect($task->sharedProofs)->toHaveCount(1);

        // Отклоняем
        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/task-responses/{$response->id}/reject", [
                'reason' => 'Bad quality',
            ])
            ->assertOk();

        // Проверяем, что shared proofs УДАЛЕНЫ
        $task->refresh();
        expect($task->sharedProofs)->toHaveCount(0);

        // Проверяем, что response переключен на индивидуальный режим
        $response->refresh();
        expect($response->status)->toBe('rejected');
        expect($response->uses_shared_proofs)->toBeFalse();
    });

    it('rejects only selected response when rejecting one from group task with shared proofs', function () {
        // Создаем групповую задачу с 3 исполнителями
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

        // Создаем responses для всех (как при complete_for_all)
        foreach ($employees as $employee) {
            $task->responses()->create([
                'user_id' => $employee->id,
                'status' => 'pending_review',
                'responded_at' => now(),
                'submission_source' => 'shared',
                'uses_shared_proofs' => true,
            ]);
        }

        // Создаем shared proof (как при complete_for_all)
        $file = UploadedFile::fake()->image('shared.jpg');
        $path = $file->store("private/task_proofs/{$this->dealership->id}", 'local');

        TaskSharedProof::create([
            'task_id' => $task->id,
            'file_path' => $path,
            'original_filename' => 'shared.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => $file->getSize(),
        ]);

        // Проверяем начальное состояние
        $task->refresh();
        expect($task->responses()->where('status', 'pending_review')->count())->toBe(3);
        expect($task->sharedProofs)->toHaveCount(1);

        // Отклоняем ОДИН response
        $firstResponse = $task->responses()->first();
        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/task-responses/{$firstResponse->id}/reject", [
                'reason' => 'Bad quality',
            ])
            ->assertOk();

        // Проверяем, что отклонен ТОЛЬКО ОДИН response
        $task->refresh();
        expect($task->responses()->where('status', 'rejected')->count())->toBe(1);
        expect($task->responses()->where('status', 'pending_review')->count())->toBe(2);

        // Проверяем, что shared proofs УДАЛЕНЫ
        expect($task->sharedProofs)->toHaveCount(0);

        // Проверяем состояние отклоненного response
        $firstResponse->refresh();
        expect($firstResponse->status)->toBe('rejected');
        expect($firstResponse->rejection_reason)->toBe('Bad quality');
        expect($firstResponse->uses_shared_proofs)->toBeFalse();
    });

    it('deletes shared proofs when bulk rejecting all responses', function () {
        Queue::fake();

        // Создаем групповую задачу с 3 исполнителями
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

        // Создаем responses для всех (как при complete_for_all)
        foreach ($employees as $employee) {
            $task->responses()->create([
                'user_id' => $employee->id,
                'status' => 'pending_review',
                'responded_at' => now(),
                'submission_source' => 'shared',
                'uses_shared_proofs' => true,
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

        // Проверяем начальное состояние
        $task->refresh();
        expect($task->responses()->where('status', 'pending_review')->count())->toBe(3);
        expect($task->sharedProofs)->toHaveCount(1);

        // Отклоняем ВСЕ responses через bulk reject
        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/tasks/{$task->id}/reject-all-responses", [
                'reason' => 'Bad quality',
            ])
            ->assertOk();

        // Проверяем, что ВСЕ responses отклонены
        $task->refresh();
        expect($task->responses()->where('status', 'rejected')->count())->toBe(3);
        expect($task->responses()->where('status', 'pending_review')->count())->toBe(0);

        // Проверяем, что записи shared proofs удалены из БД
        expect($task->sharedProofs)->toHaveCount(0);

        // Проверяем, что Job для удаления файла был поставлен в очередь
        Queue::assertPushed(\App\Jobs\DeleteProofFileJob::class);

        // Проверяем, что у всех responses одинаковая причина отклонения
        foreach ($task->responses as $response) {
            expect($response->status)->toBe('rejected');
            expect($response->rejection_reason)->toBe('Bad quality');
            expect($response->uses_shared_proofs)->toBeFalse();
        }
    });
});
