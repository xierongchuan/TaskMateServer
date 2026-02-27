<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\AutoDealership;
use App\Models\TaskGenerator;
use App\Models\TaskGeneratorAssignment;
use App\Models\User;
use App\Enums\Role;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TaskGeneratorControllerTest extends TestCase
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

    public function test_can_list_generators(): void
    {
        Sanctum::actingAs($this->manager);

        TaskGenerator::factory()->count(3)->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
        ]);

        $response = $this->getJson('/api/v1/task-generators');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'title', 'recurrence', 'is_active']
                ],
                'current_page',
                'last_page',
                'per_page',
                'total',
            ]);

        $this->assertCount(3, $response->json('data'));
    }

    public function test_can_create_generator(): void
    {
        Sanctum::actingAs($this->manager);

        $employee = User::factory()->create([
            'role' => Role::EMPLOYEE,
            'dealership_id' => $this->dealership->id,
        ]);

        $response = $this->postJson('/api/v1/task-generators', [
            'title' => 'Ежедневная проверка',
            'description' => 'Тестовое описание',
            'dealership_id' => $this->dealership->id,
            'recurrence' => 'daily',
            'recurrence_time' => '09:00',
            'deadline_time' => '18:00',
            'start_date' => Carbon::today()->format('Y-m-d'),
            'task_type' => 'individual',
            'response_type' => 'completion',
            'priority' => 'high',
            'assignments' => [$employee->id],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.title', 'Ежедневная проверка')
            ->assertJsonPath('data.recurrence', 'daily');

        $this->assertDatabaseHas('task_generators', [
            'title' => 'Ежедневная проверка',
            'recurrence' => 'daily',
        ]);

        $this->assertDatabaseHas('task_generator_assignments', [
            'user_id' => $employee->id,
        ]);
    }

    public function test_can_update_generator(): void
    {
        Sanctum::actingAs($this->manager);

        $generator = TaskGenerator::factory()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
            'title' => 'Старое название',
        ]);

        $response = $this->putJson("/api/v1/task-generators/{$generator->id}", [
            'title' => 'Новое название',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.title', 'Новое название');

        $this->assertDatabaseHas('task_generators', [
            'id' => $generator->id,
            'title' => 'Новое название',
        ]);
    }

    public function test_can_pause_generator(): void
    {
        Sanctum::actingAs($this->manager);

        $generator = TaskGenerator::factory()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
            'is_active' => true,
        ]);

        $response = $this->postJson("/api/v1/task-generators/{$generator->id}/pause");

        $response->assertOk()
            ->assertJsonPath('data.is_active', false);
    }

    public function test_can_resume_generator(): void
    {
        Sanctum::actingAs($this->manager);

        $generator = TaskGenerator::factory()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
            'is_active' => false,
        ]);

        $response = $this->postJson("/api/v1/task-generators/{$generator->id}/resume");

        $response->assertOk()
            ->assertJsonPath('data.is_active', true);
    }

    public function test_can_delete_generator(): void
    {
        Sanctum::actingAs($this->manager);

        $generator = TaskGenerator::factory()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
        ]);

        $response = $this->deleteJson("/api/v1/task-generators/{$generator->id}");

        $response->assertOk();

        $this->assertDatabaseMissing('task_generators', [
            'id' => $generator->id,
        ]);
    }

    public function test_can_filter_by_recurrence(): void
    {
        Sanctum::actingAs($this->manager);

        TaskGenerator::factory()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
            'recurrence' => 'daily',
        ]);
        TaskGenerator::factory()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
            'recurrence' => 'weekly',
        ]);

        $response = $this->getJson('/api/v1/task-generators?recurrence=daily');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals('daily', $response->json('data.0.recurrence'));
    }

    public function test_can_get_generator_statistics(): void
    {
        Sanctum::actingAs($this->manager);

        $generator = TaskGenerator::factory()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
        ]);

        $response = $this->getJson("/api/v1/task-generators/{$generator->id}/stats");

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'generator_id',
                    'all_time' => [
                        'total_generated',
                        'completed_count',
                        'expired_count',
                        'pending_count',
                        'on_time_count',
                        'completion_rate',
                        'on_time_rate',
                    ],
                    'week' => [
                        'total_generated',
                        'completed_count',
                        'expired_count',
                        'pending_count',
                        'on_time_count',
                        'completion_rate',
                        'on_time_rate',
                    ],
                    'month' => [
                        'total_generated',
                        'completed_count',
                        'expired_count',
                        'pending_count',
                        'on_time_count',
                        'completion_rate',
                        'on_time_rate',
                    ],
                    'year' => [
                        'total_generated',
                        'completed_count',
                        'expired_count',
                        'pending_count',
                        'on_time_count',
                        'completion_rate',
                        'on_time_rate',
                    ],
                    'average_completion_time_minutes',
                ]
            ]);
    }

    public function test_owner_can_pause_all_generators(): void
    {
        $owner = User::factory()->create(['role' => Role::OWNER]);
        Sanctum::actingAs($owner);

        TaskGenerator::factory()->count(3)->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/v1/task-generators/pause-all');

        $response->assertOk();
        $this->assertEquals(3, $response->json('paused_count'));
        $this->assertEquals(0, TaskGenerator::where('is_active', true)->count());
    }

    public function test_manager_cannot_pause_all_generators(): void
    {
        Sanctum::actingAs($this->manager);

        TaskGenerator::factory()->count(2)->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/v1/task-generators/pause-all');

        $response->assertForbidden();
        // Generators should still be active
        $this->assertEquals(2, TaskGenerator::where('is_active', true)->count());
    }

    public function test_owner_can_resume_all_generators(): void
    {
        $owner = User::factory()->create(['role' => Role::OWNER]);
        Sanctum::actingAs($owner);

        TaskGenerator::factory()->count(3)->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
            'is_active' => false,
        ]);

        $response = $this->postJson('/api/v1/task-generators/resume-all');

        $response->assertOk();
        $this->assertEquals(3, $response->json('resumed_count'));
        $this->assertEquals(3, TaskGenerator::where('is_active', true)->count());
    }

    public function test_manager_cannot_resume_all_generators(): void
    {
        Sanctum::actingAs($this->manager);

        TaskGenerator::factory()->count(2)->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
            'is_active' => false,
        ]);

        $response = $this->postJson('/api/v1/task-generators/resume-all');

        $response->assertForbidden();
        // Generators should still be paused
        $this->assertEquals(0, TaskGenerator::where('is_active', true)->count());
    }
}
