<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ShiftStatus;
use App\Helpers\TimeHelper;
use App\Http\Resources\TaskResource;
use App\Models\AutoDealership;
use App\Models\CalendarDay;
use App\Models\Shift;
use App\Models\Task;
use App\Models\TaskGenerator;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Сервис для получения данных дашборда.
 *
 * Оптимизирует запросы к базе данных путём объединения
 * и использования агрегатных функций.
 */
class DashboardService
{
    /**
     * Кэш временных границ текущего дня.
     *
     * @var array{start: Carbon, end: Carbon}|null
     */
    private ?array $todayBoundaries = null;

    /**
     * Получает все данные для дашборда.
     *
     * @param  int|null  $dealershipId  ID автосалона для фильтрации
     * @return array<string, mixed>
     */
    public function getDashboardData(?int $dealershipId = null): array
    {
        // Определяем границы дня по timezone автосалона
        $timezone = null;
        if ($dealershipId) {
            $dealership = AutoDealership::find($dealershipId);
            $timezone = $dealership?->timezone;
        }

        if ($timezone) {
            $this->todayBoundaries = TimeHelper::dayBoundariesForTimezone($timezone);
        } else {
            $this->todayBoundaries = [
                'start' => TimeHelper::startOfDayUtc(),
                'end' => TimeHelper::endOfDayUtc(),
            ];
        }

        // Получаем статистику задач одним оптимизированным запросом
        $taskStats = $this->getTaskStatistics($dealershipId);

        // Получаем список просроченных задач (reuse overdue count from taskStats)
        $overdueTasksList = $this->getOverdueTasksList($dealershipId);

        // Получаем активные смены с eager loading
        $activeShifts = $this->getActiveShifts($dealershipId);
        $userCount = $this->getUserCount($dealershipId);
        $generatorStats = $this->getGeneratorStats($dealershipId);

        return [
            'total_users' => $userCount,
            'active_users' => $userCount,
            'total_tasks' => $taskStats['total_active'],
            'active_tasks' => $taskStats['total_active'],
            'completed_tasks' => $taskStats['completed_today'],
            'overdue_tasks' => $taskStats['overdue'],
            'overdue_tasks_list' => $overdueTasksList,
            'pending_review_count' => $this->getPendingReviewCount($dealershipId),
            'pending_review_tasks' => $this->getPendingReviewTasks($dealershipId, 5),
            'open_shifts' => count($activeShifts),
            'late_shifts_today' => $this->getLateShiftsCount($dealershipId),
            'active_shifts' => $activeShifts,
            'dealership_shift_stats' => $this->getDealershipShiftStats($dealershipId),
            'today_tasks_list' => $this->getTodayTasksList($dealershipId),
            'active_generators' => $generatorStats['active'],
            'total_generators' => $generatorStats['total'],
            'tasks_generated_today' => $generatorStats['generated_today'],
            'timestamp' => TimeHelper::toIsoZulu(TimeHelper::nowUtc()),
        ];
    }

    /**
     * Получает статистику задач оптимизированным запросом.
     *
     * @return array{total_active: int, completed_today: int, overdue: int, postponed: int}
     */
    protected function getTaskStatistics(?int $dealershipId): array
    {
        $nowUtc = TimeHelper::nowUtc();
        $todayStart = $this->todayBoundaries['start'];
        $todayEnd = $this->todayBoundaries['end'];

        // Оптимизированный запрос с условными агрегатами
        $query = Task::query()
            ->where('is_active', true)
            ->when($dealershipId, fn ($q) => $q->where('dealership_id', $dealershipId))
            ->selectRaw('
                COUNT(*) as total_active,
                SUM(CASE WHEN postpone_count > 0 THEN 1 ELSE 0 END) as postponed
            ')
            ->first();

        // Подсчёт просроченных задач (без выполненных)
        $overdueCount = Task::overdue($nowUtc)
            ->when($dealershipId, fn ($q) => $q->where('dealership_id', $dealershipId))
            ->count();

        // Подсчёт завершённых сегодня (используем ту же логику что и TaskFilterService)
        $completedToday = Task::query()
            ->whereNull('archived_at')
            ->when($dealershipId, fn ($q) => $q->where('dealership_id', $dealershipId))
            ->completed($todayStart, $todayEnd)
            ->count();

        return [
            'total_active' => (int) ($query->total_active ?? 0),
            'completed_today' => $completedToday,
            'overdue' => $overdueCount,
            'postponed' => (int) ($query->postponed ?? 0),
        ];
    }

    /**
     * Получает активные смены.
     */
    protected function getActiveShifts(?int $dealershipId): Collection
    {
        return Shift::with(['user:id,full_name', 'dealership:id,name'])
            ->whereIn('status', ShiftStatus::activeStatusValues())
            ->whereNull('shift_end')
            ->when($dealershipId, fn ($q) => $q->where('dealership_id', $dealershipId))
            ->orderBy('shift_start')
            ->get()
            ->map(fn ($shift) => [
                'id' => $shift->id,
                'user' => [
                    'id' => $shift->user->id,
                    'full_name' => $shift->user->full_name,
                ],
                'dealership' => $shift->dealership ? [
                    'id' => $shift->dealership->id,
                    'name' => $shift->dealership->name,
                ] : null,
                'status' => $shift->status,
                'opened_at' => TimeHelper::toIsoZulu($shift->shift_start),
                'closed_at' => TimeHelper::toIsoZulu($shift->shift_end),
                'scheduled_start' => TimeHelper::toIsoZulu($shift->scheduled_start),
                'scheduled_end' => TimeHelper::toIsoZulu($shift->scheduled_end),
                'is_late' => $shift->late_minutes > 0,
                'late_minutes' => $shift->late_minutes,
            ]);
    }

    /**
     * Получает статистику сотрудников на смене по автосалонам.
     */
    protected function getDealershipShiftStats(?int $dealershipId): Collection
    {
        $dealerships = AutoDealership::query()
            ->when($dealershipId, fn ($q) => $q->where('id', $dealershipId))
            ->get();

        if ($dealerships->isEmpty()) {
            return collect();
        }

        $dealershipIds = $dealerships->pluck('id')->toArray();

        // Batch: количество сотрудников по автосалонам
        $employeeCounts = User::whereIn('dealership_id', $dealershipIds)
            ->where('role', 'employee')
            ->selectRaw('dealership_id, COUNT(*) as count')
            ->groupBy('dealership_id')
            ->pluck('count', 'dealership_id');

        // Batch: количество активных смен по автосалонам
        $shiftCounts = Shift::whereIn('dealership_id', $dealershipIds)
            ->whereIn('status', ShiftStatus::activeStatusValues())
            ->whereNull('shift_end')
            ->selectRaw('dealership_id, COUNT(*) as count')
            ->groupBy('dealership_id')
            ->pluck('count', 'dealership_id');

        // Batch: расписания смен по автосалонам
        $allSchedules = \App\Models\ShiftSchedule::whereIn('dealership_id', $dealershipIds)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'dealership_id', 'name', 'start_time', 'end_time'])
            ->groupBy('dealership_id');

        // Batch: предзагрузка данных о праздниках одним запросом вместо N*3 запросов.
        // Каждое дилерство может быть в своём timezone, поэтому конвертируем UTC → локальный день.
        $nowUtc = TimeHelper::nowUtc();
        $year = (int) $nowUtc->format('Y');

        // Вычисляем локальную дату для каждого дилерства (учитываем timezone)
        $localDates = [];
        foreach ($dealerships as $dealership) {
            $timezone = $dealership->timezone ?? '+05:00';
            $localDates[$dealership->id] = $nowUtc->copy()->setTimezone($timezone)->toDateString();
        }

        // Один batch-запрос вместо 3 запросов на каждое дилерство
        $holidayData = CalendarDay::getHolidayDataForDealerships($dealershipIds, $localDates, $year);

        return $dealerships->map(function ($dealership) use (
            $employeeCounts,
            $shiftCounts,
            $allSchedules,
            $holidayData,
            $localDates
        ) {
            $schedules = $allSchedules->get($dealership->id, collect());

            $currentOrNextSchedule = null;
            $isCurrentSchedule = false;
            if ($schedules->isNotEmpty()) {
                $timezone = $dealership->timezone ?? '+05:00';
                $localNow = Carbon::now($timezone)->format('H:i');

                $currentOrNextSchedule = $schedules->first(fn ($s) => $s->containsTime($localNow));

                if ($currentOrNextSchedule) {
                    $isCurrentSchedule = true;
                } else {
                    $currentOrNextSchedule = $schedules->first(function ($s) use ($localNow) {
                        return substr($s->start_time, 0, 5) > $localNow;
                    }) ?? $schedules->first();
                }
            }

            // Определяем статус праздника из предзагруженных данных (без дополнительных запросов)
            $isHoliday = $this->resolveIsHolidayFromBatch(
                $dealership->id,
                $localDates[$dealership->id] ?? null,
                $holidayData
            );

            return [
                'dealership_id' => $dealership->id,
                'dealership_name' => $dealership->name,
                'total_employees' => $employeeCounts->get($dealership->id, 0),
                'on_shift_count' => $shiftCounts->get($dealership->id, 0),
                'shift_schedules' => $schedules->toArray(),
                'current_or_next_schedule' => $currentOrNextSchedule ? [
                    'id' => $currentOrNextSchedule->id,
                    'name' => $currentOrNextSchedule->name,
                    'start_time' => $currentOrNextSchedule->start_time,
                    'end_time' => $currentOrNextSchedule->end_time,
                    'is_current' => $isCurrentSchedule,
                ] : null,
                'is_today_holiday' => $isHoliday,
            ];
        });
    }

    /**
     * Определяет статус праздника из предзагруженных batch-данных.
     *
     * Воспроизводит логику CalendarDay::isHoliday() без дополнительных запросов к БД:
     * - Если дилерство имеет собственный календарь — используем только его запись
     * - Если нет — fallback на глобальную запись (dealership_id IS NULL)
     *
     * @param  int  $dealershipId  ID дилерского центра
     * @param  string|null  $localDate  Локальная дата дилерства в формате Y-m-d
     * @param  array{ownCalendarIds: array<int>, dealershipRecords: \Illuminate\Support\Collection, globalRecords: \Illuminate\Support\Collection}  $holidayData
     */
    private function resolveIsHolidayFromBatch(
        int $dealershipId,
        ?string $localDate,
        array $holidayData
    ): bool {
        if ($localDate === null) {
            return false;
        }

        $hasOwnCalendar = in_array($dealershipId, $holidayData['ownCalendarIds'], strict: true);

        if ($hasOwnCalendar) {
            // Используем ТОЛЬКО запись из собственного календаря дилерства
            $record = $holidayData['dealershipRecords']->get($dealershipId);

            return $record !== null && $record->type === 'holiday';
        }

        // Fallback: глобальная запись для данной локальной даты
        $globalRecord = $holidayData['globalRecords']->get($localDate);

        return $globalRecord !== null && $globalRecord->type === 'holiday';
    }

    /**
     * Получает количество опоздавших смен сегодня.
     */
    protected function getLateShiftsCount(?int $dealershipId): int
    {
        return Shift::query()
            ->whereBetween('shift_start', [$this->todayBoundaries['start'], $this->todayBoundaries['end']])
            ->where('late_minutes', '>', 0)
            ->when($dealershipId, fn ($q) => $q->where('dealership_id', $dealershipId))
            ->count();
    }

    /**
     * Получает список задач за сегодня: просроченные первыми, затем выполненные.
     *
     * "Сегодня" определяется по timezone автосалона (todayBoundaries).
     */
    protected function getTodayTasksList(?int $dealershipId): Collection
    {
        $todayStart = $this->todayBoundaries['start'];
        $todayEnd = $this->todayBoundaries['end'];
        $nowUtc = TimeHelper::nowUtc();

        // Просроченные задачи (overdue)
        $overdueTasks = Task::with(['creator:id,full_name', 'dealership:id,name', 'assignments.user:id,full_name', 'responses.user:id,full_name'])
            ->overdue($nowUtc)
            ->when($dealershipId, fn ($q) => $q->where('dealership_id', $dealershipId))
            ->orderBy('deadline')
            ->limit(15)
            ->get();

        // Выполненные сегодня задачи
        $remainingLimit = max(0, 15 - $overdueTasks->count());
        $completedTasks = collect();

        if ($remainingLimit > 0) {
            $completedTasks = Task::with(['creator:id,full_name', 'dealership:id,name', 'assignments.user:id,full_name', 'responses.user:id,full_name'])
                ->whereNull('archived_at')
                ->when($dealershipId, fn ($q) => $q->where('dealership_id', $dealershipId))
                ->completed($todayStart, $todayEnd)
                ->orderByDesc('updated_at')
                ->limit($remainingLimit)
                ->get();
        }

        return $overdueTasks->concat($completedTasks)
            ->map(fn ($task) => TaskResource::make($task)->resolve());
    }

    /**
     * Получает количество пользователей.
     */
    protected function getUserCount(?int $dealershipId): int
    {
        return User::query()
            ->when($dealershipId, fn ($q) => $q->where('dealership_id', $dealershipId))
            ->count();
    }

    /**
     * Получает список просроченных задач.
     */
    protected function getOverdueTasksList(?int $dealershipId): Collection
    {
        return Task::with(['creator:id,full_name', 'dealership:id,name', 'assignments.user:id,full_name', 'responses.user:id,full_name'])
            ->overdue()
            ->when($dealershipId, fn ($q) => $q->where('dealership_id', $dealershipId))
            ->orderBy('deadline')
            ->limit(10)
            ->get()
            ->map(fn ($task) => TaskResource::make($task)->resolve());
    }

    /**
     * Получает количество задач на проверке.
     */
    protected function getPendingReviewCount(?int $dealershipId): int
    {
        return Task::query()
            ->whereHas('responses', fn ($q) => $q->where('status', 'pending_review'))
            ->when($dealershipId, fn ($q) => $q->where('dealership_id', $dealershipId))
            ->whereNull('archived_at')
            ->count();
    }

    /**
     * Получает список задач на проверке.
     */
    protected function getPendingReviewTasks(?int $dealershipId, int $limit = 5): Collection
    {
        return Task::with([
            'creator:id,full_name',
            'dealership:id,name',
            'assignments.user:id,full_name',
            'responses' => fn ($q) => $q->where('status', 'pending_review')->with(['user:id,full_name', 'proofs']),
        ])
            ->whereHas('responses', fn ($q) => $q->where('status', 'pending_review'))
            ->when($dealershipId, fn ($q) => $q->where('dealership_id', $dealershipId))
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get()
            ->map(fn ($task) => TaskResource::make($task)->resolve());
    }

    /**
     * Получает статистику генераторов задач.
     *
     * @return array{total: int, active: int, generated_today: int}
     */
    protected function getGeneratorStats(?int $dealershipId): array
    {
        // Оптимизированный запрос с условными агрегатами
        $stats = TaskGenerator::query()
            ->when($dealershipId, fn ($q) => $q->where('dealership_id', $dealershipId))
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN is_active = true THEN 1 ELSE 0 END) as active
            ')
            ->first();

        // Подсчёт сгенерированных задач за сегодня
        $generatedToday = Task::query()
            ->whereNotNull('generator_id')
            ->whereBetween('created_at', [$this->todayBoundaries['start'], $this->todayBoundaries['end']])
            ->when($dealershipId, fn ($q) => $q->where('dealership_id', $dealershipId))
            ->count();

        return [
            'total' => (int) ($stats->total ?? 0),
            'active' => (int) ($stats->active ?? 0),
            'generated_today' => $generatedToday,
        ];
    }
}
