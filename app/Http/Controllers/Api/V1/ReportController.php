<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\TimeHelper;
use App\Http\Controllers\Controller;
use App\Models\Shift;
use App\Models\Task;
use App\Models\TaskResponse;
use App\Models\User;
use App\Services\EmployeeStatsService;
use App\Traits\HasDealershipAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    use HasDealershipAccess;

    public function __construct(
        private readonly EmployeeStatsService $employeeStatsService,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');

        if (! $dateFrom || ! $dateTo) {
            return response()->json(['message' => 'Параметры date_from и date_to обязательны'], 400);
        }

        // Конвертируем даты в UTC для запросов к БД
        $from = TimeHelper::startOfDayUtc($dateFrom);
        $to = TimeHelper::endOfDayUtc($dateTo);
        $nowUtc = TimeHelper::nowUtc();

        $dealershipResult = $this->resolveDealershipFilter($request, $user);
        if ($dealershipResult instanceof JsonResponse) {
            return $dealershipResult;
        }
        $dealershipId = $dealershipResult;

        // === SUMMARY STATISTICS ===

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

        // === ПОДСЧЁТ СТАТУСОВ БЕЗ ДВОЙНОГО СЧЁТА ===
        // Используем взаимоисключающую логику как в Task::getStatusAttribute()

        // Считаем статусы по каждой задаче индивидуально
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
        $tasksQuery->chunk(500, function ($tasks) use (&$statusCounts) {
            foreach ($tasks as $task) {
                $status = $task->status;
                if (isset($statusCounts[$status])) {
                    $statusCounts[$status]++;
                }
            }
        });

        // Формируем массив для API (сумма должна равняться totalTasks)
        $tasksByStatus = [];
        foreach ($statusCounts as $status => $count) {
            $tasksByStatus[] = [
                'status' => $status,
                'count' => $count,
                'percentage' => $totalTasks > 0 ? round(($count / $totalTasks) * 100, 1) : 0,
            ];
        }

        // Суммарные completed (включая с опозданием) и overdue для summary
        $completedTasks = $statusCounts['completed'] + $statusCounts['completed_late'];
        $overdueTasks = $statusCounts['overdue'];

        // === ПРОИЗВОДИТЕЛЬНОСТЬ СОТРУДНИКОВ ===
        $employeesQuery = User::query();
        if ($dealershipId) {
            $employeesQuery->where('dealership_id', $dealershipId);
        }
        $employees = $employeesQuery->get();

        $employeesPerformance = $employees->map(
            fn ($employee) => $this->employeeStatsService->getStats($employee, $from, $to)
        )->filter(fn ($stats) => $stats['has_history'])->sortByDesc('performance_score')->values();

        // === ЕЖЕДНЕВНАЯ СТАТИСТИКА ===
        // Три агрегирующих запроса вместо 3×N запросов в цикле по дням.

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

        /** @var \Illuminate\Support\Collection<string, int> $completedByDay */
        $completedByDay = $completedByDayQuery->pluck('cnt', 'day');

        // Задачи, просроченные в каждый день периода (дедлайн попал в день, уже прошёл, задача активна и не выполнена).
        $overdueByDayQuery = Task::whereBetween('deadline', [$from, $to])
            ->where('deadline', '<', $nowUtc)
            ->where('is_active', true)
            ->whereDoesntHave('responses', function ($q) {
                $q->where('status', 'completed');
            })
            ->selectRaw('DATE(deadline) AS day, COUNT(*) AS cnt')
            ->groupBy('day');

        $this->scopeByDealership($overdueByDayQuery, $dealershipId);

        /** @var \Illuminate\Support\Collection<string, int> $overdueByDay */
        $overdueByDay = $overdueByDayQuery->pluck('cnt', 'day');

        // Опоздания на смены в каждый день периода.
        $lateShiftsByDayQuery = Shift::whereBetween('shift_start', [$from, $to])
            ->where('late_minutes', '>', 0)
            ->selectRaw('DATE(shift_start) AS day, COUNT(*) AS cnt')
            ->groupBy('day');

        $this->scopeByDealership($lateShiftsByDayQuery, $dealershipId);

        /** @var \Illuminate\Support\Collection<string, int> $lateShiftsByDay */
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

        // === ТОП ПРОБЛЕМ ===

        // Задачи на проверке
        $pendingReviewCount = $statusCounts['pending_review'] ?? 0;

        // Сотрудники с низким рейтингом (score < 70)
        $lowPerformersCount = $employeesPerformance->filter(fn ($e) => $e['performance_score'] < 70)->count();

        // Долго невыполненные задачи (pending > 3 дней)
        $stalePendingQuery = Task::where('is_active', true)
            ->whereBetween('created_at', [$from, $to])
            ->where('created_at', '<', $nowUtc->copy()->subDays(3))
            ->whereDoesntHave('responses', fn ($q) => $q->whereIn('status', ['completed', 'pending_review']));
        $this->scopeByDealership($stalePendingQuery, $dealershipId);
        $stalePendingCount = $stalePendingQuery->count();

        // Неявки - запланированные смены без фактического начала
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

        return response()->json([
            'period' => $from->format('Y-m-d').' - '.$to->format('Y-m-d'),
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'summary' => [
                'total_tasks' => $totalTasks,
                'completed_tasks' => $completedTasks,
                'overdue_tasks' => $overdueTasks,
                'postponed_tasks' => $postponedTasks,
                'total_shifts' => $totalShifts,
                'late_shifts' => $lateShifts,
            ],
            'tasks_by_status' => $tasksByStatus,
            'employees_performance' => $employeesPerformance,
            'daily_stats' => $dailyStats,
            'top_issues' => $topIssues,
        ]);
    }

    /**
     * Возвращает детали проблемы по типу.
     */
    public function issueDetails(Request $request, string $issueType)
    {
        $user = $request->user();
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');

        if (! $dateFrom || ! $dateTo) {
            return response()->json(['message' => 'Параметры date_from и date_to обязательны'], 400);
        }

        $from = TimeHelper::startOfDayUtc($dateFrom);
        $to = TimeHelper::endOfDayUtc($dateTo);
        $nowUtc = TimeHelper::nowUtc();

        $dealershipResult = $this->resolveDealershipFilter($request, $user);
        if ($dealershipResult instanceof JsonResponse) {
            return $dealershipResult;
        }
        $dealershipId = $dealershipResult;

        $items = [];

        switch ($issueType) {
            case 'overdue_tasks':
                $query = Task::with(['creator', 'dealership'])
                    ->whereBetween('created_at', [$from, $to])
                    ->where('is_active', true)
                    ->whereNotNull('deadline')
                    ->where('deadline', '<', $nowUtc)
                    ->whereDoesntHave('responses', fn ($q) => $q->where('status', 'completed'));
                $this->scopeByDealership($query, $dealershipId);
                $items = $query->orderBy('deadline')->get()->map(fn ($task) => [
                    'id' => $task->id,
                    'title' => $task->title,
                    'subtitle' => $task->dealership?->name,
                    'date' => $task->deadline?->toIso8601ZuluString(),
                    'type' => 'task',
                    'dealership_id' => $task->dealership_id,
                ]);
                break;

            case 'late_shifts':
                $query = Shift::with(['user', 'dealership'])
                    ->whereBetween('shift_start', [$from, $to])
                    ->where('late_minutes', '>', 0);
                $this->scopeByDealership($query, $dealershipId);
                $items = $query->orderByDesc('late_minutes')->get()->map(fn ($shift) => [
                    'id' => $shift->id,
                    'title' => $shift->user?->full_name ?? 'Неизвестный',
                    'subtitle' => "Опоздание: {$shift->late_minutes} мин",
                    'date' => $shift->shift_start?->toIso8601ZuluString(),
                    'type' => 'shift',
                    'user_id' => $shift->user_id,
                    'dealership_id' => $shift->dealership_id,
                ]);
                break;

            case 'frequent_postponements':
                $query = Task::with(['creator', 'dealership'])
                    ->whereBetween('created_at', [$from, $to])
                    ->where('postpone_count', '>', 0);
                $this->scopeByDealership($query, $dealershipId);
                $items = $query->orderByDesc('postpone_count')->get()->map(fn ($task) => [
                    'id' => $task->id,
                    'title' => $task->title,
                    'subtitle' => "Переносов: {$task->postpone_count}",
                    'date' => $task->created_at?->toIso8601ZuluString(),
                    'type' => 'task',
                    'dealership_id' => $task->dealership_id,
                ]);
                break;

            case 'pending_review_tasks':
                $query = Task::with(['creator', 'dealership'])
                    ->whereBetween('created_at', [$from, $to])
                    ->whereHas('responses', fn ($q) => $q->where('status', 'pending_review'));
                $this->scopeByDealership($query, $dealershipId);
                $items = $query->orderBy('created_at')->get()->map(fn ($task) => [
                    'id' => $task->id,
                    'title' => $task->title,
                    'subtitle' => $task->dealership?->name,
                    'date' => $task->created_at?->toIso8601ZuluString(),
                    'type' => 'task',
                    'dealership_id' => $task->dealership_id,
                ]);
                break;

            case 'low_performers':
                $employeesQuery = User::where('role', 'employee');
                if ($dealershipId) {
                    $employeesQuery->where('dealership_id', $dealershipId);
                }

                $items = $employeesQuery->get()->map(function ($employee) use ($from, $to) {
                    $stats = $this->employeeStatsService->getStats($employee, $from, $to);

                    return [
                        'id' => $employee->id,
                        'title' => $employee->full_name,
                        'subtitle' => "Рейтинг: {$stats['performance_score']}/100",
                        'score' => $stats['performance_score'],
                        'type' => 'user',
                        'dealership_id' => $employee->dealership_id,
                    ];
                })->filter(fn ($e) => $e['score'] < 70)->sortBy('score')->values();
                break;

            case 'stale_pending_tasks':
                $query = Task::with(['creator', 'dealership'])
                    ->where('is_active', true)
                    ->whereBetween('created_at', [$from, $to])
                    ->where('created_at', '<', $nowUtc->copy()->subDays(3))
                    ->whereDoesntHave('responses', fn ($q) => $q->whereIn('status', ['completed', 'pending_review']));
                $this->scopeByDealership($query, $dealershipId);
                $items = $query->orderBy('created_at')->get()->map(fn ($task) => [
                    'id' => $task->id,
                    'title' => $task->title,
                    'subtitle' => $task->dealership?->name,
                    'date' => $task->created_at?->toIso8601ZuluString(),
                    'type' => 'task',
                    'dealership_id' => $task->dealership_id,
                ]);
                break;

            case 'missed_shifts':
                $query = Shift::with(['user', 'dealership'])
                    ->whereBetween('scheduled_start', [$from, $to])
                    ->whereNull('shift_start')
                    ->where('scheduled_start', '<', $nowUtc);
                $this->scopeByDealership($query, $dealershipId);
                $items = $query->orderBy('scheduled_start')->get()->map(fn ($shift) => [
                    'id' => $shift->id,
                    'title' => $shift->user?->full_name ?? 'Неизвестный',
                    'subtitle' => $shift->dealership?->name,
                    'date' => $shift->scheduled_start?->toIso8601ZuluString(),
                    'type' => 'shift',
                    'user_id' => $shift->user_id,
                    'dealership_id' => $shift->dealership_id,
                ]);
                break;

            default:
                return response()->json(['message' => 'Неизвестный тип проблемы'], 400);
        }

        return response()->json([
            'issue_type' => $issueType,
            'items' => $items,
        ]);
    }

    /**
     * Определить dealership_id для фильтрации отчётов.
     *
     * @return int|null|JsonResponse ID дилерства или ответ с ошибкой доступа
     */
    private function resolveDealershipFilter(Request $request, User $user): int|null|JsonResponse
    {
        if ($user->role === 'manager' && $user->dealership_id) {
            return $user->dealership_id;
        }

        if ($request->filled('dealership_id')) {
            $requestedDealershipId = $request->integer('dealership_id');
            if ($accessError = $this->validateDealershipAccess($user, $requestedDealershipId)) {
                return $accessError;
            }

            return $requestedDealershipId;
        }

        return null;
    }

    /**
     * Применить фильтр по автосалону к запросу.
     */
    private function scopeByDealership($query, ?int $dealershipId): void
    {
        if ($dealershipId) {
            $query->where('dealership_id', $dealershipId);
        }
    }
}
