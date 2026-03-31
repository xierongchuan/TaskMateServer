<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\Role;
use App\Models\AutoDealership;
use App\Models\CalendarDay;
use App\Models\Task;
use App\Models\TaskGenerator;
use App\Models\TaskGeneratorAssignment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskGeneratorExecutionTest extends TestCase
{
    use RefreshDatabase;

    private AutoDealership $dealership;

    private User $manager;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dealership = AutoDealership::factory()->create();
        $this->manager = User::factory()->create([
            'role' => Role::MANAGER->value,
            'dealership_id' => $this->dealership->id,
        ]);
        $this->employee = User::factory()->create([
            'role' => Role::EMPLOYEE->value,
            'dealership_id' => $this->dealership->id,
        ]);
    }

    public function test_generator_copies_assignments_to_generated_task(): void
    {
        $employee2 = User::factory()->create([
            'role' => Role::EMPLOYEE->value,
            'dealership_id' => $this->dealership->id,
        ]);

        $generator = TaskGenerator::factory()->daily()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
            'task_type' => 'group',
            'start_date' => Carbon::today(),
            'is_active' => true,
        ]);

        // Factory's configure() already created one assignment; add second
        TaskGeneratorAssignment::create(['generator_id' => $generator->id, 'user_id' => $employee2->id]);

        // Simulate generation by calling the job directly
        $job = app(\App\Jobs\ProcessTaskGeneratorsJob::class);
        $job->handle();

        // Verify task was generated with correct assignments
        $task = Task::where('generator_id', $generator->id)->first();
        $this->assertNotNull($task);
        $this->assertEquals('group', $task->task_type);
        $this->assertGreaterThanOrEqual(2, $task->assignments->count());
    }

    public function test_generator_does_not_duplicate_on_same_day(): void
    {
        $generator = TaskGenerator::factory()->daily()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
            'start_date' => Carbon::today(),
            'is_active' => true,
            'last_generated_at' => Carbon::today()->setHour(9), // Already generated today
        ]);

        $job = app(\App\Jobs\ProcessTaskGeneratorsJob::class);
        $job->handle();

        // No new task should be created because last_generated_at is today
        $this->assertDatabaseMissing('tasks', [
            'generator_id' => $generator->id,
        ]);
    }

    public function test_generator_skips_inactive_generators(): void
    {
        $generator = TaskGenerator::factory()->daily()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
            'start_date' => Carbon::today(),
            'is_active' => false,
        ]);

        $job = app(\App\Jobs\ProcessTaskGeneratorsJob::class);
        $job->handle();

        $this->assertDatabaseMissing('tasks', [
            'generator_id' => $generator->id,
        ]);
    }

    public function test_generator_skips_holiday(): void
    {
        $generator = TaskGenerator::factory()->daily()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
            'start_date' => Carbon::today(),
            'is_active' => true,
        ]);

        // Mark today as holiday for this dealership
        CalendarDay::create([
            'date' => Carbon::today()->toDateString(),
            'is_holiday' => true,
            'type' => 'holiday',
            'dealership_id' => $this->dealership->id,
        ]);

        // shouldGenerateToday returns false for holidays
        $this->assertFalse($generator->shouldGenerateToday(Carbon::today(), true));
    }

    public function test_generator_sets_correct_task_fields(): void
    {
        $generator = TaskGenerator::factory()->daily()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
            'title' => 'Ежедневная проверка',
            'description' => 'Проверить всё',
            'task_type' => 'individual',
            'response_type' => 'completion_with_proof',
            'priority' => 'high',
            'tags' => ['рутина', 'ежедневно'],
            'start_date' => Carbon::today(),
            'is_active' => true,
        ]);

        $job = app(\App\Jobs\ProcessTaskGeneratorsJob::class);
        $job->handle();

        $task = Task::where('generator_id', $generator->id)->first();
        $this->assertNotNull($task);
        $this->assertEquals('Ежедневная проверка', $task->title);
        $this->assertEquals('Проверить всё', $task->description);
        $this->assertEquals('individual', $task->task_type);
        $this->assertEquals('completion_with_proof', $task->response_type);
        $this->assertEquals('high', $task->priority);
        $this->assertEquals(['рутина', 'ежедневно'], $task->tags);
        $this->assertEquals($this->dealership->id, $task->dealership_id);
        $this->assertTrue($task->is_active);
    }

    public function test_generator_updates_last_generated_at(): void
    {
        $generator = TaskGenerator::factory()->daily()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
            'start_date' => Carbon::today(),
            'is_active' => true,
            'last_generated_at' => null,
        ]);

        $job = app(\App\Jobs\ProcessTaskGeneratorsJob::class);
        $job->handle();

        $generator->refresh();
        $this->assertNotNull($generator->last_generated_at);
        $this->assertTrue($generator->last_generated_at->isToday());
    }

    public function test_weekly_generator_generates_on_correct_day(): void
    {
        // 2026-03-30 is Monday, 10:00 UTC
        $monday = Carbon::parse('2026-03-30 10:00:00', 'UTC');
        Carbon::setTestNow($monday);

        $generator = TaskGenerator::factory()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
            'recurrence' => 'weekly',
            'recurrence_days_of_week' => [1], // Monday
            'recurrence_time' => '09:00:00',
            'start_date' => $monday->copy()->startOfDay(),
            'is_active' => true,
        ]);

        $this->assertTrue($generator->shouldGenerateToday($monday, false));

        Carbon::setTestNow();
    }

    public function test_weekly_generator_does_not_generate_on_wrong_day(): void
    {
        // 2026-04-01 is Wednesday, 10:00 UTC
        $wednesday = Carbon::parse('2026-04-01 10:00:00', 'UTC');
        Carbon::setTestNow($wednesday);

        $generator = TaskGenerator::factory()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
            'recurrence' => 'weekly',
            'recurrence_days_of_week' => [1], // Monday only
            'recurrence_time' => '09:00:00',
            'start_date' => $wednesday->copy()->subWeek(),
            'is_active' => true,
        ]);

        $this->assertFalse($generator->shouldGenerateToday($wednesday, false));

        Carbon::setTestNow();
    }
}
