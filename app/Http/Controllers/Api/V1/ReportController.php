<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\TimeHelper;
use App\Http\Controllers\Controller;
use App\Models\Shift;
use App\Models\Task;
use App\Models\User;
use App\Traits\HasDealershipAccess;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    use HasDealershipAccess;
    public function index(Request $request)
    {
        $user = $request->user();
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');

        if (! $dateFrom || ! $dateTo) {
            return response()->json(['message' => 'Parameters date_from and date_to are required'], 400);
        }

        // Конвертируем даты в UTC для запросов к БД
        $from = TimeHelper::startOfDayUtc($dateFrom);
        $to = TimeHelper::endOfDayUtc($dateTo);
        $nowUtc = TimeHelper::nowUtc();

        // Фильтр по автосалону
        $dealershipId = null;
        if ($user->role === 'manager' && $user->dealership_id) {
            // Для менеджера - только его автосалон
            $dealershipId = $user->dealership_id;
        } elseif ($request->filled('dealership_id')) {
            // Для других ролей - проверяем доступ к выбранному автосалону
            $requestedDealershipId = $request->integer('dealership_id');
            if ($accessError = $this->validateDealershipAccess($user, $requestedDealershipId)) {
                return $accessError;
            }
            $dealershipId = $requestedDealershipId;
        }

        // Helper для применения фильтра по автосалону
        $applyTaskFilter = function ($query) use ($dealershipId) {
            if ($dealershipId) {
                $query->where('dealership_id', $dealershipId);
            }
        };

        $applyShiftFilter = function ($query) use ($dealershipId) {
            if ($dealershipId) {
                $query->where('dealership_id', $dealershipId);
            }
        };

        // === SUMMARY STATISTICS ===

        // Всего задач в периоде
        $totalTasksQuery = Task::whereBetween('created_at', [$from, $to]);
        $applyTaskFilter($totalTasksQuery);
        $totalTasks = $totalTasksQuery->count();

        // Переносы
        $postponedTasksQuery = Task::whereBetween('created_at', [$from, $to])
            ->where('postpone_count', '>', 0);
        $applyTaskFilter($postponedTasksQuery);
        $postponedTasks = $postponedTasksQuery->count();

        // Смены
        $totalShiftsQuery = Shift::whereBetween('shift_start', [$from, $to]);
        $applyShiftFilter($totalShiftsQuery);
        $totalShifts = $totalShiftsQuery->count();

        $lateShiftsQuery = Shift::whereBetween('shift_start', [$from, $to])
            ->where('late_minutes', '>', 0);
        $applyShiftFilter($lateShiftsQuery);
        $lateShifts = $lateShiftsQuery->count();

        $totalReplacementsQuery = Shift::whereBetween('shift_start', [$from, $to])->has('replacement');
        $applyShiftFilter($totalReplacementsQuery);
        $totalReplacements = $totalReplacementsQuery->count();

        // === ПОДСЧЁТ СТАТУСОВ БЕЗ ДВОЙНОГО СЧЁТА ===
        // Используем взаимоисключающую логику как в Task::getStatusAttribute()

        // Получаем все задачи периода с responses для расчёта статусов
        $tasksQuery = Task::with(['responses', 'assignments'])->whereBetween('created_at', [$from, $to]);
        $applyTaskFilter($tasksQuery);
        $allTasks = $tasksQuery->get();

        // Считаем статусы по каждой задаче индивидуально
        $statusCounts = [
            'completed' => 0,
            'completed_late' => 0,
            'pending_review' => 0,
            'acknowledged' => 0,
            'overdue' => 0,
            'pending' => 0,
        ];

        foreach ($allTasks as $task) {
            $status = $this->calculateTaskStatus($task, $nowUtc);
            if (isset($statusCounts[$status])) {
                $statusCounts[$status]++;
            }
        }

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
        $employeesQuery = User::where('role', 'employee');
        if ($dealershipId) {
            $employeesQuery->where('dealership_id', $dealershipId);
        }
        $employees = $employeesQuery->get();

        $employeesPerformance = $employees->map(function ($employee) use ($from, $to, $nowUtc, $applyTaskFilter) {
            // Задачи, назначенные этому сотруднику
            $userTasksQuery = Task::whereHas('assignedUsers', function ($q) use ($employee) {
                $q->where('user_id', $employee->id);
            })->whereBetween('created_at', [$from, $to]);

            $userTasks = (clone $userTasksQuery)->count();

            // Выполненные - есть completed response от этого пользователя
            $userCompleted = (clone $userTasksQuery)
                ->whereHas('responses', function ($q) use ($employee) {
                    $q->where('user_id', $employee->id)
                      ->where('status', 'completed');
                })
                ->count();

            // Просроченные - дедлайн прошёл, нет completed response от этого пользователя
            $userOverdue = (clone $userTasksQuery)
                ->where('is_active', true)
                ->whereNotNull('deadline')
                ->where('deadline', '<', $nowUtc)
                ->whereDoesntHave('responses', function ($q) use ($employee) {
                    $q->where('user_id', $employee->id)
                      ->where('status', 'completed');
                })
                ->count();

            // Смены за период
            $userShiftsQuery = Shift::where('user_id', $employee->id)
                ->whereBetween('shift_start', [$from, $to]);

            $userTotalShifts = (clone $userShiftsQuery)->count();

            // Опоздания на смены
            $lateShiftsQuery = (clone $userShiftsQuery)->where('late_minutes', '>', 0);
            $userLateShifts = (clone $lateShiftsQuery)->count();
            $userAvgLateMinutes = $userLateShifts > 0
                ? (int) round((float) $lateShiftsQuery->avg('late_minutes'), 0)
                : 0;

            // Процент выполнения
            $completionRate = $userTasks > 0
                ? round(($userCompleted / $userTasks) * 100, 1)
                : 0;

            // Расчёт рейтинга
            $score = 100;
            if ($userTasks > 0) {
                $score -= ($userOverdue * 5);
            }
            $score -= ($userLateShifts * 10);
            $score = max(0, min(100, $score));

            return [
                'employee_id' => $employee->id,
                'employee_name' => $employee->full_name,
                'total_tasks' => $userTasks,
                'completed_tasks' => $userCompleted,
                'completion_rate' => $completionRate,
                'overdue_tasks' => $userOverdue,
                'total_shifts' => $userTotalShifts,
                'late_shifts' => $userLateShifts,
                'avg_late_minutes' => (int) $userAvgLateMinutes,
                'performance_score' => $score,
            ];
        })->sortByDesc('performance_score')->values();

        // === ЕЖЕДНЕВНАЯ СТАТИСТИКА ===
        $dailyStats = [];
        $current = $from->copy();
        while ($current <= $to) {
            $dayStart = TimeHelper::startOfDayUtc($current);
            $dayEnd = TimeHelper::endOfDayUtc($current);

            // Задачи, выполненные в этот день (по времени response)
            $dayCompletedQuery = Task::whereHas('responses', function ($q) use ($dayStart, $dayEnd) {
                $q->where('status', 'completed')
                  ->whereBetween('responded_at', [$dayStart, $dayEnd]);
            });
            $applyTaskFilter($dayCompletedQuery);
            $dayCompleted = $dayCompletedQuery->count();

            // Задачи, просроченные в этот день (дедлайн попал в этот день и уже прошёл)
            $dayOverdueQuery = Task::whereBetween('deadline', [$dayStart, $dayEnd])
                ->where('deadline', '<', $nowUtc)
                ->where('is_active', true)
                ->whereDoesntHave('responses', function ($q) {
                    $q->where('status', 'completed');
                });
            $applyTaskFilter($dayOverdueQuery);
            $dayOverdue = $dayOverdueQuery->count();

            // Опоздания на смены в этот день
            $dayLateShiftsQuery = Shift::whereBetween('shift_start', [$dayStart, $dayEnd])
                ->where('late_minutes', '>', 0);
            $applyShiftFilter($dayLateShiftsQuery);
            $dayLateShifts = $dayLateShiftsQuery->count();

            $dailyStats[] = [
                'date' => $current->format('Y-m-d'),
                'completed' => $dayCompleted,
                'overdue' => $dayOverdue,
                'late_shifts' => $dayLateShifts,
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
        $applyTaskFilter($stalePendingQuery);
        $stalePendingCount = $stalePendingQuery->count();

        // Неявки - запланированные смены без фактического начала
        $missedShiftsQuery = Shift::whereBetween('scheduled_start', [$from, $to])
            ->whereNull('shift_start')
            ->where('scheduled_start', '<', $nowUtc);
        $applyShiftFilter($missedShiftsQuery);
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
            'period' => $from->format('Y-m-d') . ' - ' . $to->format('Y-m-d'),
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'summary' => [
                'total_tasks' => $totalTasks,
                'completed_tasks' => $completedTasks,
                'overdue_tasks' => $overdueTasks,
                'postponed_tasks' => $postponedTasks,
                'total_shifts' => $totalShifts,
                'late_shifts' => $lateShifts,
                'total_replacements' => $totalReplacements,
            ],
            'tasks_by_status' => $tasksByStatus,
            'employees_performance' => $employeesPerformance,
            'daily_stats' => $dailyStats,
            'top_issues' => $topIssues,
        ]);
    }

    /**
     * Вычисляет статус задачи (копия логики из Task::getStatusAttribute для консистентности)
     */
    private function calculateTaskStatus(Task $task, $nowUtc): string
    {
        $responses = $task->responses;
        $assignments = $task->assignments;
        $hasDeadline = $task->deadline !== null;
        $deadlinePassed = $hasDeadline && $task->deadline->lt($nowUtc);

        $isCompleted = false;
        $completedLate = false;

        if ($task->task_type === 'group') {
            $assignedUserIds = $assignments->pluck('user_id')->unique()->values()->toArray();
            $completedResponses = $responses->where('status', 'completed');
            $completedUserIds = $completedResponses->pluck('user_id')->unique()->values()->toArray();

            if (count($assignedUserIds) > 0 && count(array_diff($assignedUserIds, $completedUserIds)) === 0) {
                $isCompleted = true;

                if ($hasDeadline) {
                    foreach ($completedResponses as $response) {
                        if ($response->responded_at && $response->responded_at->gt($task->deadline)) {
                            $completedLate = true;
                            break;
                        }
                    }
                }
            }
        } else {
            $completedResponse = $responses->firstWhere('status', 'completed');
            if ($completedResponse) {
                $isCompleted = true;

                if ($hasDeadline && $completedResponse->responded_at && $completedResponse->responded_at->gt($task->deadline)) {
                    $completedLate = true;
                }
            }
        }

        if ($isCompleted) {
            return $completedLate ? 'completed_late' : 'completed';
        }

        if ($task->task_type === 'group') {
            $pendingReviewUserIds = $responses->where('status', 'pending_review')->pluck('user_id')->unique()->values()->toArray();
            if (count($pendingReviewUserIds) > 0) {
                return 'pending_review';
            }

            $acknowledgedUserIds = $responses->where('status', 'acknowledged')->pluck('user_id')->unique()->values()->toArray();
            if (count($acknowledgedUserIds) > 0) {
                return 'acknowledged';
            }
        } else {
            if ($responses->contains('status', 'pending_review')) {
                return 'pending_review';
            }

            if ($responses->contains('status', 'acknowledged')) {
                return 'acknowledged';
            }
        }

        if ($task->is_active && $deadlinePassed) {
            return 'overdue';
        }

        return 'pending';
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
            return response()->json(['message' => 'Parameters date_from and date_to are required'], 400);
        }

        $from = TimeHelper::startOfDayUtc($dateFrom);
        $to = TimeHelper::endOfDayUtc($dateTo);
        $nowUtc = TimeHelper::nowUtc();

        // Фильтр по автосалону
        $dealershipId = null;
        if ($user->role === 'manager' && $user->dealership_id) {
            // Для менеджера - только его автосалон
            $dealershipId = $user->dealership_id;
        } elseif ($request->filled('dealership_id')) {
            // Для других ролей - проверяем доступ к выбранному автосалону
            $requestedDealershipId = $request->integer('dealership_id');
            if ($accessError = $this->validateDealershipAccess($user, $requestedDealershipId)) {
                return $accessError;
            }
            $dealershipId = $requestedDealershipId;
        }

        $applyTaskFilter = function ($query) use ($dealershipId) {
            if ($dealershipId) {
                $query->where('dealership_id', $dealershipId);
            }
        };

        $applyShiftFilter = function ($query) use ($dealershipId) {
            if ($dealershipId) {
                $query->where('dealership_id', $dealershipId);
            }
        };

        $items = [];

        switch ($issueType) {
            case 'overdue_tasks':
                $query = Task::with(['creator', 'dealership'])
                    ->whereBetween('created_at', [$from, $to])
                    ->where('is_active', true)
                    ->whereNotNull('deadline')
                    ->where('deadline', '<', $nowUtc)
                    ->whereDoesntHave('responses', fn ($q) => $q->where('status', 'completed'));
                $applyTaskFilter($query);
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
                $applyShiftFilter($query);
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
                $applyTaskFilter($query);
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
                $applyTaskFilter($query);
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
                // Пересчитываем для получения списка
                $employeesQuery = User::where('role', 'employee');
                if ($dealershipId) {
                    $employeesQuery->where('dealership_id', $dealershipId);
                }
                $employees = $employeesQuery->get();

                $items = $employees->map(function ($employee) use ($from, $to, $nowUtc) {
                    $userTasksQuery = Task::whereHas('assignedUsers', fn ($q) => $q->where('user_id', $employee->id))
                        ->whereBetween('created_at', [$from, $to]);

                    $userTasks = (clone $userTasksQuery)->count();
                    $userOverdue = (clone $userTasksQuery)
                        ->where('is_active', true)
                        ->whereNotNull('deadline')
                        ->where('deadline', '<', $nowUtc)
                        ->whereDoesntHave('responses', fn ($q) => $q->where('user_id', $employee->id)->where('status', 'completed'))
                        ->count();

                    $userLateShifts = Shift::where('user_id', $employee->id)
                        ->whereBetween('shift_start', [$from, $to])
                        ->where('late_minutes', '>', 0)
                        ->count();

                    $score = 100;
                    if ($userTasks > 0) {
                        $score -= ($userOverdue * 5);
                    }
                    $score -= ($userLateShifts * 10);
                    $score = max(0, min(100, $score));

                    return [
                        'id' => $employee->id,
                        'title' => $employee->full_name,
                        'subtitle' => "Рейтинг: {$score}/100",
                        'score' => $score,
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
                $applyTaskFilter($query);
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
                $applyShiftFilter($query);
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
                return response()->json(['message' => 'Unknown issue type'], 400);
        }

        return response()->json([
            'issue_type' => $issueType,
            'items' => $items,
        ]);
    }
}
