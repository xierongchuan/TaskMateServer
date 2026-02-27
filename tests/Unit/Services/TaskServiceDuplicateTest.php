<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\AutoDealership;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskService;
use Carbon\Carbon;

describe('TaskService Duplicate Detection', function () {
    beforeEach(function () {
        $this->dealership = AutoDealership::factory()->create();
        $this->manager = User::factory()->create([
            'role' => Role::MANAGER->value,
            'dealership_id' => $this->dealership->id,
        ]);
        $this->taskService = app(TaskService::class);
    });

    describe('Minute-Level Tolerance', function () {
        it('considers tasks with same deadline minute as duplicates', function () {
            // Arrange: Создаём задачу с deadline 10:30:45
            $deadline = Carbon::parse('2025-01-27T10:30:45Z');

            Task::factory()->create([
                'title' => 'Original Task',
                'task_type' => 'individual',
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
                'deadline' => $deadline,
                'description' => 'Test description',
            ]);

            // Act & Assert: Check with same minute (10:30:00)
            $isDuplicate1 = $this->taskService->isDuplicate([
                'title' => 'Original Task',
                'task_type' => 'individual',
                'dealership_id' => $this->dealership->id,
                'deadline' => Carbon::parse('2025-01-27T10:30:00Z'),
                'description' => 'Test description',
            ]);
            expect($isDuplicate1)->toBeTrue();

            // Act & Assert: Check with same minute (10:30:59)
            $isDuplicate2 = $this->taskService->isDuplicate([
                'title' => 'Original Task',
                'task_type' => 'individual',
                'dealership_id' => $this->dealership->id,
                'deadline' => Carbon::parse('2025-01-27T10:30:59Z'),
                'description' => 'Test description',
            ]);
            expect($isDuplicate2)->toBeTrue();
        });

        it('does not consider tasks with different deadline minute as duplicates', function () {
            // Arrange
            $deadline = Carbon::parse('2025-01-27T10:30:45Z');

            Task::factory()->create([
                'title' => 'Original Task',
                'task_type' => 'individual',
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
                'deadline' => $deadline,
                'description' => 'Test description',
            ]);

            // Act & Assert: Check with different minute (10:31:00)
            $isDuplicate = $this->taskService->isDuplicate([
                'title' => 'Original Task',
                'task_type' => 'individual',
                'dealership_id' => $this->dealership->id,
                'deadline' => Carbon::parse('2025-01-27T10:31:00Z'),
                'description' => 'Test description',
            ]);
            expect($isDuplicate)->toBeFalse();
        });

        it('does not consider tasks with deadline 1 minute before as duplicates', function () {
            // Arrange
            $deadline = Carbon::parse('2025-01-27T10:30:45Z');

            Task::factory()->create([
                'title' => 'Original Task',
                'task_type' => 'individual',
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
                'deadline' => $deadline,
                'description' => 'Test description',
            ]);

            // Act & Assert: Check with 1 minute before (10:29:00)
            $isDuplicate = $this->taskService->isDuplicate([
                'title' => 'Original Task',
                'task_type' => 'individual',
                'dealership_id' => $this->dealership->id,
                'deadline' => Carbon::parse('2025-01-27T10:29:00Z'),
                'description' => 'Test description',
            ]);
            expect($isDuplicate)->toBeFalse();
        });
    });

    describe('Field Matching', function () {
        it('requires matching title for duplicate', function () {
            // Arrange
            Task::factory()->create([
                'title' => 'Task A',
                'task_type' => 'individual',
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
                'deadline' => Carbon::parse('2025-01-27T10:30:00Z'),
                'description' => 'Description',
            ]);

            // Act & Assert: Different title is not duplicate
            $isDuplicate = $this->taskService->isDuplicate([
                'title' => 'Task B', // Different!
                'task_type' => 'individual',
                'dealership_id' => $this->dealership->id,
                'deadline' => Carbon::parse('2025-01-27T10:30:00Z'),
                'description' => 'Description',
            ]);
            expect($isDuplicate)->toBeFalse();
        });

        it('requires matching task_type for duplicate', function () {
            // Arrange
            Task::factory()->create([
                'title' => 'Task',
                'task_type' => 'individual',
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
                'deadline' => Carbon::parse('2025-01-27T10:30:00Z'),
            ]);

            // Act & Assert: Different task_type is not duplicate
            $isDuplicate = $this->taskService->isDuplicate([
                'title' => 'Task',
                'task_type' => 'group', // Different!
                'dealership_id' => $this->dealership->id,
                'deadline' => Carbon::parse('2025-01-27T10:30:00Z'),
            ]);
            expect($isDuplicate)->toBeFalse();
        });

        it('requires matching dealership_id for duplicate', function () {
            // Arrange
            $otherDealership = AutoDealership::factory()->create();

            Task::factory()->create([
                'title' => 'Task',
                'task_type' => 'individual',
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
                'deadline' => Carbon::parse('2025-01-27T10:30:00Z'),
            ]);

            // Act & Assert: Different dealership is not duplicate
            $isDuplicate = $this->taskService->isDuplicate([
                'title' => 'Task',
                'task_type' => 'individual',
                'dealership_id' => $otherDealership->id, // Different!
                'deadline' => Carbon::parse('2025-01-27T10:30:00Z'),
            ]);
            expect($isDuplicate)->toBeFalse();
        });
    });

    describe('NULL Handling', function () {
        it('handles null dealership_id correctly', function () {
            // Arrange: Task с null dealership и null description
            Task::factory()->create([
                'title' => 'Global Task',
                'task_type' => 'individual',
                'dealership_id' => null, // NULL!
                'creator_id' => $this->manager->id,
                'deadline' => Carbon::parse('2025-01-27T10:30:00Z'),
                'description' => null, // Важно: isDuplicate проверяет description
            ]);

            // Act & Assert: null dealership matches null
            $isDuplicate = $this->taskService->isDuplicate([
                'title' => 'Global Task',
                'task_type' => 'individual',
                'dealership_id' => null,
                'deadline' => Carbon::parse('2025-01-27T10:30:00Z'),
                'description' => null,
            ]);
            expect($isDuplicate)->toBeTrue();
        });

        it('does not match null dealership with non-null', function () {
            // Arrange
            Task::factory()->create([
                'title' => 'Task',
                'task_type' => 'individual',
                'dealership_id' => null,
                'creator_id' => $this->manager->id,
                'deadline' => Carbon::parse('2025-01-27T10:30:00Z'),
                'description' => null,
            ]);

            // Act & Assert: null does not match specific dealership
            $isDuplicate = $this->taskService->isDuplicate([
                'title' => 'Task',
                'task_type' => 'individual',
                'dealership_id' => $this->dealership->id,
                'deadline' => Carbon::parse('2025-01-27T10:30:00Z'),
                'description' => null,
            ]);
            expect($isDuplicate)->toBeFalse();
        });

        it('handles null description correctly', function () {
            // Arrange
            Task::factory()->create([
                'title' => 'Task',
                'task_type' => 'individual',
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
                'deadline' => Carbon::parse('2025-01-27T10:30:00Z'),
                'description' => null,
            ]);

            // Act & Assert: null description matches null
            $isDuplicate = $this->taskService->isDuplicate([
                'title' => 'Task',
                'task_type' => 'individual',
                'dealership_id' => $this->dealership->id,
                'deadline' => Carbon::parse('2025-01-27T10:30:00Z'),
                'description' => null,
            ]);
            expect($isDuplicate)->toBeTrue();
        });
    });

    describe('Edge Cases', function () {
        it('does not consider soft deleted tasks as duplicates', function () {
            // Arrange: Soft deleted task
            $task = Task::factory()->create([
                'title' => 'Deleted Task',
                'task_type' => 'individual',
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
                'deadline' => Carbon::parse('2025-01-27T10:30:00Z'),
                'description' => null,
            ]);
            $task->delete();

            // Act & Assert: Soft deleted is not duplicate
            $isDuplicate = $this->taskService->isDuplicate([
                'title' => 'Deleted Task',
                'task_type' => 'individual',
                'dealership_id' => $this->dealership->id,
                'deadline' => Carbon::parse('2025-01-27T10:30:00Z'),
                'description' => null,
            ]);
            expect($isDuplicate)->toBeFalse();
        });

        it('handles midnight boundary correctly', function () {
            // Arrange: Task at 23:59
            Task::factory()->create([
                'title' => 'Late Task',
                'task_type' => 'individual',
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
                'deadline' => Carbon::parse('2025-01-27T23:59:30Z'),
                'description' => null,
            ]);

            // Act & Assert: Same minute is duplicate
            $isDuplicate1 = $this->taskService->isDuplicate([
                'title' => 'Late Task',
                'task_type' => 'individual',
                'dealership_id' => $this->dealership->id,
                'deadline' => Carbon::parse('2025-01-27T23:59:00Z'),
                'description' => null,
            ]);
            expect($isDuplicate1)->toBeTrue();

            // Act & Assert: Next day is not duplicate
            $isDuplicate2 = $this->taskService->isDuplicate([
                'title' => 'Late Task',
                'task_type' => 'individual',
                'dealership_id' => $this->dealership->id,
                'deadline' => Carbon::parse('2025-01-28T00:00:00Z'),
                'description' => null,
            ]);
            expect($isDuplicate2)->toBeFalse();
        });
    });
});
