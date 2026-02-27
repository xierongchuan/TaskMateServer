<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Task;
use App\Models\AutoDealership;
use App\Enums\Role;
use Carbon\Carbon;

describe('Task Priority API', function () {
    beforeEach(function () {
        $this->dealership = AutoDealership::factory()->create();
        $this->manager = User::factory()->create([
            'role' => Role::MANAGER->value,
            'dealership_id' => $this->dealership->id
        ]);
    });

    it('creates a task with priority', function () {
        $response = $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/v1/tasks', [
                'title' => 'Important Task',
                'description' => 'Description',
                'dealership_id' => $this->dealership->id,
                'priority' => 'high',
                'task_type' => 'individual',
                'response_type' => 'completion',
                'deadline' => Carbon::now()->addDay()->toIso8601String(),
                'appear_date' => Carbon::now()->toIso8601String(),
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('tasks', [
            'title' => 'Important Task',
            'priority' => 'high',
        ]);
    });

    it('defaults priority to medium if not provided', function () {
        $response = $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/v1/tasks', [
                'title' => 'Normal Task',
                'dealership_id' => $this->dealership->id,
                'task_type' => 'individual',
                'response_type' => 'completion',
                'deadline' => Carbon::now()->addDay()->toIso8601String(),
                'appear_date' => Carbon::now()->toIso8601String(),
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('tasks', [
            'title' => 'Normal Task',
            'priority' => 'medium',
        ]);
    });

    it('updates task priority', function () {
        $task = Task::factory()->create([
            'dealership_id' => $this->dealership->id,
            'priority' => 'low'
        ]);

        $response = $this->actingAs($this->manager, 'sanctum')
            ->putJson("/api/v1/tasks/{$task->id}", [
                'priority' => 'high'
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'priority' => 'high',
        ]);
    });

    it('filters tasks by priority', function () {
        Task::factory()->create(['dealership_id' => $this->dealership->id, 'priority' => 'high', 'title' => 'High Priority']);
        Task::factory()->create(['dealership_id' => $this->dealership->id, 'priority' => 'low', 'title' => 'Low Priority']);

        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/tasks?dealership_id={$this->dealership->id}&priority=high");

        $response->assertStatus(200);
        $data = $response->json('data');
        expect($data)->toHaveCount(1);
        expect($data[0]['title'])->toBe('High Priority');
    });

    it('validates priority value', function () {
        $response = $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/v1/tasks', [
                'title' => 'Invalid Task',
                'dealership_id' => $this->dealership->id,
                'priority' => 'super_urgent',
                'task_type' => 'individual',
                'response_type' => 'completion',
                'deadline' => Carbon::now()->addDay()->toIso8601String(),
                'appear_date' => Carbon::now()->toIso8601String(),
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('priority');
    });
});
