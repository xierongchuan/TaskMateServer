<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Helpers\TimeHelper;
use App\Models\CalendarDay;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskGenerator;
use App\Services\SettingsService;
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

        $generators = TaskGenerator::with(['assignments', 'dealership'])
            ->where('is_active', true)
            ->whereDate('start_date', '<=', $now->toDateString())
            ->where(function ($q) use ($now) {
                $q->whereNull('end_date')
                    ->orWhereDate('end_date', '>=', $now->toDateString());
            })
            ->get();

        // Pre-compute holiday status for all dealerships in a single batch query,
        // avoiding N+1 queries (CalendarDay::isHoliday() per generator).
        $holidayMap = $this->buildHolidayMap($generators, $now);

        $createdCount = 0;
        $skippedCount = 0;

        foreach ($generators as $generator) {
            // Retrieve pre-computed holiday status for this generator's dealership.
            // Null dealership_id uses key 'null' (global calendar result).
            $dealershipKey = $generator->dealership_id !== null
                ? $generator->dealership_id
                : 'null';
            $preloadedIsHoliday = $holidayMap[$dealershipKey] ?? null;

            try {
                // Используем транзакцию с блокировкой для предотвращения race condition
                // между несколькими воркерами
                $created = DB::transaction(function () use ($generator, $now, $preloadedIsHoliday) {
                    // Перезагружаем генератор с блокировкой
                    $lockedGenerator = TaskGenerator::where('id', $generator->id)
                        ->lockForUpdate()
                        ->first();

                    if (! $lockedGenerator || ! $lockedGenerator->shouldGenerateToday($now, $preloadedIsHoliday)) {
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
     * Build a map of holiday status for all unique dealerships in the generator collection.
     *
     * Executes at most 3 DB queries for the entire batch (compared to N*3 queries before):
     *   1. Check which dealerships have their own calendar for the year
     *   2. Fetch calendar records for dealerships with their own calendar
     *   3. Fetch global calendar records for dealerships without their own calendar
     *
     * Map keys:
     *   - int   => bool   — dealership_id to holiday status (own calendar)
     *   - 'null' => bool  — global calendar status (for generators with null dealership_id)
     *
     * @param  \Illuminate\Support\Collection<int, TaskGenerator>  $generators
     * @return array<int|string, bool>
     */
    private function buildHolidayMap(\Illuminate\Support\Collection $generators, Carbon $now): array
    {
        $settingsService = app(SettingsService::class);

        // Collect unique dealership IDs (excluding null).
        $dealershipIds = $generators
            ->pluck('dealership_id')
            ->filter()
            ->unique()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        // Build per-dealership local date map (dealership_id => 'Y-m-d' in local timezone).
        // Each dealership may have a different timezone, so local calendar dates may differ.
        $localDates = [];
        foreach ($dealershipIds as $dealershipId) {
            $timezone = $settingsService->getTimezone($dealershipId);
            $localDates[$dealershipId] = $now->copy()->setTimezone($timezone)->toDateString();
        }

        $year = (int) $now->format('Y');

        // Single batch call — replaces N*3 queries with at most 3 queries total.
        $batchData = CalendarDay::getHolidayDataForDealerships($dealershipIds, $localDates, $year);

        $ownCalendarIds = $batchData['ownCalendarIds'];    // int[]
        $dealershipRecords = $batchData['dealershipRecords']; // Collection keyed by dealership_id
        $globalRecords = $batchData['globalRecords'];         // Collection keyed by date string

        $holidayMap = [];

        // Resolve holiday status for each dealership with its own calendar.
        foreach ($ownCalendarIds as $dealershipId) {
            $record = $dealershipRecords->get($dealershipId);
            $holidayMap[$dealershipId] = $record !== null && $record->type === 'holiday';
        }

        // Resolve holiday status for dealerships falling back to the global calendar.
        $fallbackIds = array_diff($dealershipIds, $ownCalendarIds);
        foreach ($fallbackIds as $dealershipId) {
            $localDate = $localDates[$dealershipId] ?? null;
            if ($localDate === null) {
                $holidayMap[$dealershipId] = false;

                continue;
            }
            $record = $globalRecords->get($localDate);
            $holidayMap[$dealershipId] = $record !== null && $record->type === 'holiday';
        }

        // Resolve holiday status for generators with null dealership_id (global calendar).
        $hasNullDealership = $generators->contains(fn ($g) => $g->dealership_id === null);
        if ($hasNullDealership) {
            $globalTimezone = $settingsService->getTimezone(null);
            $globalLocalDate = $now->copy()->setTimezone($globalTimezone)->toDateString();

            // Re-use already-fetched global records if the date matches; otherwise query once.
            // Global records are already keyed by date string, so a direct lookup is free.
            $globalRecord = $globalRecords->get($globalLocalDate);

            if ($globalRecord === null && ! $globalRecords->has($globalLocalDate)) {
                // The date wasn't fetched in the batch (no fallback dealerships shared this date).
                $globalRecord = CalendarDay::whereNull('dealership_id')
                    ->whereDate('date', $globalLocalDate)
                    ->first();
            }

            $holidayMap['null'] = $globalRecord !== null && $globalRecord->type === 'holiday';
        }

        return $holidayMap;
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

        // Copy assignments from generator (bulk insert)
        $assignments = $generator->assignments;
        if ($assignments->isNotEmpty()) {
            $now = TimeHelper::nowUtc();
            $bulkData = $assignments->map(fn ($assignment) => [
                'task_id' => $task->id,
                'user_id' => $assignment->user_id,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();
            TaskAssignment::insert($bulkData);
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
