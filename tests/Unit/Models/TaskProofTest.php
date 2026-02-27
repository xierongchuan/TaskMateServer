<?php

declare(strict_types=1);

use App\Models\Task;
use App\Models\TaskProof;
use App\Models\TaskResponse;
use App\Models\AutoDealership;
use App\Models\User;
use App\Enums\Role;
use Carbon\Carbon;

describe('TaskProof Model', function () {
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
        $response = TaskResponse::create([
            'task_id' => $task->id,
            'user_id' => $this->employee->id,
            'status' => 'pending',
            'responded_at' => Carbon::now(),
        ]);

        // Act
        $proof = TaskProof::create([
            'task_response_id' => $response->id,
            'file_path' => 'proofs/test.jpg',
            'original_filename' => 'photo.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 12345,
        ]);

        // Assert
        expect($proof)->toBeInstanceOf(TaskProof::class);
        expect($proof->task_response_id)->toBe($response->id);
        expect($proof->original_filename)->toBe('photo.jpg');
        expect($proof->mime_type)->toBe('image/jpeg');
        expect($proof->file_size)->toBe(12345);
    });

    describe('taskResponse relationship', function () {
        it('belongs to task response', function () {
            // Arrange
            $task = Task::factory()->create(['dealership_id' => $this->dealership->id]);
            $response = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'pending',
                'responded_at' => Carbon::now(),
            ]);
            $proof = TaskProof::create([
                'task_response_id' => $response->id,
                'file_path' => 'proofs/test.jpg',
                'original_filename' => 'photo.jpg',
                'mime_type' => 'image/jpeg',
                'file_size' => 12345,
            ]);

            // Act & Assert
            expect($proof->taskResponse)->toBeInstanceOf(TaskResponse::class);
            expect($proof->taskResponse->id)->toBe($response->id);
        });
    });

    describe('url attribute', function () {
        it('generates signed URL', function () {
            // Arrange
            $task = Task::factory()->create(['dealership_id' => $this->dealership->id]);
            $response = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'pending',
                'responded_at' => Carbon::now(),
            ]);
            $proof = TaskProof::create([
                'task_response_id' => $response->id,
                'file_path' => 'proofs/test.jpg',
                'original_filename' => 'photo.jpg',
                'mime_type' => 'image/jpeg',
                'file_size' => 12345,
            ]);

            // Act
            $url = $proof->url;

            // Assert
            expect($url)->toBeString();
            expect($url)->toContain('signature=');
        });
    });

    describe('toApiArray', function () {
        it('returns formatted array', function () {
            // Arrange
            $task = Task::factory()->create(['dealership_id' => $this->dealership->id]);
            $response = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'pending',
                'responded_at' => Carbon::now(),
            ]);
            $proof = TaskProof::create([
                'task_response_id' => $response->id,
                'file_path' => 'proofs/test.jpg',
                'original_filename' => 'document.pdf',
                'mime_type' => 'application/pdf',
                'file_size' => 54321,
            ]);

            // Act
            $array = $proof->toApiArray();

            // Assert
            expect($array)->toHaveKeys(['id', 'url', 'original_filename', 'mime_type', 'file_size', 'created_at']);
            expect($array['original_filename'])->toBe('document.pdf');
            expect($array['mime_type'])->toBe('application/pdf');
            expect($array['file_size'])->toBe(54321);
        });
    });

    describe('casts', function () {
        it('casts file_size to integer', function () {
            // Arrange
            $task = Task::factory()->create(['dealership_id' => $this->dealership->id]);
            $response = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'pending',
                'responded_at' => Carbon::now(),
            ]);
            $proof = TaskProof::create([
                'task_response_id' => $response->id,
                'file_path' => 'proofs/test.jpg',
                'original_filename' => 'photo.jpg',
                'mime_type' => 'image/jpeg',
                'file_size' => '12345', // String
            ]);

            // Act & Assert
            expect($proof->file_size)->toBeInt();
        });
    });
});
