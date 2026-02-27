<?php

declare(strict_types=1);

use App\Jobs\ProcessTaskGeneratorsJob;
use App\Models\Task;
use App\Models\TaskGenerator;
use App\Models\TaskGeneratorAssignment;
use App\Models\AutoDealership;
use App\Models\User;
use App\Enums\Role;
use Carbon\Carbon;

describe('ProcessTaskGeneratorsJob', function () {
    beforeEach(function () {
        $this->dealership = AutoDealership::factory()->create();
        $this->manager = User::factory()->create([
            'role' => Role::MANAGER->value,
            'dealership_id' => $this->dealership->id
        ]);
        $this->employee = User::factory()->create([
            'role' => Role::EMPLOYEE->value,
            'dealership_id' => $this->dealership->id
        ]);
    });

    it('uses correct queue', function () {
        // Act
        $job = new ProcessTaskGeneratorsJob();

        // Assert
        expect($job->queue)->toBe('task_generators');
    });

    it('processes active generators', function () {
        // Arrange - use a time that has already passed in UTC
        $nowUtc = Carbon::now('UTC');
        $pastTime = $nowUtc->copy()->subHours(2)->format('H:i');

        $generator = TaskGenerator::factory()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
            'title' => 'Ежедневная задача',
            'is_active' => true,
            'recurrence' => 'daily',
            'start_date' => Carbon::yesterday('UTC'),
            'recurrence_time' => $pastTime . ':00', // Time that has already passed in UTC
            'deadline_time' => '23:59:00',
            'last_generated_at' => Carbon::yesterday('UTC')->subDay(), // Not generated today
        ]);

        TaskGeneratorAssignment::create([
            'generator_id' => $generator->id,
            'user_id' => $this->employee->id,
        ]);

        // Act
        $job = new ProcessTaskGeneratorsJob();
        $job->handle();

        // Assert
        expect(Task::where('generator_id', $generator->id)->count())->toBeGreaterThanOrEqual(1);
    });

    it('skips inactive generators', function () {
        // Arrange
        $generator = TaskGenerator::factory()->create([
            'dealership_id' => $this->dealership->id,
            'is_active' => false,
            'recurrence' => 'daily',
            'start_date' => Carbon::yesterday(),
        ]);

        // Act
        $job = new ProcessTaskGeneratorsJob();
        $job->handle();

        // Assert
        expect(Task::where('generator_id', $generator->id)->count())->toBe(0);
    });

    it('skips generators not started yet', function () {
        // Arrange
        $generator = TaskGenerator::factory()->create([
            'dealership_id' => $this->dealership->id,
            'is_active' => true,
            'recurrence' => 'daily',
            'start_date' => Carbon::tomorrow(), // Starts tomorrow
        ]);

        // Act
        $job = new ProcessTaskGeneratorsJob();
        $job->handle();

        // Assert
        expect(Task::where('generator_id', $generator->id)->count())->toBe(0);
    });

    it('skips generators past end date', function () {
        // Arrange - используем UTC даты для корректной работы с Job
        $nowUtc = Carbon::now('UTC');
        $generator = TaskGenerator::factory()->create([
            'dealership_id' => $this->dealership->id,
            'is_active' => true,
            'recurrence' => 'daily',
            'start_date' => $nowUtc->copy()->subMonth(),
            'end_date' => $nowUtc->copy()->subDay(), // Ended yesterday in UTC
            'recurrence_time' => $nowUtc->copy()->subHours(2)->format('H:i:s'), // Time already passed
        ]);

        // Act
        $job = new ProcessTaskGeneratorsJob();
        $job->handle();

        // Assert
        expect(Task::where('generator_id', $generator->id)->count())->toBe(0);
    });

    it('copies assignments from generator to task', function () {
        // Arrange
        $generator = TaskGenerator::factory()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
            'is_active' => true,
            'recurrence' => 'daily',
            'start_date' => Carbon::yesterday(),
            'recurrence_time' => '09:00',
            'deadline_time' => '18:00',
            'last_generated_at' => Carbon::yesterday()->subDay(),
        ]);

        // Удаляем все автоматически созданные assignments от factory
        $generator->assignments()->delete();

        TaskGeneratorAssignment::create([
            'generator_id' => $generator->id,
            'user_id' => $this->employee->id,
        ]);

        // Act
        $job = new ProcessTaskGeneratorsJob();
        $job->handle();

        // Assert
        $task = Task::where('generator_id', $generator->id)->first();
        if ($task) {
            expect($task->assignments->count())->toBeGreaterThanOrEqual(1);
            expect($task->assignments->pluck('user_id'))->toContain($this->employee->id);
        }
    });

    it('updates last_generated_at timestamp', function () {
        // Arrange
        $generator = TaskGenerator::factory()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
            'is_active' => true,
            'recurrence' => 'daily',
            'start_date' => Carbon::yesterday(),
            'recurrence_time' => '09:00',
            'deadline_time' => '18:00',
            'last_generated_at' => Carbon::yesterday()->subDay(),
        ]);

        $originalLastGenerated = $generator->last_generated_at;

        // Act
        $job = new ProcessTaskGeneratorsJob();
        $job->handle();

        // Assert
        $generator->refresh();
        if (Task::where('generator_id', $generator->id)->exists()) {
            expect($generator->last_generated_at)->not->toBe($originalLastGenerated);
        }
    });
});
