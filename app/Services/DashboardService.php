<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ShiftStatus;
use App\Helpers\TimeHelper;
use App\Models\Shift;
use App\Models\Task;
use App\Models\TaskGenerator;
use App\Models\AutoDealership;
use App\Models\CalendarDay;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
     * @param int|null $dealershipId ID автосалона для фильтрации
     * @return array<string, mixed>
     */
    public function getDashboardData(?int $dealershipId = null): array
    {
        // Определяем границы дня по timezone автосалона
        $timezone = null;
        if ($dealershipId) {
            $timezone = AutoDealership::where('id', $dealershipId)->value('timezone');
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

        // Получаем активные смены с eager loading
        $activeShifts = $this->getActiveShifts($dealershipId);

        return [
            'total_users' => $this->getUserCount($dealershipId),
            'active_users' => $this->getUserCount($dealershipId),
            'total_tasks' => $taskStats['total_active'],
            'active_tasks' => $taskStats['total_active'],
            'completed_tasks' => $taskStats['completed_today'],
            'overdue_tasks' => $taskStats['overdue'],
            'overdue_tasks_list' => $this->getOverdueTasksList($dealershipId),
            'pending_review_count' => $this->getPendingReviewCount($dealershipId),
            'pending_review_tasks' => $this->getPendingReviewTasks($dealershipId, 5),
            'open_shifts' => count($activeShifts),
            'late_shifts_today' => $this->getLateShiftsCount($dealershipId),
            'active_shifts' => $activeShifts,
            'dealership_shift_stats' => $this->getDealershipShiftStats($dealershipId),
            'today_tasks_list' => $this->getTodayTasksList($dealershipId),
            'active_generators' => $this->getGeneratorStats($dealershipId)['active'],
            'total_generators' => $this->getGeneratorStats($dealershipId)['total'],
            'tasks_generated_today' => $this->getGeneratorStats($dealershipId)['generated_today'],
            'timestamp' => Carbon::now()->toIso8601String(),
        ];
    }

    /**
     * Получает статистику задач оптимизированным запросом.
     *
     * @param int|null $dealershipId
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
        $overdueCount = Task::query()
            ->where('is_active', true)
            ->where('deadline', '<', $nowUtc)
            ->whereDoesntHave('responses', fn ($q) => $q->where('status', 'completed'))
            ->when($dealershipId, fn ($q) => $q->where('dealership_id', $dealershipId))
            ->count();

        // Подсчёт завершённых сегодня (используем ту же логику что и TaskFilterService)
        $completedToday = Task::query()
            ->whereNull('archived_at')
            ->when($dealershipId, fn ($q) => $q->where('dealership_id', $dealershipId))
            ->where(function ($q) use ($todayStart, $todayEnd) {
                // Индивидуальные задачи: хотя бы один completed response за сегодня
                $q->where(function ($individual) use ($todayStart, $todayEnd) {
                    $individual->where('task_type', 'individual')
                        ->whereHas('responses', fn ($r) => $r->where('status', 'completed')
                            ->whereBetween('responded_at', [$todayStart, $todayEnd]));
                })
                // Групповые задачи: ВСЕ назначенные выполнили И хотя бы один за сегодня
                ->orWhere(function ($group) use ($todayStart, $todayEnd) {
                    $group->where('task_type', 'group')
                        ->whereHas('assignments')
                        ->whereHas('responses', fn ($r) => $r->where('status', 'completed')
                            ->whereBetween('responded_at', [$todayStart, $todayEnd]))
                        ->whereRaw('(
                            SELECT COUNT(DISTINCT ta.user_id)
                            FROM task_assignments ta
                            WHERE ta.task_id = tasks.id AND ta.deleted_at IS NULL
                        ) > 0')
                        ->whereRaw('(
                            SELECT COUNT(DISTINCT ta.user_id)
                            FROM task_assignments ta
                            WHERE ta.task_id = tasks.id AND ta.deleted_at IS NULL
                        ) = (
                            SELECT COUNT(DISTINCT tr.user_id)
                            FROM task_responses tr
                            WHERE tr.task_id = tasks.id AND tr.status = ?
                        )', ['completed']);
                });
            })
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
     *
     * @param int|null $dealershipId
     * @return Collection
     */
    protected function getActiveShifts(?int $dealershipId): Collection
    {
        return Shift::with(['user:id,full_name', 'dealership:id,name'])
            ->where('status', ShiftStatus::OPEN->value)
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
     *
     * @param int|null $dealershipId
     * @return Collection
     */
    protected function getDealershipShiftStats(?int $dealershipId): Collection
    {
        $settingsService = app(SettingsService::class);

        return AutoDealership::query()
            ->when($dealershipId, fn ($q) => $q->where('id', $dealershipId))
            ->get()
            ->map(function ($dealership) use ($settingsService) {
                $totalEmployees = User::where('dealership_id', $dealership->id)
                    ->where('role', 'employee')
                    ->count();

                $onShiftCount = Shift::where('dealership_id', $dealership->id)
                    ->where('status', ShiftStatus::OPEN->value)
                    ->whereNull('shift_end')
                    ->count();

                $schedules = \App\Models\ShiftSchedule::where('dealership_id', $dealership->id)
                    ->where('is_active', true)
                    ->orderBy('sort_order')
                    ->get(['id', 'name', 'start_time', 'end_time']);

                // Определяем текущее или ближайшее расписание по локальному времени автосалона
                $currentOrNextSchedule = null;
                $isCurrentSchedule = false;
                if ($schedules->isNotEmpty()) {
                    $timezone = $dealership->timezone ?? '+05:00';
                    $localNow = Carbon::now($timezone)->format('H:i');

                    // Сначала ищем расписание, в которое попадает текущее время
                    $currentOrNextSchedule = $schedules->first(function ($s) use ($localNow) {
                        return $s->containsTime($localNow);
                    });

                    if ($currentOrNextSchedule) {
                        $isCurrentSchedule = true;
                    } else {
                        // Ищем первое расписание, start_time которого ещё не наступил
                        $currentOrNextSchedule = $schedules->first(function ($s) use ($localNow) {
                            $start = substr($s->start_time, 0, 5);
                            return $start > $localNow;
                        });

                        // Если все смены уже прошли — берём первую (на завтра)
                        $currentOrNextSchedule = $currentOrNextSchedule ?? $schedules->first();
                    }
                }

                return [
                    'dealership_id' => $dealership->id,
                    'dealership_name' => $dealership->name,
                    'total_employees' => $totalEmployees,
                    'on_shift_count' => $onShiftCount,
                    'shift_schedules' => $schedules->toArray(),
                    'current_or_next_schedule' => $currentOrNextSchedule ? [
                        'id' => $currentOrNextSchedule->id,
                        'name' => $currentOrNextSchedule->name,
                        'start_time' => $currentOrNextSchedule->start_time,
                        'end_time' => $currentOrNextSchedule->end_time,
                        'is_current' => $isCurrentSchedule,
                    ] : null,
                    'is_today_holiday' => CalendarDay::isHoliday(TimeHelper::nowUtc(), $dealership->id),
                ];
            });
    }

    /**
     * Получает количество опоздавших смен сегодня.
     *
     * @param int|null $dealershipId
     * @return int
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
     * Получает последние задачи.
     *
     * @param int|null $dealershipId
     * @return Collection
     */
    protected function getRecentTasks(?int $dealershipId): Collection
    {
        return Task::with('creator:id,full_name')
            ->when($dealershipId, fn ($q) => $q->where('dealership_id', $dealershipId))
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(function ($task) {
                $data = $task->toApiArray();
                return [
                    'id' => $data['id'],
                    'title' => $data['title'],
                    'status' => $data['status'],
                    'created_at' => $data['created_at'],
                    'creator' => $task->creator ? [
                        'full_name' => $task->creator->full_name,
                    ] : null,
                ];
            });
    }

    /**
     * Получает список задач за сегодня: просроченные первыми, затем выполненные.
     *
     * "Сегодня" определяется по timezone автосалона (todayBoundaries).
     *
     * @param int|null $dealershipId
     * @return Collection
     */
    protected function getTodayTasksList(?int $dealershipId): Collection
    {
        $todayStart = $this->todayBoundaries['start'];
        $todayEnd = $this->todayBoundaries['end'];
        $nowUtc = TimeHelper::nowUtc();

        // Просроченные задачи (overdue)
        $overdueTasks = Task::with(['creator:id,full_name', 'dealership:id,name', 'assignments.user:id,full_name', 'responses.user:id,full_name'])
            ->where('is_active', true)
            ->where('deadline', '<', $nowUtc)
            ->whereDoesntHave('responses', fn ($q) => $q->where('status', 'completed'))
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
                ->where(function ($q) use ($todayStart, $todayEnd) {
                    $q->where(function ($individual) use ($todayStart, $todayEnd) {
                        $individual->where('task_type', 'individual')
                            ->whereHas('responses', fn ($r) => $r->where('status', 'completed')
                                ->whereBetween('responded_at', [$todayStart, $todayEnd]));
                    })
                    ->orWhere(function ($group) use ($todayStart, $todayEnd) {
                        $group->where('task_type', 'group')
                            ->whereHas('assignments')
                            ->whereHas('responses', fn ($r) => $r->where('status', 'completed')
                                ->whereBetween('responded_at', [$todayStart, $todayEnd]))
                            ->whereRaw('(
                                SELECT COUNT(DISTINCT ta.user_id)
                                FROM task_assignments ta
                                WHERE ta.task_id = tasks.id AND ta.deleted_at IS NULL
                            ) > 0')
                            ->whereRaw('(
                                SELECT COUNT(DISTINCT ta.user_id)
                                FROM task_assignments ta
                                WHERE ta.task_id = tasks.id AND ta.deleted_at IS NULL
                            ) = (
                                SELECT COUNT(DISTINCT tr.user_id)
                                FROM task_responses tr
                                WHERE tr.task_id = tasks.id AND tr.status = ?
                            )', ['completed']);
                    });
                })
                ->orderByDesc('updated_at')
                ->limit($remainingLimit)
                ->get();
        }

        return $overdueTasks->concat($completedTasks)
            ->map(fn ($task) => $task->toApiArray());
    }

    /**
     * Получает количество пользователей.
     *
     * @param int|null $dealershipId
     * @return int
     */
    protected function getUserCount(?int $dealershipId): int
    {
        return User::query()
            ->when($dealershipId, fn ($q) => $q->where('dealership_id', $dealershipId))
            ->count();
    }

    /**
     * Получает список просроченных задач.
     *
     * @param int|null $dealershipId
     * @return Collection
     */
    protected function getOverdueTasksList(?int $dealershipId): Collection
    {
        return Task::with(['creator:id,full_name', 'dealership:id,name', 'assignments.user:id,full_name', 'responses.user:id,full_name'])
            ->where('is_active', true)
            ->where('deadline', '<', TimeHelper::nowUtc())
            ->whereDoesntHave('responses', fn ($q) => $q->where('status', 'completed'))
            ->when($dealershipId, fn ($q) => $q->where('dealership_id', $dealershipId))
            ->orderBy('deadline')
            ->limit(10)
            ->get()
            ->map(fn ($task) => $task->toApiArray());
    }

    /**
     * Получает количество задач на проверке.
     *
     * @param int|null $dealershipId
     * @return int
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
     *
     * @param int|null $dealershipId
     * @param int $limit
     * @return Collection
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
            ->map(fn ($task) => $task->toApiArray());
    }

    /**
     * Получает статистику генераторов задач.
     *
     * @param int|null $dealershipId
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
