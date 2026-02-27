<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\AutoDealership;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskResponse;
use App\Models\User;
use App\Enums\Role;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ArchivedTaskControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;
    private AutoDealership $dealership;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dealership = AutoDealership::factory()->create();
        $this->manager = User::factory()->create([
            'role' => Role::MANAGER,
            'dealership_id' => $this->dealership->id,
        ]);
    }

    public function test_can_list_archived_tasks(): void
    {
        Sanctum::actingAs($this->manager);

        // Create archived tasks
        Task::factory()->count(3)->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
            'archived_at' => Carbon::now(),
            'archive_reason' => 'completed',
        ]);

        // Create non-archived task
        Task::factory()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
            'archived_at' => null,
        ]);

        $response = $this->getJson('/api/v1/archived-tasks');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'title', 'archived_at', 'archive_reason']
                ],
                'current_page',
                'last_page',
                'per_page',
                'total',
            ]);

        $this->assertCount(3, $response->json('data'));
    }

    public function test_can_filter_by_archive_reason(): void
    {
        Sanctum::actingAs($this->manager);

        Task::factory()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
            'archived_at' => Carbon::now(),
            'archive_reason' => 'completed',
        ]);

        Task::factory()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
            'archived_at' => Carbon::now(),
            'archive_reason' => 'expired',
        ]);

        $response = $this->getJson('/api/v1/archived-tasks?archive_reason=completed');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals('completed', $response->json('data.0.archive_reason'));
    }

    public function test_can_restore_archived_task(): void
    {
        Sanctum::actingAs($this->manager);

        $task = Task::factory()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
            'archived_at' => Carbon::now(),
            'archive_reason' => 'completed',
            'is_active' => false,
        ]);

        $response = $this->postJson("/api/v1/archived-tasks/{$task->id}/restore");

        $response->assertOk();

        $task->refresh();
        $this->assertNull($task->archived_at);
        $this->assertNull($task->archive_reason);
        $this->assertTrue($task->is_active);
    }

    public function test_can_export_csv(): void
    {
        Sanctum::actingAs($this->manager);

        Task::factory()->count(5)->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
            'archived_at' => Carbon::now(),
            'archive_reason' => 'completed',
        ]);

        $response = $this->getJson('/api/v1/archived-tasks/export');

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=utf-8');
    }

    public function test_can_filter_by_date_range(): void
    {
        Sanctum::actingAs($this->manager);

        // Old archived task
        Task::factory()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
            'archived_at' => Carbon::now()->subDays(10),
            'archive_reason' => 'completed',
        ]);

        // Recent archived task
        Task::factory()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
            'archived_at' => Carbon::now()->subDay(),
            'archive_reason' => 'completed',
        ]);

        $dateFrom = Carbon::now()->subDays(5)->format('Y-m-d');
        $response = $this->getJson("/api/v1/archived-tasks?date_from={$dateFrom}");

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_can_search_archived_tasks(): void
    {
        Sanctum::actingAs($this->manager);

        Task::factory()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
            'title' => 'Проверка автомобилей',
            'archived_at' => Carbon::now(),
            'archive_reason' => 'completed',
        ]);

        Task::factory()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
            'title' => 'Другая задача',
            'archived_at' => Carbon::now(),
            'archive_reason' => 'completed',
        ]);

        $response = $this->getJson('/api/v1/archived-tasks?search=автомобил');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertStringContainsString('автомобилей', $response->json('data.0.title'));
    }
}
