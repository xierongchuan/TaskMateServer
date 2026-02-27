<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Helpers\TimeHelper;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskGenerator;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Job to process task generators and create daily task instances.
 *
 * This job runs periodically (e.g., every hour) and:
 * 1. Finds all active generators that should create a task today
 * 2. Creates task instances with proper assignments
 * 3. Updates the last_generated_at timestamp
 */
class ProcessTaskGeneratorsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue('task_generators');
    }

    public function handle(): void
    {
        $now = TimeHelper::nowUtc();
        Log::info('ProcessTaskGeneratorsJob started', ['time_utc' => $now->toIso8601ZuluString()]);

        $generators = TaskGenerator::where('is_active', true)
            ->whereDate('start_date', '<=', $now->toDateString())
            ->where(function ($q) use ($now) {
                $q->whereNull('end_date')
                  ->orWhereDate('end_date', '>=', $now->toDateString());
            })
            ->get();

        $createdCount = 0;
        $skippedCount = 0;

        foreach ($generators as $generator) {
            try {
                // Используем транзакцию с блокировкой для предотвращения race condition
                // между несколькими воркерами
                $created = DB::transaction(function () use ($generator, $now) {
                    // Перезагружаем генератор с блокировкой
                    $lockedGenerator = TaskGenerator::where('id', $generator->id)
                        ->lockForUpdate()
                        ->first();

                    if (!$lockedGenerator || !$lockedGenerator->shouldGenerateToday($now)) {
                        return false;
                    }

                    $this->createTaskFromGenerator($lockedGenerator, $now);
                    return true;
                });

                if ($created) {
                    $createdCount++;
                } else {
                    $skippedCount++;
                }
            } catch (\Throwable $e) {
                Log::error('Failed to process task generator', [
                    'generator_id' => $generator->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        Log::info('ProcessTaskGeneratorsJob completed', [
            'created' => $createdCount,
            'skipped' => $skippedCount,
            'total_generators' => $generators->count(),
        ]);
    }

    /**
     * Create a task instance from a generator.
     * All times are in UTC.
     *
     * ВАЖНО: Этот метод должен вызываться внутри транзакции с lockForUpdate на генератор.
     */
    private function createTaskFromGenerator(TaskGenerator $generator, Carbon $now): void
    {
        // Calculate appear time and deadline for today (all in UTC)
        $appearTime = $generator->getAppearTimeForDate($now);
        $deadlineTime = $generator->getDeadlineTimeForDate($now);

        // Create the task with UTC times
        // Task model mutators parse ISO 8601 and store in UTC
        $task = Task::create([
            'generator_id' => $generator->id,
            'title' => $generator->title,
            'description' => $generator->description,
            'comment' => $generator->comment,
            'creator_id' => $generator->creator_id,
            'dealership_id' => $generator->dealership_id,
            'appear_date' => $appearTime->toIso8601ZuluString(),
            'deadline' => $deadlineTime->toIso8601ZuluString(),
            'scheduled_date' => $now->copy()->startOfDay(),
            'task_type' => $generator->task_type,
            'response_type' => $generator->response_type,
            'priority' => $generator->priority,
            'tags' => $generator->tags,
            'notification_settings' => $generator->notification_settings,
            'is_active' => true,
            'recurrence' => 'none', // Individual task instances are not recurring
        ]);

        // Copy assignments from generator
        $assignments = $generator->assignments;
        foreach ($assignments as $assignment) {
            TaskAssignment::create([
                'task_id' => $task->id,
                'user_id' => $assignment->user_id,
            ]);
        }

        // Update generator's last_generated_at (in UTC)
        $generator->update([
            'last_generated_at' => $now,
        ]);

        Log::info('Created task from generator', [
            'generator_id' => $generator->id,
            'task_id' => $task->id,
            'title' => $task->title,
            'scheduled_date_utc' => $now->toDateString(),
        ]);
    }
}
