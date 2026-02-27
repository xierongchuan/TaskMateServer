<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Task;
use App\Models\AutoDealership;
use App\Enums\Role;
use Carbon\Carbon;

describe('Task API', function () {
    beforeEach(function () {
        $this->dealership = AutoDealership::factory()->create();
        $this->manager = User::factory()->create([
            'role' => Role::MANAGER->value,
            'dealership_id' => $this->dealership->id
        ]);
    });

    it('returns tasks list', function () {
        // Arrange
        Task::factory(3)->create(['dealership_id' => $this->dealership->id]);

        // Act
        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/tasks?dealership_id={$this->dealership->id}");

        // Assert
        $response->assertStatus(200);
        expect($response->json('data'))->toHaveCount(3);
    });

    it('creates a task', function () {
        // Arrange
        $user = User::factory()->create(['role' => Role::EMPLOYEE->value, 'dealership_id' => $this->dealership->id]);

        // Act
        $response = $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/v1/tasks', [
                'title' => 'New Task',
                'description' => 'Task Description',
                'dealership_id' => $this->dealership->id,
                'assigned_users' => [$user->id],
                'appear_date' => Carbon::now()->toIso8601String(),
                'deadline' => Carbon::now()->addDay()->toIso8601String(),
                'task_type' => 'individual',
                'response_type' => 'completion',
            ]);

        // Assert
        $response->assertStatus(201);
        $this->assertDatabaseHas('tasks', [
            'title' => 'New Task',
            'dealership_id' => $this->dealership->id,
        ]);
    });

    it('updates a task', function () {
        // Arrange
        $task = Task::factory()->create(['dealership_id' => $this->dealership->id]);

        // Act
        $response = $this->actingAs($this->manager, 'sanctum')
            ->putJson("/api/v1/tasks/{$task->id}", [
                'title' => 'Updated Task',
            ]);

        // Assert
        $response->assertStatus(200);
        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'title' => 'Updated Task',
        ]);
    });

    it('deletes a task', function () {
        // Arrange
        $task = Task::factory()->create(['dealership_id' => $this->dealership->id]);

        // Act
        $response = $this->actingAs($this->manager, 'sanctum')
            ->deleteJson("/api/v1/tasks/{$task->id}");

        // Assert
        $response->assertStatus(200);
        // With SoftDeletes, the task should be soft deleted (deleted_at is not null)
        $this->assertSoftDeleted('tasks', ['id' => $task->id]);
    });
    it('creates a task with tags', function () {
        // Arrange
        $user = User::factory()->create(['role' => Role::EMPLOYEE->value, 'dealership_id' => $this->dealership->id]);
        $tags = ['urgent', 'backend'];

        // Act
        $response = $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/v1/tasks', [
                'title' => 'Task with Tags',
                'description' => 'Description',
                'dealership_id' => $this->dealership->id,
                'assigned_users' => [$user->id],
                'appear_date' => Carbon::now()->toIso8601String(),
                'deadline' => Carbon::now()->addDay()->toIso8601String(),
                'task_type' => 'individual',
                'response_type' => 'completion',
                'tags' => $tags,
            ]);

        // Assert
        $response->assertStatus(201);
        $this->assertDatabaseHas('tasks', [
            'title' => 'Task with Tags',
            'dealership_id' => $this->dealership->id,
        ]);

        $task = Task::where('title', 'Task with Tags')->first();
        expect($task->tags)->toBe($tags);
    });

    it('updates task tags', function () {
        // Arrange
        $task = Task::factory()->create([
            'dealership_id' => $this->dealership->id,
            'tags' => ['old_tag']
        ]);
        $newTags = ['new_tag', 'updated'];

        // Act
        $response = $this->actingAs($this->manager, 'sanctum')
            ->putJson("/api/v1/tasks/{$task->id}", [
                'tags' => $newTags,
            ]);

        // Assert
        $response->assertStatus(200);
        $task->refresh();
        expect($task->tags)->toBe($newTags);
    });

    it('searches tasks by tag text', function () {
        // Arrange
        Task::factory()->create([
            'dealership_id' => $this->dealership->id,
            'title' => 'First Task',
            'tags' => ['apple', 'banana']
        ]);

        Task::factory()->create([
            'dealership_id' => $this->dealership->id,
            'title' => 'Second Task',
            'tags' => ['cherry']
        ]);

        // Act - search using 'banana' which is in tags
        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/tasks?dealership_id={$this->dealership->id}&search=banana");

        // Assert
        $response->assertStatus(200);
        $data = $response->json('data');
        expect($data)->toHaveCount(1);
        expect($data[0]['title'])->toBe('First Task');
    });

    it('filters tasks by date range', function () {
        // Arrange
        // Task 1: Deadline today, not completed
        Task::factory()->create([
            'dealership_id' => $this->dealership->id,
            'title' => 'Deadline Today',
            'deadline' => Carbon::now(),
            'task_type' => 'individual',
        ]);

        // Task 2: Deadline tomorrow
        Task::factory()->create([
            'dealership_id' => $this->dealership->id,
            'title' => 'Deadline Tomorrow',
            'deadline' => Carbon::now()->addDay(),
            'task_type' => 'individual',
        ]);

        // Act: Filter by today
        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/tasks?dealership_id={$this->dealership->id}&date_range=today");

        // Assert
        $response->assertStatus(200);
        $data = $response->json('data');
        expect($data)->toHaveCount(1);
        expect($data[0]['title'])->toBe('Deadline Today');

        // Arrange 3: Completed today, deadline yesterday
        // Use UTC timezone to match TimeHelper::nowUtc()
        $task3 = Task::factory()->create([
            'dealership_id' => $this->dealership->id,
            'title' => 'Completed Today',
            'deadline' => Carbon::yesterday('UTC'),
            'task_type' => 'individual',
        ]);
        \App\Models\TaskResponse::create([
            'task_id' => $task3->id,
            'user_id' => $this->manager->id,
            'status' => 'completed',
            'responded_at' => Carbon::now('UTC'),
        ]);

        // Act: Filter by status=completed and date_range=today
        $response2 = $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/tasks?dealership_id={$this->dealership->id}&status=completed&date_range=today");

        // Assert
        $response2->assertStatus(200);
        $data2 = $response2->json('data');
        expect($data2)->toHaveCount(1);
        expect($data2[0]['title'])->toBe('Completed Today');
    });
    it('prevents duplicate task creation', function () {
        // Arrange
        $user = User::factory()->create(['role' => Role::EMPLOYEE->value, 'dealership_id' => $this->dealership->id]);
        $deadline = Carbon::now()->addDay()->toIso8601String();

        $taskData = [
                'title' => 'Duplicate Task',
                'description' => 'Description',
                'dealership_id' => $this->dealership->id,
                'assigned_users' => [$user->id],
                'appear_date' => Carbon::now()->toIso8601String(),
                'deadline' => $deadline,
                'task_type' => 'individual',
                'response_type' => 'completion',
        ];

        // Create first task
        $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/v1/tasks', $taskData)
            ->assertStatus(201);

        // Act - try to create duplicate
        $response = $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/v1/tasks', $taskData);

        // Assert
        $response->assertStatus(422)
            ->assertJsonFragment(['message' => 'Такая задача уже существует (дубликат)']);
    });

    it('updates task status to pending_review', function () {
        // Arrange - use completion() to avoid completion_with_proof which requires proof files
        $task = Task::factory()->completion()->create(['dealership_id' => $this->dealership->id]);
        $user = User::factory()->create(['role' => Role::EMPLOYEE->value, 'dealership_id' => $this->dealership->id]);
        \App\Models\TaskAssignment::create(['task_id' => $task->id, 'user_id' => $user->id]);

        // Act
        $response = $this->actingAs($this->manager, 'sanctum')
            ->patchJson("/api/v1/tasks/{$task->id}/status", ['status' => 'pending_review']);

        // Assert
        $response->assertStatus(200);
        expect($response->json('status'))->toBe('pending_review');
    });

    it('updates task status to completed', function () {
        // Arrange - use completion() to avoid completion_with_proof which requires proof files
        $task = Task::factory()->completion()->create([
            'dealership_id' => $this->dealership->id,
            'task_type' => 'individual',
            'deadline' => Carbon::now()->addDay(),
        ]);

        // Act
        $response = $this->actingAs($this->manager, 'sanctum')
            ->patchJson("/api/v1/tasks/{$task->id}/status", ['status' => 'completed']);

        // Assert
        $response->assertStatus(200);
        expect($response->json('status'))->toBe('completed');
    });

    it('resets task status to pending', function () {
        // Arrange
        $task = Task::factory()->create(['dealership_id' => $this->dealership->id]);
        \App\Models\TaskResponse::create([
            'task_id' => $task->id,
            'user_id' => $this->manager->id,
            'status' => 'completed',
            'responded_at' => Carbon::now(),
        ]);

        // Act
        $response = $this->actingAs($this->manager, 'sanctum')
            ->patchJson("/api/v1/tasks/{$task->id}/status", ['status' => 'pending']);

        // Assert
        $response->assertStatus(200);
        expect($response->json('status'))->toBe('pending');
    });

    it('filters tasks by pending_review status', function () {
        // Arrange
        $task1 = Task::factory()->create(['dealership_id' => $this->dealership->id, 'title' => 'Review Task']);
        $task2 = Task::factory()->create(['dealership_id' => $this->dealership->id, 'title' => 'Other Task']);
        \App\Models\TaskResponse::create([
            'task_id' => $task1->id,
            'user_id' => $this->manager->id,
            'status' => 'pending_review',
            'responded_at' => Carbon::now(),
        ]);

        // Act
        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/tasks?dealership_id={$this->dealership->id}&status=pending_review");

        // Assert
        $response->assertStatus(200);
        $data = $response->json('data');
        expect($data)->toHaveCount(1);
        expect($data[0]['title'])->toBe('Review Task');
    });

    it('filters pending tasks excluding pending_review status', function () {
        // Arrange: task with pending_review status
        $task1 = Task::factory()->create([
            'dealership_id' => $this->dealership->id,
            'title' => 'Review Task'
        ]);
        \App\Models\TaskResponse::create([
            'task_id' => $task1->id,
            'user_id' => $this->manager->id,
            'status' => 'pending_review',
            'responded_at' => Carbon::now(),
        ]);

        // Arrange: regular pending task (no responses)
        $task2 = Task::factory()->create([
            'dealership_id' => $this->dealership->id,
            'title' => 'Pending Task'
        ]);

        // Act: filter by pending status
        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/tasks?dealership_id={$this->dealership->id}&status=pending");

        // Assert: only pending task should be returned, not pending_review
        $response->assertStatus(200);
        $data = $response->json('data');
        expect($data)->toHaveCount(1);
        expect($data[0]['title'])->toBe('Pending Task');
    });
});

