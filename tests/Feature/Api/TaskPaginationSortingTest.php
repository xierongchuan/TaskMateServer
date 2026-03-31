<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\Role;
use App\Models\AutoDealership;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskPaginationSortingTest extends TestCase
{
    use RefreshDatabase;

    private AutoDealership $dealership;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dealership = AutoDealership::factory()->create();
        $this->manager = User::factory()->create([
            'role' => Role::MANAGER->value,
            'dealership_id' => $this->dealership->id,
        ]);
    }

    public function test_pagination_returns_correct_structure(): void
    {
        Task::factory()->count(25)->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
        ]);

        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/tasks?dealership_id={$this->dealership->id}&per_page=10");

        $response->assertOk();
        $this->assertCount(10, $response->json('data'));
        $this->assertEquals(1, $response->json('current_page'));
        $this->assertEquals(10, $response->json('per_page'));
        $this->assertEquals(25, $response->json('total'));
        $this->assertEquals(3, $response->json('last_page'));
    }

    public function test_pagination_respects_page_parameter(): void
    {
        Task::factory()->count(25)->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
        ]);

        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/tasks?dealership_id={$this->dealership->id}&per_page=10&page=3");

        $response->assertOk();
        $this->assertCount(5, $response->json('data'));
        $this->assertEquals(3, $response->json('current_page'));
    }

    public function test_per_page_capped_at_100(): void
    {
        Task::factory()->count(150)->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
        ]);

        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/tasks?dealership_id={$this->dealership->id}&per_page=500");

        $response->assertOk();
        $this->assertLessThanOrEqual(100, $response->json('per_page'));
        $this->assertLessThanOrEqual(100, count($response->json('data')));
    }

    public function test_default_per_page_is_15(): void
    {
        Task::factory()->count(20)->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
        ]);

        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/tasks?dealership_id={$this->dealership->id}");

        $response->assertOk();
        $this->assertEquals(15, $response->json('per_page'));
        $this->assertCount(15, $response->json('data'));
    }

    public function test_sort_by_created_at_desc(): void
    {
        Task::factory()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
            'title' => 'First',
            'created_at' => now()->subDays(2),
        ]);
        Task::factory()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
            'title' => 'Third',
            'created_at' => now(),
        ]);
        Task::factory()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
            'title' => 'Second',
            'created_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/tasks?dealership_id={$this->dealership->id}&sort_by=created_at&sort_dir=desc");

        $response->assertOk();
        $titles = collect($response->json('data'))->pluck('title')->toArray();
        $this->assertEquals(['Third', 'Second', 'First'], $titles);
    }

    public function test_sort_by_title_asc(): void
    {
        Task::factory()->create(['dealership_id' => $this->dealership->id, 'creator_id' => $this->manager->id, 'title' => 'Яблоко']);
        Task::factory()->create(['dealership_id' => $this->dealership->id, 'creator_id' => $this->manager->id, 'title' => 'Арбуз']);
        Task::factory()->create(['dealership_id' => $this->dealership->id, 'creator_id' => $this->manager->id, 'title' => 'Банан']);

        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/tasks?dealership_id={$this->dealership->id}&sort_by=title&sort_dir=asc&per_page=100");

        $response->assertOk();
        $titles = collect($response->json('data'))->pluck('title')->toArray();
        $this->assertEquals(['Арбуз', 'Банан', 'Яблоко'], $titles);
    }

    public function test_sort_by_priority(): void
    {
        Task::factory()->create(['dealership_id' => $this->dealership->id, 'creator_id' => $this->manager->id, 'priority' => 'high']);
        Task::factory()->create(['dealership_id' => $this->dealership->id, 'creator_id' => $this->manager->id, 'priority' => 'low']);
        Task::factory()->create(['dealership_id' => $this->dealership->id, 'creator_id' => $this->manager->id, 'priority' => 'medium']);

        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/tasks?dealership_id={$this->dealership->id}&sort_by=priority&sort_dir=asc&per_page=100");

        $response->assertOk();
        $this->assertCount(3, $response->json('data'));
    }

    public function test_sort_by_deadline_asc(): void
    {
        Task::factory()->create([
            'dealership_id' => $this->dealership->id, 'creator_id' => $this->manager->id,
            'deadline' => now()->addDays(10),
        ]);
        Task::factory()->create([
            'dealership_id' => $this->dealership->id, 'creator_id' => $this->manager->id,
            'deadline' => now()->addDays(1),
        ]);
        Task::factory()->create([
            'dealership_id' => $this->dealership->id, 'creator_id' => $this->manager->id,
            'deadline' => now()->addDays(5),
        ]);

        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/tasks?dealership_id={$this->dealership->id}&sort_by=deadline&sort_dir=asc&per_page=100");

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(3, $data);
        // First task should have the earliest deadline
        $this->assertLessThanOrEqual($data[1]['deadline'], $data[0]['deadline']);
        $this->assertLessThanOrEqual($data[2]['deadline'], $data[1]['deadline']);
    }

    public function test_invalid_sort_field_defaults_to_created_at(): void
    {
        Task::factory()->count(3)->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
        ]);

        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/tasks?dealership_id={$this->dealership->id}&sort_by=invalid_field");

        // Should not crash, should use default sort
        $response->assertOk();
        $this->assertCount(3, $response->json('data'));
    }

    public function test_invalid_sort_dir_defaults_to_desc(): void
    {
        Task::factory()->count(3)->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
        ]);

        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/tasks?dealership_id={$this->dealership->id}&sort_dir=invalid");

        $response->assertOk();
        $this->assertCount(3, $response->json('data'));
    }

    public function test_links_contain_pagination_urls(): void
    {
        Task::factory()->count(20)->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
        ]);

        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/tasks?dealership_id={$this->dealership->id}&per_page=5");

        $response->assertOk();
        $links = $response->json('links');
        $this->assertNotNull($links['first']);
        $this->assertNotNull($links['last']);
        $this->assertNotNull($links['next']);
        $this->assertNull($links['prev']); // First page has no prev
    }
}
