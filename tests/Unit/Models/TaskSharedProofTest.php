<?php

declare(strict_types=1);

use App\Models\Task;
use App\Models\TaskSharedProof;
use App\Models\AutoDealership;
use App\Models\User;
use App\Enums\Role;

describe('TaskSharedProof Model', function () {
    beforeEach(function () {
        $this->dealership = AutoDealership::factory()->create();
        $this->employee = User::factory()->create([
            'role' => Role::EMPLOYEE->value,
            'dealership_id' => $this->dealership->id
        ]);
    });

    it('can be created', function () {
        // Arrange
        $task = Task::factory()->create(['dealership_id' => $this->dealership->id]);

        // Act
        $sharedProof = TaskSharedProof::factory()->create([
            'task_id' => $task->id,
            'file_path' => 'shared/proof.jpg',
            'original_filename' => 'team_photo.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 12345,
        ]);

        // Assert
        expect($sharedProof)->toBeInstanceOf(TaskSharedProof::class);
        expect($sharedProof->task_id)->toBe($task->id);
        expect($sharedProof->original_filename)->toBe('team_photo.jpg');
    });

    describe('task relationship', function () {
        it('belongs to task', function () {
            // Arrange
            $task = Task::factory()->create(['dealership_id' => $this->dealership->id]);
            $sharedProof = TaskSharedProof::factory()->create([
                'task_id' => $task->id,
            ]);

            // Act & Assert
            expect($sharedProof->task)->toBeInstanceOf(Task::class);
            expect($sharedProof->task->id)->toBe($task->id);
        });
    });

    describe('url attribute', function () {
        it('generates signed URL', function () {
            // Arrange
            $task = Task::factory()->create(['dealership_id' => $this->dealership->id]);
            $sharedProof = TaskSharedProof::factory()->create([
                'task_id' => $task->id,
                'file_path' => 'shared/test.jpg',
            ]);

            // Act
            $url = $sharedProof->url;

            // Assert
            expect($url)->toBeString();
            expect($url)->toContain('signature=');
        });
    });

    describe('toApiArray', function () {
        it('returns formatted array', function () {
            // Arrange
            $task = Task::factory()->create(['dealership_id' => $this->dealership->id]);
            $sharedProof = TaskSharedProof::factory()->create([
                'task_id' => $task->id,
                'file_path' => 'shared/document.pdf',
                'original_filename' => 'report.pdf',
                'mime_type' => 'application/pdf',
                'file_size' => 54321,
            ]);

            // Act
            $array = $sharedProof->toApiArray();

            // Assert
            expect($array)->toHaveKeys(['id', 'url', 'original_filename', 'mime_type', 'file_size', 'created_at']);
            expect($array['original_filename'])->toBe('report.pdf');
            expect($array['mime_type'])->toBe('application/pdf');
        });
    });
});
