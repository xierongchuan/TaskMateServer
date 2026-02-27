<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Shift;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;

class EmployeeStatsService
{
    /**
     * Вычисляет подробную статистику сотрудника за период.
     *
     * @return array<string, mixed>
     */
    public function getStats(User $employee, Carbon $from, Carbon $to): array
    {
        $nowUtc = Carbon::now('UTC');

        // Загружаем все задачи сотрудника с responses за период одним запросом
        $tasks = Task::with(['responses' => function ($q) use ($employee) {
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
        $userOverdue = $tasks->filter(function ($task) use ($nowUtc) {
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
