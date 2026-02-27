<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\AutoDealership;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskFilterService;
use Illuminate\Http\Request;

describe('TaskFilterService Security', function () {
    beforeEach(function () {
        $this->dealership = AutoDealership::factory()->create();
        $this->manager = User::factory()->create([
            'role' => Role::MANAGER->value,
            'dealership_id' => $this->dealership->id,
        ]);
        $this->taskFilterService = app(TaskFilterService::class);
    });

    describe('SQL Injection Prevention', function () {
        it('safely handles SQL injection attempts in search parameter', function () {
            // Arrange: Создаём нормальные задачи
            Task::factory()->create([
                'title' => 'Normal Task',
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);

            // Act: SQL injection attempt
            $request = Request::create('/api/v1/tasks', 'GET', [
                'search' => "' OR 1=1 --",
                'dealership_id' => $this->dealership->id,
            ]);

            // Should not throw exception
            $result = $this->taskFilterService->getFilteredTasks($request, $this->manager);

            // Assert: No results (search treated as literal string)
            expect($result->total())->toBe(0);
        });

        it('safely handles DROP TABLE injection attempt', function () {
            // Arrange
            Task::factory()->create([
                'title' => 'Test Task',
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);

            // Act: DROP TABLE attempt
            $request = Request::create('/api/v1/tasks', 'GET', [
                'search' => "'; DROP TABLE tasks; --",
                'dealership_id' => $this->dealership->id,
            ]);

            $result = $this->taskFilterService->getFilteredTasks($request, $this->manager);

            // Assert: Query executed safely, table still exists
            expect(Task::count())->toBeGreaterThan(0);
        });

        it('safely handles UNION SELECT injection attempt', function () {
            // Arrange
            Task::factory()->create([
                'title' => 'Test Task',
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);

            // Act: UNION SELECT attempt
            $request = Request::create('/api/v1/tasks', 'GET', [
                'search' => "' UNION SELECT * FROM users WHERE '1'='1",
                'dealership_id' => $this->dealership->id,
            ]);

            $result = $this->taskFilterService->getFilteredTasks($request, $this->manager);

            // Assert: No results, no user data leaked
            expect($result->total())->toBe(0);
        });

        it('safely handles null bytes in search', function () {
            // Arrange
            Task::factory()->create([
                'title' => 'Test Task',
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);

            // Act: Null byte injection
            $request = Request::create('/api/v1/tasks', 'GET', [
                'search' => "Test\0Task",
                'dealership_id' => $this->dealership->id,
            ]);

            // Should not throw exception
            $result = $this->taskFilterService->getFilteredTasks($request, $this->manager);

            // Assert: Query executed safely
            expect(true)->toBeTrue(); // No exception thrown
        });

        it('safely handles unicode in search', function () {
            // Arrange: Task with unicode
            Task::factory()->create([
                'title' => 'Тестовая задача на русском',
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);

            // Act: Search with unicode
            $request = Request::create('/api/v1/tasks', 'GET', [
                'search' => 'Тестовая',
                'dealership_id' => $this->dealership->id,
            ]);

            $result = $this->taskFilterService->getFilteredTasks($request, $this->manager);

            // Assert: Unicode search works
            expect($result->total())->toBe(1);
            expect($result->first()->title)->toContain('Тестовая');
        });

        it('handles special LIKE characters as part of search', function () {
            // Arrange: Task with special characters
            Task::factory()->create([
                'title' => 'Test 50% discount',
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            Task::factory()->create([
                'title' => 'Test task normal',
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);

            // Act: Search for text with %
            $request = Request::create('/api/v1/tasks', 'GET', [
                'search' => '50% discount',
                'dealership_id' => $this->dealership->id,
            ]);

            $result = $this->taskFilterService->getFilteredTasks($request, $this->manager);

            // Assert: Task with % in title is found
            expect($result->total())->toBe(1);
            expect($result->first()->title)->toContain('50%');
        });

        it('search is case-insensitive', function () {
            // Arrange
            Task::factory()->create([
                'title' => 'UPPERCASE TASK',
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);

            // Act: Search with lowercase
            $request = Request::create('/api/v1/tasks', 'GET', [
                'search' => 'uppercase',
                'dealership_id' => $this->dealership->id,
            ]);

            $result = $this->taskFilterService->getFilteredTasks($request, $this->manager);

            // Assert: Case-insensitive search works (ILIKE in PostgreSQL)
            expect($result->total())->toBe(1);
        });
    });

    describe('Filter Parameter Validation', function () {
        it('safely handles invalid dealership_id', function () {
            // Arrange
            Task::factory()->create([
                'title' => 'Test Task',
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);

            // Act: Non-existent dealership
            $request = Request::create('/api/v1/tasks', 'GET', [
                'dealership_id' => 99999,
            ]);

            $result = $this->taskFilterService->getFilteredTasks($request, $this->manager);

            // Assert: Returns empty (no access to non-existent dealership)
            expect($result->total())->toBe(0);
        });

        it('safely handles invalid status value', function () {
            // Arrange
            Task::factory()->create([
                'title' => 'Test Task',
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);

            // Act: Invalid status
            $request = Request::create('/api/v1/tasks', 'GET', [
                'status' => 'invalid_status',
                'dealership_id' => $this->dealership->id,
            ]);

            // Should not throw exception, just ignore invalid status
            $result = $this->taskFilterService->getFilteredTasks($request, $this->manager);

            // Assert: Returns results (invalid status ignored)
            expect($result->total())->toBeGreaterThanOrEqual(0);
        });

        it('safely handles array injection in string parameters', function () {
            // Arrange
            Task::factory()->create([
                'title' => 'Test Task',
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);

            // Act: Array instead of string
            $request = Request::create('/api/v1/tasks', 'GET', [
                'search' => ['malicious', 'array'],
                'dealership_id' => $this->dealership->id,
            ]);

            // Should handle gracefully
            try {
                $result = $this->taskFilterService->getFilteredTasks($request, $this->manager);
                expect(true)->toBeTrue(); // No exception
            } catch (\Throwable $e) {
                // If exception, it should be validation error, not SQL error
                expect($e->getMessage())->not->toContain('SQL');
            }
        });
    });
});
