<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\AutoDealership;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskResponse;
use App\Models\User;
use Carbon\Carbon;

describe('Task Security', function () {
    beforeEach(function () {
        // Создаём два независимых dealerships
        $this->dealershipA = AutoDealership::factory()->create(['name' => 'Автосалон А']);
        $this->dealershipB = AutoDealership::factory()->create(['name' => 'Автосалон Б']);

        $this->managerA = User::factory()->create([
            'role' => Role::MANAGER->value,
            'dealership_id' => $this->dealershipA->id,
        ]);
        $this->managerB = User::factory()->create([
            'role' => Role::MANAGER->value,
            'dealership_id' => $this->dealershipB->id,
        ]);
        $this->owner = User::factory()->create([
            'role' => Role::OWNER->value,
            'dealership_id' => null, // Owner не привязан к конкретному dealership
        ]);
        $this->employeeA = User::factory()->create([
            'role' => Role::EMPLOYEE->value,
            'dealership_id' => $this->dealershipA->id,
        ]);
    });

    describe('Dealership Isolation', function () {
        it('prevents manager from viewing tasks in other dealership', function () {
            // Arrange: Создаём задачу в dealership A
            $task = Task::factory()->create([
                'dealership_id' => $this->dealershipA->id,
                'creator_id' => $this->managerA->id,
            ]);

            // Act: Manager B пытается получить задачу
            $response = $this->actingAs($this->managerB, 'sanctum')
                ->getJson("/api/v1/tasks/{$task->id}");

            // Assert: Должен получить 403 или 404 (не раскрывая существование)
            $response->assertStatus(403);

            // Verify: Error message не содержит детали задачи
            expect($response->json('message'))->not->toContain($task->title);
        });

        it('prevents manager from updating tasks in other dealership', function () {
            // Arrange
            $task = Task::factory()->create([
                'dealership_id' => $this->dealershipA->id,
                'creator_id' => $this->managerA->id,
            ]);

            // Act: Manager B пытается обновить задачу
            $response = $this->actingAs($this->managerB, 'sanctum')
                ->putJson("/api/v1/tasks/{$task->id}", [
                    'title' => 'Хакерский заголовок',
                ]);

            // Assert
            $response->assertStatus(403);

            // Verify: Задача не изменилась
            $task->refresh();
            expect($task->title)->not->toBe('Хакерский заголовок');
        });

        it('prevents manager from changing status of tasks in other dealership', function () {
            // Arrange
            $task = Task::factory()->completion()->create([
                'dealership_id' => $this->dealershipA->id,
                'creator_id' => $this->managerA->id,
            ]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employeeA->id]);

            // Act: Manager from dealership B tries to update task from dealership A
            $response = $this->actingAs($this->managerB, 'sanctum')
                ->patchJson("/api/v1/tasks/{$task->id}/status", [
                    'status' => 'completed',
                ]);

            // Assert: Access denied
            $response->assertStatus(403);

            // Verify: No response was created for managerB
            $managerResponse = TaskResponse::where('task_id', $task->id)
                ->where('user_id', $this->managerB->id)
                ->first();
            expect($managerResponse)->toBeNull();

            // Verify: Task status unchanged
            $task->load('responses', 'assignments');
            expect($task->status)->toBe('pending');
        });

        it('prevents manager from approving responses in other dealership', function () {
            // Arrange: Создаём задачу с response в dealership A
            $task = Task::factory()->completion()->create([
                'dealership_id' => $this->dealershipA->id,
                'creator_id' => $this->managerA->id,
            ]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employeeA->id]);
            $taskResponse = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employeeA->id,
                'status' => 'pending_review',
                'responded_at' => Carbon::now(),
            ]);

            // Act: Manager B пытается одобрить
            $response = $this->actingAs($this->managerB, 'sanctum')
                ->postJson("/api/v1/task-responses/{$taskResponse->id}/approve");

            // Assert
            $response->assertStatus(403);

            // Verify: Response не изменился
            $taskResponse->refresh();
            expect($taskResponse->status)->toBe('pending_review');
        });

        it('allows owner to access tasks in any dealership', function () {
            // Arrange
            $taskA = Task::factory()->create([
                'dealership_id' => $this->dealershipA->id,
                'creator_id' => $this->managerA->id,
            ]);
            $taskB = Task::factory()->create([
                'dealership_id' => $this->dealershipB->id,
                'creator_id' => $this->managerB->id,
            ]);

            // Act & Assert: Owner может видеть обе задачи
            $this->actingAs($this->owner, 'sanctum')
                ->getJson("/api/v1/tasks/{$taskA->id}")
                ->assertStatus(200);

            $this->actingAs($this->owner, 'sanctum')
                ->getJson("/api/v1/tasks/{$taskB->id}")
                ->assertStatus(200);
        });

        it('filters task list by accessible dealerships only', function () {
            // Arrange: Создаём задачи в обоих dealerships
            Task::factory()->count(3)->create([
                'dealership_id' => $this->dealershipA->id,
                'creator_id' => $this->managerA->id,
            ]);
            Task::factory()->count(2)->create([
                'dealership_id' => $this->dealershipB->id,
                'creator_id' => $this->managerB->id,
            ]);

            // Act: Manager A запрашивает список
            $response = $this->actingAs($this->managerA, 'sanctum')
                ->getJson('/api/v1/tasks');

            // Assert: Видит только свои 3 задачи
            $response->assertStatus(200);
            $data = $response->json('data');
            expect($data)->toHaveCount(3);

            foreach ($data as $task) {
                expect($task['dealership_id'])->toBe($this->dealershipA->id);
            }
        });
    });

    describe('XSS and Injection Prevention', function () {
        it('stores XSS payloads safely without execution', function () {
            // Arrange: Malicious payloads
            $xssPayloads = [
                'title' => '<script>alert("xss")</script>',
                'description' => '<img src=x onerror=alert("xss")>',
                'comment' => '"><script>document.location="http://evil.com"</script>',
            ];

            // Act: Создаём задачу с XSS payloads
            $response = $this->actingAs($this->managerA, 'sanctum')
                ->postJson('/api/v1/tasks', array_merge($xssPayloads, [
                    'dealership_id' => $this->dealershipA->id,
                    'assigned_users' => [$this->employeeA->id],
                    'appear_date' => Carbon::now()->toIso8601String(),
                    'deadline' => Carbon::now()->addDay()->toIso8601String(),
                    'task_type' => 'individual',
                    'response_type' => 'completion',
                ]));

            $response->assertStatus(201);

            // Assert: Данные сохранены as-is (экранирование на frontend)
            $task = Task::find($response->json('data.id'));
            expect($task->title)->toBe($xssPayloads['title']);
            expect($task->description)->toBe($xssPayloads['description']);
            expect($task->comment)->toBe($xssPayloads['comment']);

            // Verify: Данные возвращаются корректно в API
            $getResponse = $this->actingAs($this->managerA, 'sanctum')
                ->getJson("/api/v1/tasks/{$task->id}");
            $getResponse->assertStatus(200);
            expect($getResponse->json('data.title'))->toBe($xssPayloads['title']);
        });

        it('stores SQL injection attempts as literal strings', function () {
            // Arrange: SQL injection payloads
            $sqlPayloads = [
                'title' => "'; DROP TABLE tasks; --",
                'description' => "1' OR '1'='1",
                'comment' => "UNION SELECT * FROM users WHERE '1'='1",
            ];

            // Act
            $response = $this->actingAs($this->managerA, 'sanctum')
                ->postJson('/api/v1/tasks', array_merge($sqlPayloads, [
                    'dealership_id' => $this->dealershipA->id,
                    'assigned_users' => [$this->employeeA->id],
                    'appear_date' => Carbon::now()->toIso8601String(),
                    'deadline' => Carbon::now()->addDay()->toIso8601String(),
                    'task_type' => 'individual',
                    'response_type' => 'completion',
                ]));

            $response->assertStatus(201);

            // Assert: Данные сохранены как обычные строки
            $task = Task::find($response->json('data.id'));
            expect($task->title)->toBe($sqlPayloads['title']);

            // Verify: Таблица tasks существует и содержит данные
            expect(Task::count())->toBeGreaterThan(0);
        });

        it('handles Cyrillic and special characters in tags correctly', function () {
            // Arrange: Cyrillic tags with special characters
            $cyrillicTags = ['срочное', 'важно!!!', 'клиент №123', 'проверка@test'];

            // Act
            $response = $this->actingAs($this->managerA, 'sanctum')
                ->postJson('/api/v1/tasks', [
                    'title' => 'Задача с русскими тегами',
                    'dealership_id' => $this->dealershipA->id,
                    'assigned_users' => [$this->employeeA->id],
                    'appear_date' => Carbon::now()->toIso8601String(),
                    'deadline' => Carbon::now()->addDay()->toIso8601String(),
                    'task_type' => 'individual',
                    'response_type' => 'completion',
                    'tags' => $cyrillicTags,
                ]);

            $response->assertStatus(201);

            // Assert: Tags сохранены с правильной кодировкой
            $task = Task::find($response->json('data.id'));
            expect($task->tags)->toBe($cyrillicTags);

            // Verify: Поиск по Cyrillic тегам работает
            $searchResponse = $this->actingAs($this->managerA, 'sanctum')
                ->getJson('/api/v1/tasks?search=срочное');

            $searchResponse->assertStatus(200);
            expect($searchResponse->json('data'))->toHaveCount(1);
        });

        it('handles unicode edge cases in task fields', function () {
            // Arrange: Unicode edge cases
            $unicodeData = [
                'title' => '任务 🎯 Задача ñ café',
                'description' => "Line1\nLine2\tTabbed\r\nWindows",
                'comment' => '👍 Хорошо! ✅',
            ];

            // Act
            $response = $this->actingAs($this->managerA, 'sanctum')
                ->postJson('/api/v1/tasks', array_merge($unicodeData, [
                    'dealership_id' => $this->dealershipA->id,
                    'assigned_users' => [$this->employeeA->id],
                    'appear_date' => Carbon::now()->toIso8601String(),
                    'deadline' => Carbon::now()->addDay()->toIso8601String(),
                    'task_type' => 'individual',
                    'response_type' => 'completion',
                ]));

            $response->assertStatus(201);

            // Assert: All unicode preserved
            $task = Task::find($response->json('data.id'));
            expect($task->title)->toBe($unicodeData['title']);
            expect($task->description)->toBe($unicodeData['description']);
            expect($task->comment)->toBe($unicodeData['comment']);
        });

        it('validates maximum field lengths', function () {
            // Arrange: Очень длинный заголовок (> 255 символов)
            $longTitle = str_repeat('А', 300);

            // Act
            $response = $this->actingAs($this->managerA, 'sanctum')
                ->postJson('/api/v1/tasks', [
                    'title' => $longTitle,
                    'dealership_id' => $this->dealershipA->id,
                    'assigned_users' => [$this->employeeA->id],
                    'appear_date' => Carbon::now()->toIso8601String(),
                    'deadline' => Carbon::now()->addDay()->toIso8601String(),
                    'task_type' => 'individual',
                    'response_type' => 'completion',
                ]);

            // Assert: Validation error
            $response->assertStatus(422);
            $response->assertJsonValidationErrors(['title']);
        });
    });

    describe('Role-based Access Control', function () {
        it('prevents employee from creating tasks', function () {
            // Act
            $response = $this->actingAs($this->employeeA, 'sanctum')
                ->postJson('/api/v1/tasks', [
                    'title' => 'Employee Task',
                    'dealership_id' => $this->dealershipA->id,
                    'assigned_users' => [$this->employeeA->id],
                    'appear_date' => Carbon::now()->toIso8601String(),
                    'deadline' => Carbon::now()->addDay()->toIso8601String(),
                    'task_type' => 'individual',
                    'response_type' => 'completion',
                ]);

            // Assert
            $response->assertStatus(403);
        });

        it('prevents employee from deleting tasks', function () {
            // Arrange
            $task = Task::factory()->create([
                'dealership_id' => $this->dealershipA->id,
                'creator_id' => $this->managerA->id,
            ]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employeeA->id]);

            // Act: Employee пытается удалить назначенную ему задачу
            $response = $this->actingAs($this->employeeA, 'sanctum')
                ->deleteJson("/api/v1/tasks/{$task->id}");

            // Assert
            $response->assertStatus(403);

            // Verify: Task не удалена
            expect(Task::find($task->id))->not->toBeNull();
        });

        it('prevents employee from approving task responses', function () {
            // Arrange
            $task = Task::factory()->completion()->create([
                'dealership_id' => $this->dealershipA->id,
                'creator_id' => $this->managerA->id,
            ]);
            $employee2 = User::factory()->create([
                'role' => Role::EMPLOYEE->value,
                'dealership_id' => $this->dealershipA->id,
            ]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $employee2->id]);
            $taskResponse = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $employee2->id,
                'status' => 'pending_review',
                'responded_at' => Carbon::now(),
            ]);

            // Act: Employee пытается одобрить response другого employee
            $response = $this->actingAs($this->employeeA, 'sanctum')
                ->postJson("/api/v1/task-responses/{$taskResponse->id}/approve");

            // Assert
            $response->assertStatus(403);
        });

        it('allows observer to view but not modify tasks', function () {
            // Arrange
            $observer = User::factory()->create([
                'role' => Role::OBSERVER->value,
                'dealership_id' => $this->dealershipA->id,
            ]);
            $task = Task::factory()->create([
                'dealership_id' => $this->dealershipA->id,
                'creator_id' => $this->managerA->id,
            ]);

            // Act & Assert: Observer может просматривать
            $this->actingAs($observer, 'sanctum')
                ->getJson("/api/v1/tasks/{$task->id}")
                ->assertStatus(200);

            // Act & Assert: Observer не может изменять
            $this->actingAs($observer, 'sanctum')
                ->putJson("/api/v1/tasks/{$task->id}", ['title' => 'Modified'])
                ->assertStatus(403);
        });
    });
});
