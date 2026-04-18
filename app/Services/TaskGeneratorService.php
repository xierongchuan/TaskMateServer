<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\TaskGenerator;
use App\Models\TaskGeneratorAssignment;
use App\Models\User;
use Carbon\Carbon;
use App\Helpers\TimeHelper;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Сервис для бизнес-логики генераторов задач.
 *
 * Инкапсулирует создание, обновление и вычисление статистики генераторов.
 */
class TaskGeneratorService
{
    /**
     * Создаёт новый генератор задач с назначениями.
     *
     * @param  array<string, mixed>  $data  Валидированные данные
     * @param  User  $creator  Пользователь, создающий генератор
     */
    public function createGenerator(array $data, User $creator): TaskGenerator
    {
        return DB::transaction(function () use ($data, $creator) {
            $daysOfWeek = $this->resolveDaysOfWeek($data);
            $daysOfMonth = $this->resolveDaysOfMonth($data);

            $generator = TaskGenerator::create([
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'comment' => $data['comment'] ?? null,
                'creator_id' => $creator->id,
                'dealership_id' => $data['dealership_id'],
                'recurrence' => $data['recurrence'],
                'recurrence_time' => $data['recurrence_time'].':00',
                'deadline_time' => $data['deadline_time'].':00',
                'recurrence_days_of_week' => $daysOfWeek,
                'recurrence_days_of_month' => $daysOfMonth,
                'start_date' => Carbon::parse($data['start_date'])->setTimezone('UTC'),
                'end_date' => isset($data['end_date'])
                    ? Carbon::parse($data['end_date'])->setTimezone('UTC')
                    : null,
                'task_type' => $data['task_type'] ?? 'individual',
                'response_type' => $data['response_type'] ?? 'notification',
                'priority' => $data['priority'] ?? 'medium',
                'tags' => $data['tags'] ?? null,
                'notification_settings' => $data['notification_settings'] ?? null,
                'is_active' => true,
            ]);

            $this->syncAssignments($generator, $data['assignments']);

            return $generator;
        });
    }

    /**
     * Обновляет существующий генератор задач.
     *
     * @param  TaskGenerator  $generator  Генератор для обновления
     * @param  array<string, mixed>  $data  Валидированные данные для обновления
     */
    public function updateGenerator(TaskGenerator $generator, array $data): TaskGenerator
    {
        return DB::transaction(function () use ($generator, $data) {
            $updateData = $this->buildUpdateData($data);

            if (! empty($updateData)) {
                $generator->update($updateData);
            }

            if (isset($data['assignments'])) {
                $this->syncAssignments($generator, $data['assignments']);
            }

            return $generator;
        });
    }

    /**
     * Вычисляет статистику для генератора задач по всем временным периодам.
     *
     * @return array<string, mixed>
     */
    public function getStatistics(TaskGenerator $generator): array
    {
        // Загружаем все задачи один раз вместо 4 отдельных запросов
        $allTasks = $generator->generatedTasks()
            ->with(['responses', 'assignments'])
            ->get();

        $periods = [7, 30, 365];
        $cutoffs = [];
        foreach ($periods as $days) {
            $cutoffs[$days] = TimeHelper::nowUtc()->subDays($days)->startOfDay();
        }

        return [
            'generator_id' => $generator->id,
            'all_time' => $this->computeStatsForTasks($allTasks),
            'week' => $this->computeStatsForTasks($allTasks->filter(fn ($t) => $t->scheduled_date >= $cutoffs[7])),
            'month' => $this->computeStatsForTasks($allTasks->filter(fn ($t) => $t->scheduled_date >= $cutoffs[30])),
            'year' => $this->computeStatsForTasks($allTasks->filter(fn ($t) => $t->scheduled_date >= $cutoffs[365])),
            'average_completion_time_minutes' => $this->computeAverageCompletionTime($allTasks),
        ];
    }

    /**
     * Синхронизирует назначения пользователей для генератора.
     *
     * Удаляет старые назначения и создаёт новые в bulk insert.
     * Вызывается внутри транзакции.
     *
     * @param  array<int>  $userIds  Массив ID пользователей
     */
    private function syncAssignments(TaskGenerator $generator, array $userIds): void
    {
        TaskGeneratorAssignment::where('generator_id', $generator->id)->delete();

        if (! empty($userIds)) {
            TaskGeneratorAssignment::insert(array_map(fn ($userId) => [
                'generator_id' => $generator->id,
                'user_id' => $userId,
            ], $userIds));
        }
    }

    /**
     * Строит массив данных для обновления генератора на основе переданных полей.
     *
     * Обрабатывает обратную совместимость: конвертирует старый формат одиночного значения
     * recurrence_day_of_week / recurrence_day_of_month в новый формат массивов.
     *
     * @param  array<string, mixed>  $data  Валидированные данные
     * @return array<string, mixed>
     */
    private function buildUpdateData(array $data): array
    {
        $updateData = [];

        if (isset($data['title'])) {
            $updateData['title'] = $data['title'];
        }
        if (array_key_exists('description', $data)) {
            $updateData['description'] = $data['description'];
        }
        if (array_key_exists('comment', $data)) {
            $updateData['comment'] = $data['comment'];
        }
        if (isset($data['recurrence'])) {
            $updateData['recurrence'] = $data['recurrence'];
        }
        if (isset($data['recurrence_time'])) {
            $updateData['recurrence_time'] = $data['recurrence_time'].':00';
        }
        if (isset($data['deadline_time'])) {
            $updateData['deadline_time'] = $data['deadline_time'].':00';
        }

        // Обратная совместимость: старый формат → новый формат массива
        if (array_key_exists('recurrence_days_of_week', $data)) {
            $updateData['recurrence_days_of_week'] = $data['recurrence_days_of_week'];
        } elseif (array_key_exists('recurrence_day_of_week', $data)) {
            $updateData['recurrence_days_of_week'] = $data['recurrence_day_of_week']
                ? [$data['recurrence_day_of_week']]
                : null;
        }

        if (array_key_exists('recurrence_days_of_month', $data)) {
            $updateData['recurrence_days_of_month'] = $data['recurrence_days_of_month'];
        } elseif (array_key_exists('recurrence_day_of_month', $data)) {
            $updateData['recurrence_days_of_month'] = $data['recurrence_day_of_month']
                ? [$data['recurrence_day_of_month']]
                : null;
        }

        if (isset($data['start_date'])) {
            $updateData['start_date'] = Carbon::parse($data['start_date'])->setTimezone('UTC');
        }
        if (array_key_exists('end_date', $data)) {
            $updateData['end_date'] = $data['end_date']
                ? Carbon::parse($data['end_date'])->setTimezone('UTC')
                : null;
        }
        if (isset($data['task_type'])) {
            $updateData['task_type'] = $data['task_type'];
        }
        if (isset($data['response_type'])) {
            $updateData['response_type'] = $data['response_type'];
        }
        if (isset($data['priority'])) {
            $updateData['priority'] = $data['priority'];
        }
        if (array_key_exists('tags', $data)) {
            $updateData['tags'] = $data['tags'];
        }
        if (array_key_exists('notification_settings', $data)) {
            $updateData['notification_settings'] = $data['notification_settings'];
        }

        return $updateData;
    }

    /**
     * Вычисляет статистику для коллекции задач.
     *
     * Считает задачи по фактическому статусу:
     * - Выполнено: заархивировано с причиной 'completed' ИЛИ активная задача со статусом 'completed'
     * - Просрочено: заархивировано с причиной 'expired' ИЛИ активная задача со статусом 'overdue'
     * - Ожидает: активные задачи без выполнения или просрочки
     *
     * @param  Collection<int, \App\Models\Task>  $tasks
     * @return array<string, mixed>
     */
    private function computeStatsForTasks(Collection $tasks): array
    {
        $totalGenerated = $tasks->count();
        $completedCount = 0;
        $expiredCount = 0;
        $pendingCount = 0;
        $onTimeCount = 0;

        foreach ($tasks as $task) {
            $status = $task->status;

            if ($task->archived_at !== null) {
                if ($task->archive_reason === 'completed') {
                    $completedCount++;
                    if ($task->deadline && Carbon::parse($task->archived_at)->lte(Carbon::parse($task->deadline))) {
                        $onTimeCount++;
                    }
                } elseif ($task->archive_reason === 'expired') {
                    $expiredCount++;
                } else {
                    $pendingCount++;
                }
            } else {
                if ($status === 'completed') {
                    $completedCount++;
                    $completedResponse = $task->responses->where('status', 'completed')->sortByDesc('responded_at')->first();
                    if ($completedResponse && $task->deadline) {
                        if (Carbon::parse($completedResponse->responded_at)->lte(Carbon::parse($task->deadline))) {
                            $onTimeCount++;
                        }
                    }
                } elseif ($status === 'overdue') {
                    $expiredCount++;
                } else {
                    $pendingCount++;
                }
            }
        }

        $completionRate = $totalGenerated > 0
            ? round(($completedCount / $totalGenerated) * 100, 2)
            : 0;

        $onTimeRate = $completedCount > 0
            ? round(($onTimeCount / $completedCount) * 100, 2)
            : 0;

        return [
            'total_generated' => $totalGenerated,
            'completed_count' => $completedCount,
            'expired_count' => $expiredCount,
            'pending_count' => $pendingCount,
            'on_time_count' => $onTimeCount,
            'completion_rate' => $completionRate,
            'on_time_rate' => $onTimeRate,
        ];
    }

    /**
     * Вычисляет среднее время выполнения задач в минутах из предзагруженной коллекции.
     *
     * Учитывает только реалистичные значения: от 1 минуты до 7 дней.
     *
     * @param  Collection<int, \App\Models\Task>  $tasks
     */
    private function computeAverageCompletionTime(Collection $tasks): ?float
    {
        $totalMinutes = 0;
        $count = 0;

        foreach ($tasks as $task) {
            if (! $task->appear_date) {
                continue;
            }

            $appearDate = Carbon::parse($task->appear_date);
            $completedAt = null;

            if ($task->archived_at !== null && $task->archive_reason === 'completed') {
                $completedAt = Carbon::parse($task->archived_at);
            } else {
                $completedResponse = $task->responses->where('status', 'completed')->sortByDesc('responded_at')->first();
                if ($completedResponse) {
                    $completedAt = Carbon::parse($completedResponse->responded_at);
                }
            }

            if ($completedAt) {
                $minutes = $appearDate->diffInMinutes($completedAt);

                if ($minutes > 0 && $minutes < 60 * 24 * 7) {
                    $totalMinutes += $minutes;
                    $count++;
                }
            }
        }

        return $count > 0 ? round($totalMinutes / $count, 2) : null;
    }

    /**
     * Разрешает дни недели из данных запроса с поддержкой обратной совместимости.
     *
     * @param  array<string, mixed>  $data
     * @return array<int>|null
     */
    private function resolveDaysOfWeek(array $data): ?array
    {
        $daysOfWeek = $data['recurrence_days_of_week'] ?? null;

        if (empty($daysOfWeek) && ! empty($data['recurrence_day_of_week'])) {
            $daysOfWeek = [$data['recurrence_day_of_week']];
        }

        return $daysOfWeek ?: null;
    }

    /**
     * Разрешает дни месяца из данных запроса с поддержкой обратной совместимости.
     *
     * @param  array<string, mixed>  $data
     * @return array<int>|null
     */
    private function resolveDaysOfMonth(array $data): ?array
    {
        $daysOfMonth = $data['recurrence_days_of_month'] ?? null;

        if (empty($daysOfMonth) && ! empty($data['recurrence_day_of_month'])) {
            $daysOfMonth = [$data['recurrence_day_of_month']];
        }

        return $daysOfMonth ?: null;
    }
}
