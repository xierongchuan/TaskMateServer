<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Jobs\StoreTaskProofsJob;
use App\Models\AutoDealership;
use App\Models\Task;
use App\Models\TaskProof;
use App\Models\TaskResponse;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

describe('StoreTaskProofsJob', function () {
    beforeEach(function () {
        Storage::fake();
        Storage::fake('task_proofs');

        $this->dealership = AutoDealership::factory()->create();
        $this->manager = User::factory()->create([
            'role' => Role::MANAGER->value,
            'dealership_id' => $this->dealership->id,
        ]);
        $this->employee = User::factory()->create([
            'role' => Role::EMPLOYEE->value,
            'dealership_id' => $this->dealership->id,
        ]);
        $this->task = Task::factory()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
        ]);
        $this->taskResponse = TaskResponse::create([
            'task_id' => $this->task->id,
            'user_id' => $this->employee->id,
            'status' => 'pending_review',
            'responded_at' => now(),
        ]);
    });

    describe('конфигурация', function () {
        it('использует очередь proof_upload', function () {
            $job = new StoreTaskProofsJob(1, [], 1, 1);

            expect($job->queue)->toBe('proof_upload');
        });

        it('имеет 3 попытки с задержкой 60 секунд', function () {
            $job = new StoreTaskProofsJob(1, [], 1, 1);

            expect($job->tries)->toBe(3);
            expect($job->backoff)->toBe(60);
        });
    });

    describe('handle', function () {
        it('сохраняет файл и создаёт запись TaskProof', function () {
            // Arrange
            $tempPath = 'temp/proof_uploads/test_file.jpg';
            Storage::put($tempPath, 'fake-image-content');

            $filesData = [[
                'path' => $tempPath,
                'original_name' => 'photo.jpg',
                'mime' => 'image/jpeg',
                'size' => 12345,
                'user_id' => $this->employee->id,
            ]];

            $job = new StoreTaskProofsJob(
                $this->taskResponse->id,
                $filesData,
                $this->dealership->id,
                $this->task->id,
            );

            // Act
            $job->handle();

            // Assert
            expect(TaskProof::count())->toBe(1);

            $proof = TaskProof::first();
            expect($proof->task_response_id)->toBe($this->taskResponse->id);
            expect($proof->original_filename)->toBe('photo.jpg');
            expect($proof->mime_type)->toBe('image/jpeg');
            expect($proof->file_size)->toBe(12345);

            // Файл перемещён на task_proofs disk
            Storage::disk('task_proofs')->assertExists($proof->file_path);

            // Temp файл удалён
            Storage::assertMissing($tempPath);
        });

        it('обрабатывает несколько файлов за один запуск', function () {
            // Arrange
            $filesData = [];
            for ($i = 1; $i <= 3; $i++) {
                $path = "temp/proof_uploads/file_{$i}.jpg";
                Storage::put($path, "content-{$i}");
                $filesData[] = [
                    'path' => $path,
                    'original_name' => "photo_{$i}.jpg",
                    'mime' => 'image/jpeg',
                    'size' => 1000 * $i,
                    'user_id' => $this->employee->id,
                ];
            }

            $job = new StoreTaskProofsJob(
                $this->taskResponse->id,
                $filesData,
                $this->dealership->id,
                $this->task->id,
            );

            // Act
            $job->handle();

            // Assert
            expect(TaskProof::count())->toBe(3);
        });

        it('очищает temp файлы если TaskResponse не найден', function () {
            // Arrange
            $tempPath = 'temp/proof_uploads/orphan.jpg';
            Storage::put($tempPath, 'content');

            $job = new StoreTaskProofsJob(
                99999, // несуществующий ID
                [['path' => $tempPath, 'original_name' => 'orphan.jpg', 'mime' => 'image/jpeg', 'size' => 100, 'user_id' => 1]],
                $this->dealership->id,
                $this->task->id,
            );

            // Act
            $job->handle();

            // Assert
            expect(TaskProof::count())->toBe(0);
            Storage::assertMissing($tempPath);
        });

        it('откатывает транзакцию при отсутствии temp файла', function () {
            // Arrange — первый файл существует, второй нет
            $tempPath1 = 'temp/proof_uploads/exists.jpg';
            Storage::put($tempPath1, 'content');

            $filesData = [
                ['path' => $tempPath1, 'original_name' => 'exists.jpg', 'mime' => 'image/jpeg', 'size' => 100, 'user_id' => $this->employee->id],
                ['path' => 'temp/proof_uploads/missing.jpg', 'original_name' => 'missing.jpg', 'mime' => 'image/jpeg', 'size' => 100, 'user_id' => $this->employee->id],
            ];

            $job = new StoreTaskProofsJob(
                $this->taskResponse->id,
                $filesData,
                $this->dealership->id,
                $this->task->id,
            );

            // Act & Assert
            expect(fn () => $job->handle())->toThrow(RuntimeException::class);
            expect(TaskProof::count())->toBe(0); // транзакция откатилась
        });

        it('генерирует путь в формате dealerships/{id}/tasks/{id}/YYYY/MM/DD/', function () {
            // Arrange
            $tempPath = 'temp/proof_uploads/test.jpg';
            Storage::put($tempPath, 'content');

            $job = new StoreTaskProofsJob(
                $this->taskResponse->id,
                [['path' => $tempPath, 'original_name' => 'test.jpg', 'mime' => 'image/jpeg', 'size' => 100, 'user_id' => $this->employee->id]],
                $this->dealership->id,
                $this->task->id,
            );

            // Act
            $job->handle();

            // Assert
            $proof = TaskProof::first();
            $expectedPrefix = sprintf(
                'dealerships/%d/tasks/%d/%s/',
                $this->dealership->id,
                $this->task->id,
                date('Y/m/d'),
            );
            expect($proof->file_path)->toStartWith($expectedPrefix);
            expect($proof->file_path)->toContain('proof_');
            expect($proof->file_path)->toEndWith('.jpg');
        });
    });

    describe('failed', function () {
        it('очищает temp файлы при окончательном провале', function () {
            // Arrange
            $tempPath = 'temp/proof_uploads/fail.jpg';
            Storage::put($tempPath, 'content');

            $job = new StoreTaskProofsJob(
                $this->taskResponse->id,
                [['path' => $tempPath, 'original_name' => 'fail.jpg', 'mime' => 'image/jpeg', 'size' => 100, 'user_id' => $this->employee->id]],
                $this->dealership->id,
                $this->task->id,
            );

            // Act
            $job->failed(new RuntimeException('Test failure'));

            // Assert
            Storage::assertMissing($tempPath);
        });
    });
});
