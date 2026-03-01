<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Shift;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class EmployeeStatsService
{
    /**
     * Вычисляет подробную статистику для коллекции сотрудников за период.
     *
     * Batch-подход: все данные получаем тремя агрегирующими запросами
     * вместо 4×N запросов при поочерёдном вызове getStats().
     *
     * @param  Collection<int, User>  $employees
     * @return Collection<int, array<string, mixed>> Коллекция, индексированная по порядковому номеру
     */
    public function getBatchStats(Collection $employees, Carbon $from, Carbon $to): Collection
    {
        if ($employees->isEmpty()) {
            return collect();
        }

        $nowUtc = Carbon::now('UTC');
        $employeeIds = $employees->pluck('id')->all();

        // === Задачи ===
        // Получаем все назначения сотрудников в периоде одним запросом.
        // Нам нужны: task_id, user_id — чтобы сгруппировать задачи по сотруднику.
        $assignmentRows = TaskAssignment::whereIn('user_id', $employeeIds)
            ->whereNull('deleted_at')
            ->whereHas('task', fn ($q) => $q->whereBetween('created_at', [$from, $to]))
            ->with(['task' => fn ($q) => $q->whereBetween('created_at', [$from, $to])])
            ->get();

        // Собираем task_id-ы, относящиеся к нашим сотрудникам в периоде
        $taskIds = $assignmentRows->pluck('task_id')->unique()->all();

        // Загружаем все задачи с responses и assignments за один запрос
        $tasks = Task::whereIn('id', $taskIds)
            ->with([
                'responses',
                'assignments',
            ])
            ->get()
            ->keyBy('id');

        // Индекс: user_id → коллекция Task
        /** @var array<int, Collection<int, Task>> $tasksByUser */
        $tasksByUser = [];
        foreach ($assignmentRows as $assignment) {
            $task = $tasks->get($assignment->task_id);
            if ($task === null) {
                continue;
            }
            $tasksByUser[$assignment->user_id][] = $task;
        }

        // === Смены ===
        // Один агрегирующий запрос с GROUP BY user_id для shift_start
        $shiftStats = Shift::whereIn('user_id', $employeeIds)
            ->whereBetween('shift_start', [$from, $to])
            ->selectRaw(
                'user_id,
                COUNT(*) AS total,
                SUM(CASE WHEN late_minutes > 0 THEN 1 ELSE 0 END) AS late,
                AVG(CASE WHEN late_minutes > 0 THEN late_minutes ELSE NULL END) AS avg_late'
            )
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        // Пропущенные смены — запланированные без фактического начала
        $missedShiftStats = Shift::whereIn('user_id', $employeeIds)
            ->whereBetween('scheduled_start', [$from, $to])
            ->whereNull('shift_start')
            ->where('scheduled_start', '<', $nowUtc)
            ->selectRaw('user_id, COUNT(*) AS total')
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        // === Собираем результат для каждого сотрудника ===
        return $employees->map(function (User $employee) use (
            $tasksByUser,
            $shiftStats,
            $missedShiftStats,
            $nowUtc,
        ): array {
            $employeeTasks = collect($tasksByUser[$employee->id] ?? []);

            // Вычисляем метрики задач из уже загруженных данных (без новых запросов)
            $totalTasks = $employeeTasks->count();
            $completedOnTime = 0;
            $completedLate = 0;
            $completionTimes = [];
            $pendingReview = 0;
            $rejectedTasks = 0;
            $tasksByType = [
                'notification' => 0,
                'completion' => 0,
                'completion_with_proof' => 0,
            ];

            foreach ($employeeTasks as $task) {
                $type = $task->response_type;
                if (isset($tasksByType[$type])) {
                    $tasksByType[$type]++;
                }

                // Ищем ответ именно этого сотрудника из уже загруженной relations
                $response = $task->responses->firstWhere('user_id', $employee->id);
                if ($response === null) {
                    continue;
                }

                if ($response->status === 'completed') {
                    if ($response->responded_at) {
                        $completionTimes[] = $response->responded_at->diffInSeconds($task->created_at) / 3600.0;
                    }

                    if ($task->deadline && $response->responded_at && $response->responded_at->gt($task->deadline)) {
                        $completedLate++;
                    } else {
                        $completedOnTime++;
                    }
                } elseif ($response->status === 'pending_review') {
                    $pendingReview++;
                } elseif ($response->status === 'rejected') {
                    $rejectedTasks++;
                }
            }

            $userCompleted = $completedOnTime + $completedLate;

            // Просроченные — дедлайн прошёл, нет completed response
            $userOverdue = $employeeTasks->filter(function ($task) use ($employee, $nowUtc): bool {
                if (! $task->is_active || ! $task->deadline || ! $task->deadline->lt($nowUtc)) {
                    return false;
                }
                $response = $task->responses->firstWhere('user_id', $employee->id);

                return ! $response || $response->status !== 'completed';
            })->count();

            $avgCompletionTimeHours = count($completionTimes) > 0
                ? round(array_sum($completionTimes) / count($completionTimes), 1)
                : 0;

            $completionRate = $totalTasks > 0
                ? round(($userCompleted / $totalTasks) * 100, 1)
                : 0;

            // Смены из агрегата
            $shiftRow = $shiftStats->get($employee->id);
            $totalShifts = $shiftRow ? (int) $shiftRow->total : 0;
            $lateShifts = $shiftRow ? (int) $shiftRow->late : 0;
            $avgLateMinutes = ($shiftRow && $shiftRow->avg_late !== null)
                ? (int) round((float) $shiftRow->avg_late, 0)
                : 0;

            $missedRow = $missedShiftStats->get($employee->id);
            $missedShifts = $missedRow ? (int) $missedRow->total : 0;

            // Расчёт рейтинга
            $score = 100;
            if ($totalTasks > 0) {
                $score -= ($userOverdue * 5);
            }
            $score -= ($lateShifts * 10);
            $score = max(0, min(100, $score));

            return [
                'employee_id' => $employee->id,
                'employee_name' => $employee->full_name,
                'total_tasks' => $totalTasks,
                'completed_tasks' => $userCompleted,
                'completed_on_time' => $completedOnTime,
                'completed_late' => $completedLate,
                'completion_rate' => $completionRate,
                'overdue_tasks' => $userOverdue,
                'pending_review' => $pendingReview,
                'rejected_tasks' => $rejectedTasks,
                'avg_completion_time_hours' => $avgCompletionTimeHours,
                'tasks_by_type' => $tasksByType,
                'total_shifts' => $totalShifts,
                'late_shifts' => $lateShifts,
                'avg_late_minutes' => $avgLateMinutes,
                'missed_shifts' => $missedShifts,
                'performance_score' => $score,
                'has_history' => $totalTasks > 0 || $totalShifts > 0 || $missedShifts > 0,
            ];
        });
    }

    /**
     * Вычисляет подробную статистику сотрудника за период.
     *
     * Оставлен для обратной совместимости (используется в issueDetails контроллера).
     * В ReportController::index() теперь используется getBatchStats().
     *
     * @return array<string, mixed>
     */
    public function getStats(User $employee, Carbon $from, Carbon $to): array
    {
        $nowUtc = Carbon::now('UTC');

        // Загружаем все задачи сотрудника с responses за период одним запросом
        $tasks = Task::with(['responses' => function ($q) use ($employee): void {
            $q->where('user_id', $employee->id);
        }])
            ->whereHas('assignedUsers', fn ($q) => $q->where('user_id', $employee->id))
            ->whereBetween('created_at', [$from, $to])
            ->get();

        $totalTasks = $tasks->count();
        $completedOnTime = 0;
        $completedLate = 0;
        $completionTimes = [];
        $pendingReview = 0;
        $rejectedTasks = 0;
        $tasksByType = [
            'notification' => 0,
            'completion' => 0,
            'completion_with_proof' => 0,
        ];

        foreach ($tasks as $task) {
            // Считаем по типам задач
            $type = $task->response_type;
            if (isset($tasksByType[$type])) {
                $tasksByType[$type]++;
            }

            $response = $task->responses->first();
            if (! $response) {
                continue;
            }

            if ($response->status === 'completed') {
                // Среднее время выполнения
                if ($response->responded_at) {
                    $completionTimes[] = $response->responded_at->diffInSeconds($task->created_at) / 3600.0;
                }

                // Вовремя или с опозданием
                if ($task->deadline && $response->responded_at && $response->responded_at->gt($task->deadline)) {
                    $completedLate++;
                } else {
                    $completedOnTime++;
                }
            } elseif ($response->status === 'pending_review') {
                $pendingReview++;
            } elseif ($response->status === 'rejected') {
                $rejectedTasks++;
            }
        }

        $userCompleted = $completedOnTime + $completedLate;

        // Просроченные — дедлайн прошёл, нет completed response
        $userOverdue = $tasks->filter(function ($task) use ($nowUtc): bool {
            if (! $task->is_active || ! $task->deadline || ! $task->deadline->lt($nowUtc)) {
                return false;
            }
            $response = $task->responses->first();

            return ! $response || $response->status !== 'completed';
        })->count();

        // Среднее время выполнения в часах
        $avgCompletionTimeHours = count($completionTimes) > 0
            ? round(array_sum($completionTimes) / count($completionTimes), 1)
            : 0;

        // Процент выполнения
        $completionRate = $totalTasks > 0
            ? round(($userCompleted / $totalTasks) * 100, 1)
            : 0;

        // === Смены ===
        $shiftsQuery = Shift::where('user_id', $employee->id)
            ->whereBetween('shift_start', [$from, $to]);

        $totalShifts = (clone $shiftsQuery)->count();

        $lateShiftsQuery = (clone $shiftsQuery)->where('late_minutes', '>', 0);
        $lateShifts = (clone $lateShiftsQuery)->count();
        $avgLateMinutes = $lateShifts > 0
            ? (int) round((float) $lateShiftsQuery->avg('late_minutes'), 0)
            : 0;

        // Пропущенные смены
        $missedShifts = Shift::where('user_id', $employee->id)
            ->whereBetween('scheduled_start', [$from, $to])
            ->whereNull('shift_start')
            ->where('scheduled_start', '<', $nowUtc)
            ->count();

        // Расчёт рейтинга
        $score = 100;
        if ($totalTasks > 0) {
            $score -= ($userOverdue * 5);
        }
        $score -= ($lateShifts * 10);
        $score = max(0, min(100, $score));

        return [
            'employee_id' => $employee->id,
            'employee_name' => $employee->full_name,
            'total_tasks' => $totalTasks,
            'completed_tasks' => $userCompleted,
            'completed_on_time' => $completedOnTime,
            'completed_late' => $completedLate,
            'completion_rate' => $completionRate,
            'overdue_tasks' => $userOverdue,
            'pending_review' => $pendingReview,
            'rejected_tasks' => $rejectedTasks,
            'avg_completion_time_hours' => $avgCompletionTimeHours,
            'tasks_by_type' => $tasksByType,
            'total_shifts' => $totalShifts,
            'late_shifts' => $lateShifts,
            'avg_late_minutes' => $avgLateMinutes,
            'missed_shifts' => $missedShifts,
            'performance_score' => $score,
            'has_history' => $totalTasks > 0 || $totalShifts > 0 || $missedShifts > 0,
        ];
    }
}
