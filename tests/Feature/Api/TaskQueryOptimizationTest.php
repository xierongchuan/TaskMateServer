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
use Illuminate\Support\Facades\DB;

describe('Task Query Optimization', function () {
    beforeEach(function () {
        $this->dealership = AutoDealership::factory()->create();
        $this->manager = User::factory()->create([
            'role' => Role::MANAGER->value,
            'dealership_id' => $this->dealership->id,
        ]);
    });

    it('loads task list without N+1 queries', function () {
        // Arrange: Создаём 20 задач с связями
        $employees = User::factory()->count(5)->create([
            'role' => Role::EMPLOYEE->value,
            'dealership_id' => $this->dealership->id,
        ]);

        for ($i = 0; $i < 20; $i++) {
            $task = Task::factory()->completionWithProof()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
                'deadline' => Carbon::now()->addDays($i + 1),
            ]);

            // Добавляем 3 assignments
            foreach ($employees->random(3) as $employee) {
                TaskAssignment::create([
                    'task_id' => $task->id,
                    'user_id' => $employee->id,
                ]);
            }

            // Добавляем 2 responses с proofs
            foreach ($employees->random(2) as $employee) {
                $response = TaskResponse::create([
                    'task_id' => $task->id,
                    'user_id' => $employee->id,
                    'status' => 'pending_review',
                    'responded_at' => Carbon::now(),
                ]);

                // Добавляем proofs (fake path)
                TaskProof::create([
                    'task_response_id' => $response->id,
                    'file_path' => "dealerships/{$this->dealership->id}/tasks/{$task->id}/proof_{$i}.jpg",
                    'original_filename' => "proof_{$i}.jpg",
                    'mime_type' => 'image/jpeg',
                    'file_size' => 12345,
                ]);
            }
        }

        // Act: Запрашиваем список задач и считаем queries
        DB::enableQueryLog();

        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/tasks?dealership_id={$this->dealership->id}&per_page=20");

        $queryLog = DB::getQueryLog();
        DB::disableQueryLog();

        // Assert: Запрос успешен
        $response->assertStatus(200);
        expect($response->json('data'))->toHaveCount(20);

        // Assert: Количество queries не должно расти линейно с количеством задач
        // Ожидаем примерно: 1 (tasks) + 1 (creator) + 1 (dealership) + 1 (assignments) +
        // 1 (responses) + 1 (proofs) + 1 (verifier) + pagination = ~10-15 queries max
        $queryCount = count($queryLog);

        // С N+1 было бы 20 * 6 = 120+ queries
        // Без N+1 должно быть <= 15
        expect($queryCount)->toBeLessThanOrEqual(20);
    });

    it('does not cause N+1 when accessing task status accessor', function () {
        // Arrange: Создаём 10 задач с responses
        $employee = User::factory()->create([
            'role' => Role::EMPLOYEE->value,
            'dealership_id' => $this->dealership->id,
        ]);

        for ($i = 0; $i < 10; $i++) {
            $task = Task::factory()->completion()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            TaskAssignment::create([
                'task_id' => $task->id,
                'user_id' => $employee->id,
            ]);
            TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $employee->id,
                'status' => 'pending_review',
                'responded_at' => Carbon::now(),
            ]);
        }

        // Act: Загружаем задачи с eager loading
        DB::enableQueryLog();

        $tasks = Task::with(['responses', 'assignments'])
            ->where('dealership_id', $this->dealership->id)
            ->get();

        // Обращаемся к status accessor для каждой задачи
        foreach ($tasks as $task) {
            $status = $task->status;
        }

        $queryLog = DB::getQueryLog();
        DB::disableQueryLog();

        // Assert: Должно быть 3 query: tasks, responses, assignments
        // Без eager loading было бы 10 * 2 + 1 = 21 queries
        expect(count($queryLog))->toBeLessThanOrEqual(5);
    });

    it('efficiently loads single task with all relations', function () {
        // Arrange: Создаём задачу со всеми связями
        $employee = User::factory()->create([
            'role' => Role::EMPLOYEE->value,
            'dealership_id' => $this->dealership->id,
        ]);

        $task = Task::factory()->completionWithProof()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
        ]);

        TaskAssignment::create(['task_id' => $task->id, 'user_id' => $employee->id]);

        $response = TaskResponse::create([
            'task_id' => $task->id,
            'user_id' => $employee->id,
            'status' => 'pending_review',
            'responded_at' => Carbon::now(),
        ]);

        TaskProof::create([
            'task_response_id' => $response->id,
            'file_path' => "test/proof.jpg",
            'original_filename' => "proof.jpg",
            'mime_type' => 'image/jpeg',
            'file_size' => 12345,
        ]);

        // Act
        DB::enableQueryLog();

        $getResponse = $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/tasks/{$task->id}");

        $queryLog = DB::getQueryLog();
        DB::disableQueryLog();

        // Assert
        $getResponse->assertStatus(200);

        // Ожидаем ~7-10 queries для одной задачи со всеми relations
        expect(count($queryLog))->toBeLessThanOrEqual(12);
    });

    it('handles pagination efficiently', function () {
        // Arrange: Создаём 50 задач
        for ($i = 0; $i < 50; $i++) {
            Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
        }

        // Act: Запрашиваем разные страницы
        DB::enableQueryLog();

        $page1 = $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/tasks?dealership_id={$this->dealership->id}&per_page=15&page=1");

        $queriesPage1 = count(DB::getQueryLog());
        DB::flushQueryLog();

        $page2 = $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/tasks?dealership_id={$this->dealership->id}&per_page=15&page=2");

        $queriesPage2 = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Assert: Обе страницы успешны
        $page1->assertStatus(200);
        $page2->assertStatus(200);

        expect($page1->json('data'))->toHaveCount(15);
        expect($page2->json('data'))->toHaveCount(15);

        // Assert: Количество queries примерно одинаковое для обеих страниц
        // (разница не более 2-3 queries)
        expect(abs($queriesPage1 - $queriesPage2))->toBeLessThanOrEqual(3);
    });

    it('filters efficiently without loading all records', function () {
        // Arrange: Создаём задачи с разными статусами
        $employee = User::factory()->create([
            'role' => Role::EMPLOYEE->value,
            'dealership_id' => $this->dealership->id,
        ]);

        // 20 pending задач
        Task::factory()->count(20)->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
        ]);

        // 5 pending_review задач
        for ($i = 0; $i < 5; $i++) {
            $task = Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $employee->id]);
            TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $employee->id,
                'status' => 'pending_review',
                'responded_at' => Carbon::now(),
            ]);
        }

        // Act: Фильтруем по статусу
        DB::enableQueryLog();

        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/tasks?dealership_id={$this->dealership->id}&status=pending_review");

        $queryLog = DB::getQueryLog();
        DB::disableQueryLog();

        // Assert
        $response->assertStatus(200);
        expect($response->json('data'))->toHaveCount(5);

        // Assert: Query должен использовать EXISTS subquery для фильтрации по статусу
        // Значение 'pending_review' передаётся через parameter binding (?)
        $queries = collect($queryLog)->pluck('query')->implode(' ');
        expect($queries)->toContain('exists');
        expect($queries)->toContain('task_responses');
        expect($queries)->toContain('status');

        // Проверяем что bindings содержат 'pending_review'
        $allBindings = collect($queryLog)->pluck('bindings')->flatten()->toArray();
        expect($allBindings)->toContain('pending_review');
    });
});
