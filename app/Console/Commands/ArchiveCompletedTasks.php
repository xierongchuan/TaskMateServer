<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Helpers\TimeHelper;
use App\Models\Task;
use App\Services\SettingsService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ArchiveCompletedTasks extends Command
{
    protected $signature = 'tasks:archive-completed
                            {--type=all : Type of tasks to archive: completed, overdue, or all}
                            {--force : Force archiving even if not the configured day/time}';

    protected $description = 'Archive completed and/or overdue tasks based on settings';

    public function handle(SettingsService $settingsService): int
    {
        $type = $this->option('type');
        $force = $this->option('force');
        $now = TimeHelper::nowUtc();
        $todayDayOfWeek = $now->dayOfWeek === 0 ? 7 : $now->dayOfWeek; // 1-7 (Mon-Sun)
        $currentTime = $now->format('H:i');

        $this->info("Current time UTC: {$now->toIso8601ZuluString()} (Day: $todayDayOfWeek)");
        $this->info("Archive type: $type");

        $archivedCompleted = 0;
        $archivedOverdue = 0;

        // Get dealership-specific settings
        $dealershipSettings = DB::table('settings')
            ->whereIn('key', ['archive_completed_time', 'archive_overdue_day_of_week', 'archive_overdue_time'])
            ->whereNotNull('dealership_id')
            ->get()
            ->groupBy('dealership_id');

        // Get global settings
        $globalCompletedTime = $settingsService->get('archive_completed_time') ?? '03:00';
        $globalOverdueDayOfWeek = (int) ($settingsService->get('archive_overdue_day_of_week') ?? 0);
        $globalOverdueTime = $settingsService->get('archive_overdue_time') ?? '03:00';

        // Process dealership-specific archiving
        $processedDealerships = [];
        foreach ($dealershipSettings as $dealershipId => $settings) {
            $settingsMap = $settings->pluck('value', 'key')->toArray();

            $completedTime = $settingsMap['archive_completed_time'] ?? $globalCompletedTime;
            $overdueDayOfWeek = (int) ($settingsMap['archive_overdue_day_of_week'] ?? $globalOverdueDayOfWeek);
            $overdueTime = $settingsMap['archive_overdue_time'] ?? $globalOverdueTime;

            // Archive completed tasks (daily at configured time)
            if (in_array($type, ['completed', 'all'])) {
                if ($force || $this->isTimeMatch($currentTime, $completedTime)) {
                    $count = $this->archiveCompletedTasks((int) $dealershipId);
                    $archivedCompleted += $count;
                    if ($count > 0) {
                        $this->info("  Dealership $dealershipId: Archived $count completed tasks");
                    }
                }
            }

            // Archive overdue tasks (weekly at configured day/time)
            if (in_array($type, ['overdue', 'all'])) {
                if ($overdueDayOfWeek > 0) {
                    if ($force || ($todayDayOfWeek === $overdueDayOfWeek && $this->isTimeMatch($currentTime, $overdueTime))) {
                        $count = $this->archiveOverdueTasks((int) $dealershipId);
                        $archivedOverdue += $count;
                        if ($count > 0) {
                            $this->info("  Dealership $dealershipId: Archived $count overdue tasks");
                        }
                    }
                }
            }

            $processedDealerships[] = $dealershipId;
        }

        // Process tasks for dealerships without specific settings (use global settings)
        $remainingDealerships = DB::table('tasks')
            ->whereNull('archived_at')
            ->where('is_active', true)
            ->whereNotNull('dealership_id')
            ->whereNotIn('dealership_id', $processedDealerships)
            ->distinct()
            ->pluck('dealership_id');

        foreach ($remainingDealerships as $dealershipId) {
            // Archive completed tasks (daily at configured time)
            if (in_array($type, ['completed', 'all'])) {
                if ($force || $this->isTimeMatch($currentTime, $globalCompletedTime)) {
                    $count = $this->archiveCompletedTasks((int) $dealershipId);
                    $archivedCompleted += $count;
                    if ($count > 0) {
                        $this->info("  Dealership $dealershipId: Archived $count completed tasks (global settings)");
                    }
                }
            }

            // Archive overdue tasks (weekly at configured day/time)
            if (in_array($type, ['overdue', 'all'])) {
                if ($globalOverdueDayOfWeek > 0) {
                    if ($force || ($todayDayOfWeek === $globalOverdueDayOfWeek && $this->isTimeMatch($currentTime, $globalOverdueTime))) {
                        $count = $this->archiveOverdueTasks((int) $dealershipId);
                        $archivedOverdue += $count;
                        if ($count > 0) {
                            $this->info("  Dealership $dealershipId: Archived $count overdue tasks (global settings)");
                        }
                    }
                }
            }
        }

        // Summary
        $totalArchived = $archivedCompleted + $archivedOverdue;
        if ($totalArchived > 0) {
            $this->info("Total archived: $archivedCompleted completed, $archivedOverdue overdue tasks");
            Log::info("Auto-archived tasks", [
                'completed' => $archivedCompleted,
                'overdue' => $archivedOverdue,
            ]);
        } else {
            $this->info("No tasks to archive");
        }

        return Command::SUCCESS;
    }

    /**
     * Check if current time matches configured time (with 5 minute tolerance).
     * All times are in UTC.
     */
    private function isTimeMatch(string $currentTime, string $configuredTime): bool
    {
        $current = Carbon::createFromFormat('H:i', $currentTime, 'UTC');
        $configured = Carbon::createFromFormat('H:i', $configuredTime, 'UTC');

        $diffMinutes = abs($current->diffInMinutes($configured));
        // Учёт перехода через полночь: 1440 минут в сутках
        $diffMinutes = min($diffMinutes, 1440 - $diffMinutes);

        return $diffMinutes <= 5;
    }

    /**
     * Archive completed tasks for a dealership.
     *
     * Учитывает тип задачи:
     * - individual: архивируется если есть хотя бы один completed response
     * - group: архивируется только если ВСЕ назначенные пользователи выполнили
     *
     * Использует вычисляемый Task.status для корректной проверки.
     */
    private function archiveCompletedTasks(?int $dealershipId): int
    {
        $cutoffDate = TimeHelper::nowUtc()->subDay();

        $query = Task::query()
            ->where('is_active', true)
            ->whereNull('archived_at')
            ->whereHas('responses', function ($q) {
                $q->where('status', 'completed');
            });

        if ($dealershipId !== null) {
            $query->where('dealership_id', $dealershipId);
        } else {
            $query->whereNull('dealership_id');
        }

        $archivedCount = 0;

        // Загружаем responses И assignments для корректного вычисления Task.status
        // (для групповых задач нужно проверить что ВСЕ назначенные выполнили)
        $query->with(['responses', 'assignments'])->chunk(500, function ($tasks) use (&$archivedCount, $cutoffDate) {
            foreach ($tasks as $task) {
                // Используем вычисляемый статус Task, который учитывает:
                // - для individual: первый completed response
                // - для group: все назначенные должны выполнить
                $taskStatus = $task->status;

                // Архивируем только если задача действительно завершена
                if (! in_array($taskStatus, ['completed', 'completed_late'])) {
                    continue;
                }

                // Находим последний completed response для проверки времени
                // Используем created_at для обратной совместимости с тестами и существующими данными
                // (responded_at имеет DEFAULT CURRENT_TIMESTAMP в БД, что может быть некорректно для старых записей)
                $lastResponse = $task->responses
                    ->where('status', 'completed')
                    ->sortByDesc('created_at')
                    ->first();

                // Архивируем только если завершено более 1 дня назад
                if ($lastResponse && Carbon::parse($lastResponse->created_at)->lt($cutoffDate)) {
                    $task->update([
                        'is_active' => false,
                        'archived_at' => TimeHelper::nowUtc(),
                        'archive_reason' => 'completed',
                    ]);
                    $archivedCount++;
                }
            }
        });

        return $archivedCount;
    }

    /**
     * Archive overdue tasks for a dealership
     */
    private function archiveOverdueTasks(?int $dealershipId): int
    {
        $cutoffDate = TimeHelper::nowUtc()->subDay();

        $query = Task::query()
            ->where('is_active', true)
            ->whereNull('archived_at')
            ->whereNotNull('deadline')
            ->where('deadline', '<', $cutoffDate)
            ->whereDoesntHave('responses', function ($q) {
                $q->where('status', 'completed');
            });

        if ($dealershipId !== null) {
            $query->where('dealership_id', $dealershipId);
        } else {
            $query->whereNull('dealership_id');
        }

        $archivedCount = 0;

        // Используем chunk() для предотвращения memory leak при большом количестве задач
        $query->chunk(500, function ($tasks) use (&$archivedCount) {
            foreach ($tasks as $task) {
                $task->update([
                    'is_active' => false,
                    'archived_at' => TimeHelper::nowUtc(),
                    'archive_reason' => 'expired',
                ]);
                $archivedCount++;
            }
        });

        return $archivedCount;
    }
}
