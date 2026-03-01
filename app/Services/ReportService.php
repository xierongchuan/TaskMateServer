<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\TimeHelper;
use App\Models\Shift;
use App\Models\Task;
use App\Models\TaskResponse;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class ReportService
{
    public function __construct(
        private readonly EmployeeStatsService $employeeStatsService,
    ) {}

    /**
     * Генерирует полный отчёт за указанный период для дилерства.
     *
     * @param  int|null  $dealershipId  ID дилерства или null для всех
     * @param  Carbon  $from  Начало периода (UTC, начало дня)
     * @param  Carbon  $to  Конец периода (UTC, конец дня)
     * @param  string  $dateFrom  Исходный параметр date_from из запроса
     * @param  string  $dateTo  Исходный параметр date_to из запроса
     * @return array<string, mixed>
     */
    public function generateReport(
        ?int $dealershipId,
        Carbon $from,
        Carbon $to,
        string $dateFrom,
        string $dateTo,
    ): array {
        $nowUtc = TimeHelper::nowUtc();

        $summary = $this->getSummaryStatistics($dealershipId, $from, $to, $nowUtc);
        $statusCounts = $summary['_status_counts'];
        $lateShifts = $summary['late_shifts'];
        $postponedTasks = $summary['postponed_tasks'];
        $overdueTasks = $summary['overdue_tasks'];

        // Загружаем сотрудников один раз — используется и в performance, и в top_issues
        $employeesQuery = \App\Models\User::query();
        if ($dealershipId) {
            $employeesQuery->where('dealership_id', $dealershipId);
        }
        $employees = $employeesQuery->get();

        $employeesPerformance = $this->getEmployeesPerformance($employees, $from, $to);

        $dailyStats = $this->getDailyStats($dealershipId, $from, $to, $nowUtc);

        $topIssues = $this->getTopIssues(
            dealershipId: $dealershipId,
            from: $from,
            to: $to,
            nowUtc: $nowUtc,
            overdueTasks: $overdueTasks,
            lateShifts: $lateShifts,
            postponedTasks: $postponedTasks,
            pendingReviewCount: $statusCounts['pending_review'] ?? 0,
            employeesPerformance: $employeesPerformance,
        );

        // Убираем внутренний служебный ключ перед отдачей
        unset($summary['_status_counts']);

        return [
            'period' => $from->format('Y-m-d').' - '.$to->format('Y-m-d'),
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'summary' => $summary,
            'tasks_by_status' => $this->buildTasksByStatus($statusCounts, $summary['total_tasks']),
            'employees_performance' => $employeesPerformance,
            'daily_stats' => $dailyStats,
            'top_issues' => $topIssues,
        ];
    }

    /**
     * Общая статистика за период.
     *
     * Возвращает ассоциативный массив со статистикой плюс служебный ключ
     * `_status_counts` (массив статусов), который используется в вызывающем
     * коде для построения tasks_by_status и top_issues, затем удаляется
     * перед отдачей клиенту.
     *
     * @return array<string, mixed>
     */
    public function getSummaryStatistics(?int $dealershipId, Carbon $from, Carbon $to, ?Carbon $nowUtc = null): array
    {
        $nowUtc ??= TimeHelper::nowUtc();

        // Всего задач в периоде
        $totalTasksQuery = Task::whereBetween('created_at', [$from, $to]);
        $this->scopeByDealership($totalTasksQuery, $dealershipId);
        $totalTasks = $totalTasksQuery->count();

        // Переносы
        $postponedTasksQuery = Task::whereBetween('created_at', [$from, $to])
            ->where('postpone_count', '>', 0);
        $this->scopeByDealership($postponedTasksQuery, $dealershipId);
        $postponedTasks = $postponedTasksQuery->count();

        // Смены
        $totalShiftsQuery = Shift::whereBetween('shift_start', [$from, $to]);
        $this->scopeByDealership($totalShiftsQuery, $dealershipId);
        $totalShifts = $totalShiftsQuery->count();

        $lateShiftsQuery = Shift::whereBetween('shift_start', [$from, $to])
            ->where('late_minutes', '>', 0);
        $this->scopeByDealership($lateShiftsQuery, $dealershipId);
        $lateShifts = $lateShiftsQuery->count();

        // Считаем статусы по каждой задаче индивидуально
        // Используем взаимоисключающую логику как в Task::getStatusAttribute()
        $statusCounts = [
            'completed' => 0,
            'completed_late' => 0,
            'pending_review' => 0,
            'acknowledged' => 0,
            'overdue' => 0,
            'pending' => 0,
        ];

        $tasksQuery = Task::with(['responses', 'assignments'])->whereBetween('created_at', [$from, $to]);
        $this->scopeByDealership($tasksQuery, $dealershipId);
        $tasksQuery->chunk(500, function ($tasks) use (&$statusCounts): void {
            foreach ($tasks as $task) {
                $status = $task->status;
                if (isset($statusCounts[$status])) {
                    $statusCounts[$status]++;
                }
            }
        });

        // Суммарные completed (включая с опозданием) и overdue для summary
        $completedTasks = $statusCounts['completed'] + $statusCounts['completed_late'];
        $overdueTasks = $statusCounts['overdue'];

        return [
            'total_tasks' => $totalTasks,
            'completed_tasks' => $completedTasks,
            'overdue_tasks' => $overdueTasks,
            'postponed_tasks' => $postponedTasks,
            'total_shifts' => $totalShifts,
            'late_shifts' => $lateShifts,
            // Служебный ключ — удаляется в generateReport() перед отдачей клиенту
            '_status_counts' => $statusCounts,
        ];
    }

    /**
     * Производительность сотрудников с использованием batch-подхода.
     *
     * @param  Collection  $employees  Коллекция объектов User
     * @return Collection<int, array<string, mixed>>
     */
    public function getEmployeesPerformance(Collection $employees, Carbon $from, Carbon $to): Collection
    {
        return $this->employeeStatsService
            ->getBatchStats($employees, $from, $to)
            ->filter(fn ($stats) => $stats['has_history'])
            ->sortByDesc('performance_score')
            ->values();
    }

    /**
     * Ежедневная статистика — три агрегирующих запроса вместо N запросов в цикле по дням.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getDailyStats(?int $dealershipId, Carbon $from, Carbon $to, ?Carbon $nowUtc = null): array
    {
        $nowUtc ??= TimeHelper::nowUtc();

        // Задачи, выполненные в каждый день периода (по времени ответа).
        // Считаем DISTINCT task_id, чтобы повторить логику whereHas: одна задача — один счёт.
        $completedByDayQuery = TaskResponse::query()
            ->join('tasks', 'tasks.id', '=', 'task_responses.task_id')
            ->where('task_responses.status', 'completed')
            ->whereBetween('task_responses.responded_at', [$from, $to])
            ->whereNull('tasks.deleted_at')
            ->selectRaw('DATE(task_responses.responded_at) AS day, COUNT(DISTINCT task_responses.task_id) AS cnt')
            ->groupBy('day');

        if ($dealershipId) {
            $completedByDayQuery->where('tasks.dealership_id', $dealershipId);
        }

        /** @var Collection<string, int> $completedByDay */
        $completedByDay = $completedByDayQuery->pluck('cnt', 'day');

        // Задачи, просроченные в каждый день периода (дедлайн попал в день, уже прошёл, задача активна и не выполнена).
        $overdueByDayQuery = Task::whereBetween('deadline', [$from, $to])
            ->where('deadline', '<', $nowUtc)
            ->where('is_active', true)
            ->whereDoesntHave('responses', function ($q): void {
                $q->where('status', 'completed');
            })
            ->selectRaw('DATE(deadline) AS day, COUNT(*) AS cnt')
            ->groupBy('day');

        $this->scopeByDealership($overdueByDayQuery, $dealershipId);

        /** @var Collection<string, int> $overdueByDay */
        $overdueByDay = $overdueByDayQuery->pluck('cnt', 'day');

        // Опоздания на смены в каждый день периода.
        $lateShiftsByDayQuery = Shift::whereBetween('shift_start', [$from, $to])
            ->where('late_minutes', '>', 0)
            ->selectRaw('DATE(shift_start) AS day, COUNT(*) AS cnt')
            ->groupBy('day');

        $this->scopeByDealership($lateShiftsByDayQuery, $dealershipId);

        /** @var Collection<string, int> $lateShiftsByDay */
        $lateShiftsByDay = $lateShiftsByDayQuery->pluck('cnt', 'day');

        // Собираем dailyStats из хэш-мап в PHP, итерируя по дням периода.
        $dailyStats = [];
        $current = $from->copy();
        while ($current <= $to) {
            $day = $current->format('Y-m-d');

            $dailyStats[] = [
                'date' => $day,
                'completed' => (int) ($completedByDay[$day] ?? 0),
                'overdue' => (int) ($overdueByDay[$day] ?? 0),
                'late_shifts' => (int) ($lateShiftsByDay[$day] ?? 0),
            ];

            $current->addDay();
        }

        return $dailyStats;
    }

    /**
     * Топ проблем — отсортированных по убыванию количества.
     *
     * @param  Collection<int, array<string, mixed>>  $employeesPerformance
     * @return array<int, array<string, mixed>>
     */
    public function getTopIssues(
        ?int $dealershipId,
        Carbon $from,
        Carbon $to,
        Carbon $nowUtc,
        int $overdueTasks,
        int $lateShifts,
        int $postponedTasks,
        int $pendingReviewCount,
        Collection $employeesPerformance,
    ): array {
        // Сотрудники с низким рейтингом (score < 70)
        $lowPerformersCount = $employeesPerformance->filter(fn ($e) => $e['performance_score'] < 70)->count();

        // Долго невыполненные задачи (pending > 3 дней)
        $stalePendingQuery = Task::where('is_active', true)
            ->whereBetween('created_at', [$from, $to])
            ->where('created_at', '<', $nowUtc->copy()->subDays(3))
            ->whereDoesntHave('responses', fn ($q) => $q->whereIn('status', ['completed', 'pending_review']));
        $this->scopeByDealership($stalePendingQuery, $dealershipId);
        $stalePendingCount = $stalePendingQuery->count();

        // Неявки — запланированные смены без фактического начала
        $missedShiftsQuery = Shift::whereBetween('scheduled_start', [$from, $to])
            ->whereNull('shift_start')
            ->where('scheduled_start', '<', $nowUtc);
        $this->scopeByDealership($missedShiftsQuery, $dealershipId);
        $missedShiftsCount = $missedShiftsQuery->count();

        $topIssues = [];

        if ($overdueTasks > 0) {
            $topIssues[] = [
                'issue_type' => 'overdue_tasks',
                'count' => $overdueTasks,
                'description' => 'Просроченные задачи',
            ];
        }
        if ($lateShifts > 0) {
            $topIssues[] = [
                'issue_type' => 'late_shifts',
                'count' => $lateShifts,
                'description' => 'Опоздания на смены',
            ];
        }
        if ($postponedTasks > 0) {
            $topIssues[] = [
                'issue_type' => 'frequent_postponements',
                'count' => $postponedTasks,
                'description' => 'Частые переносы задач',
            ];
        }
        if ($pendingReviewCount > 0) {
            $topIssues[] = [
                'issue_type' => 'pending_review_tasks',
                'count' => $pendingReviewCount,
                'description' => 'Задачи на проверке',
            ];
        }
        if ($lowPerformersCount > 0) {
            $topIssues[] = [
                'issue_type' => 'low_performers',
                'count' => $lowPerformersCount,
                'description' => 'Сотрудники с низким рейтингом',
            ];
        }
        if ($stalePendingCount > 0) {
            $topIssues[] = [
                'issue_type' => 'stale_pending_tasks',
                'count' => $stalePendingCount,
                'description' => 'Долго невыполненные задачи',
            ];
        }
        if ($missedShiftsCount > 0) {
            $topIssues[] = [
                'issue_type' => 'missed_shifts',
                'count' => $missedShiftsCount,
                'description' => 'Неявки на смены',
            ];
        }

        usort($topIssues, fn ($a, $b) => $b['count'] <=> $a['count']);

        return $topIssues;
    }

    /**
     * Строит массив tasks_by_status с процентами.
     *
     * @param  array<string, int>  $statusCounts
     * @return array<int, array<string, mixed>>
     */
    private function buildTasksByStatus(array $statusCounts, int $totalTasks): array
    {
        $tasksByStatus = [];
        foreach ($statusCounts as $status => $count) {
            $tasksByStatus[] = [
                'status' => $status,
                'count' => $count,
                'percentage' => $totalTasks > 0 ? round(($count / $totalTasks) * 100, 1) : 0,
            ];
        }

        return $tasksByStatus;
    }

    /**
     * Применяет фильтр по автосалону к запросу.
     */
    private function scopeByDealership(mixed $query, ?int $dealershipId): void
    {
        if ($dealershipId) {
            $query->where('dealership_id', $dealershipId);
        }
    }
}
