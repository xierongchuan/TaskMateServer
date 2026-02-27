<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Helpers\TimeHelper;
use App\Models\AutoDealership;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\User;
use Carbon\Carbon;

describe('Task Timezone', function () {
    beforeEach(function () {
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
        Carbon::setTestNow(); // Reset time
    });

    it('correctly determines overdue status at deadline boundary', function () {
        // Arrange: Создаём задачу с deadline в конкретное UTC время
        $deadlineTime = Carbon::parse('2025-01-27 15:30:00', 'UTC');

        $task = Task::factory()->completion()->individual()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
            'deadline' => $deadlineTime,
            'is_active' => true,
        ]);
        TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee->id]);

        // Test 1: 1 секунда ДО deadline -> pending
        Carbon::setTestNow($deadlineTime->copy()->subSecond());
        $task->load('responses', 'assignments');
        expect($task->status)->toBe('pending');
        expect(TimeHelper::isDeadlinePassed($task->deadline))->toBeFalse();

        // Test 2: Точный момент deadline -> pending (ещё не просрочена)
        Carbon::setTestNow($deadlineTime->copy());
        $task->load('responses', 'assignments');
        expect($task->status)->toBe('pending');
        expect(TimeHelper::isDeadlinePassed($task->deadline))->toBeFalse();

        // Test 3: 1 секунда ПОСЛЕ deadline -> overdue
        Carbon::setTestNow($deadlineTime->copy()->addSecond());
        $task->load('responses', 'assignments');
        expect($task->status)->toBe('overdue');
        expect(TimeHelper::isDeadlinePassed($task->deadline))->toBeTrue();
    });

    it('handles midnight boundary correctly', function () {
        // Arrange: Deadline в полночь UTC
        $midnightUtc = Carbon::parse('2025-01-28 00:00:00', 'UTC');

        $task = Task::factory()->completion()->individual()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
            'deadline' => $midnightUtc,
            'is_active' => true,
        ]);
        TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee->id]);

        // Test: 23:59:59 предыдущего дня -> pending
        Carbon::setTestNow($midnightUtc->copy()->subSecond());
        $task->load('responses', 'assignments');
        expect($task->status)->toBe('pending');

        // Test: 00:00:01 нового дня -> overdue
        Carbon::setTestNow($midnightUtc->copy()->addSecond());
        $task->load('responses', 'assignments');
        expect($task->status)->toBe('overdue');
    });

    it('stores and retrieves dates in UTC correctly', function () {
        // Arrange: Отправляем дату в ISO 8601 с московским часовым поясом
        $moscowTime = '2025-01-27T18:30:00+03:00'; // 15:30 UTC
        $expectedUtc = '2025-01-27T15:30:00';

        // Act
        $response = $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/v1/tasks', [
                'title' => 'Timezone Test Task',
                'dealership_id' => $this->dealership->id,
                'assigned_users' => [$this->employee->id],
                'appear_date' => '2025-01-27T12:00:00+03:00',
                'deadline' => $moscowTime,
                'task_type' => 'individual',
                'response_type' => 'completion',
            ]);

        $response->assertStatus(201);

        // Assert: Deadline сохранён в UTC
        $task = Task::find($response->json('id'));
        expect($task->deadline->format('Y-m-d H:i:s'))->toBe('2025-01-27 15:30:00');

        // Assert: API возвращает в UTC с Z suffix
        $getResponse = $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/tasks/{$task->id}");

        $deadlineFromApi = $getResponse->json('deadline');
        expect($deadlineFromApi)->toContain('2025-01-27T15:30:00');
        expect($deadlineFromApi)->toEndWith('Z');
    });

    it('accepts various ISO 8601 formats', function () {
        // Используем даты далеко в будущем, чтобы тест работал независимо от текущего времени
        // Все форматы соответствуют одному и тому же моменту UTC: 2030-06-15T15:30:00Z
        $validFormats = [
            '2030-06-15T15:30:00Z',           // Zulu time
            '2030-06-15T15:30:00.000Z',       // With milliseconds
            '2030-06-15T18:30:00+03:00',      // With positive offset (15:30 UTC)
            '2030-06-15T10:30:00-05:00',      // With negative offset (15:30 UTC)
            '2030-06-15T15:30:00+00:00',      // Explicit UTC offset
        ];

        // appear_date должен быть до deadline
        $appearDate = '2030-06-15T10:00:00Z';

        foreach ($validFormats as $format) {
            $response = $this->actingAs($this->manager, 'sanctum')
                ->postJson('/api/v1/tasks', [
                    'title' => "Task with format: {$format}",
                    'dealership_id' => $this->dealership->id,
                    'assigned_users' => [$this->employee->id],
                    'appear_date' => $appearDate,
                    'deadline' => $format,
                    'task_type' => 'individual',
                    'response_type' => 'completion',
                ]);

            $response->assertStatus(201);
        }
    });

    it('determines completed_late correctly based on response time vs deadline', function () {
        // Arrange
        $deadline = Carbon::parse('2025-01-27 15:00:00', 'UTC');

        $task = Task::factory()->completion()->individual()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
            'deadline' => $deadline,
            'is_active' => true,
        ]);
        TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee->id]);

        // Act: Выполняем задачу через 1 секунду после deadline
        Carbon::setTestNow($deadline->copy()->addSecond());

        $this->actingAs($this->employee, 'sanctum')
            ->patchJson("/api/v1/tasks/{$task->id}/status", [
                'status' => 'completed',
            ])
            ->assertStatus(200);

        // Assert: Статус completed_late
        $task->load('responses', 'assignments');
        expect($task->status)->toBe('completed_late');

        // Verify: responded_at записан после deadline
        $response = $task->responses->first();
        expect($response->responded_at->gt($deadline))->toBeTrue();
    });

    it('correctly handles far future deadline', function () {
        // Arrange: Задача с очень далёким deadline
        $farFutureDeadline = Carbon::now()->addYears(10);
        $task = Task::factory()->completion()->individual()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
            'deadline' => $farFutureDeadline,
            'is_active' => true,
        ]);
        TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee->id]);

        // Assert: Задача не становится overdue даже через год
        Carbon::setTestNow(Carbon::now()->addYear()); // Через год
        $task->load('responses', 'assignments');
        expect($task->status)->toBe('pending');
        expect(TimeHelper::isDeadlinePassed($farFutureDeadline))->toBeFalse();
    });

    it('handles year boundary correctly', function () {
        // Arrange: Deadline в конце года
        $endOfYear = Carbon::parse('2025-12-31 23:59:59', 'UTC');

        $task = Task::factory()->completion()->individual()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
            'deadline' => $endOfYear,
            'is_active' => true,
        ]);
        TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee->id]);

        // Test: До полуночи нового года -> pending
        Carbon::setTestNow($endOfYear->copy());
        $task->load('responses', 'assignments');
        expect($task->status)->toBe('pending');

        // Test: После полуночи нового года -> overdue
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00', 'UTC'));
        $task->load('responses', 'assignments');
        expect($task->status)->toBe('overdue');
    });

    it('validates appear_date must be before or equal to deadline', function () {
        // Act: appear_date позже deadline
        $response = $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/v1/tasks', [
                'title' => 'Invalid Dates Task',
                'dealership_id' => $this->dealership->id,
                'assigned_users' => [$this->employee->id],
                'appear_date' => '2025-01-28T10:00:00Z', // После deadline
                'deadline' => '2025-01-27T10:00:00Z',
                'task_type' => 'individual',
                'response_type' => 'completion',
            ]);

        // Assert: Validation error
        $response->assertStatus(422);
    });
});
