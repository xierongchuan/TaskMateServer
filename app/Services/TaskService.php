<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\TaskAssigned;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\User;
use Carbon\Carbon;
use App\Helpers\TimeHelper;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Сервис для бизнес-логики задач.
 *
 * Инкапсулирует логику создания, обновления и проверки дубликатов задач.
 */
class TaskService
{
    public function __construct(
        private readonly DealershipAccessService $dealershipAccess,
    ) {}

    /**
     * Создаёт новую задачу.
     *
     * @param  array<string, mixed>  $data  Валидированные данные задачи
     * @param  User  $creator  Пользователь, создающий задачу
     *
     * @throws \App\Exceptions\DuplicateTaskException
     * @throws \App\Exceptions\AccessDeniedException
     */
    public function createTask(array $data, User $creator): Task
    {
        // Проверка дубликата
        if ($this->isDuplicate($data)) {
            throw new \App\Exceptions\DuplicateTaskException('Такая задача уже существует (дубликат)');
        }

        $task = DB::transaction(function () use ($data, $creator) {
            $task = Task::create([
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'comment' => $data['comment'] ?? null,
                'creator_id' => $creator->id,
                'dealership_id' => $data['dealership_id'] ?? null,
                'appear_date' => $data['appear_date'] ?? null,
                'deadline' => $data['deadline'] ?? null,
                'task_type' => $data['task_type'],
                'response_type' => $data['response_type'],
                'tags' => $data['tags'] ?? null,
                'notification_settings' => $data['notification_settings'] ?? null,
                'priority' => $data['priority'] ?? 'medium',
            ]);

            // Назначение пользователей
            if (! empty($data['assignments'])) {
                $this->syncAssignments($task, $data['assignments']);
            }

            return $task;
        });

        // Публикуем событие после коммита транзакции
        if (! empty($data['assignments'])) {
            event(new TaskAssigned($task, $data['assignments']));
        }

        return $task;
    }

    /**
     * Обновляет существующую задачу.
     *
     * @param  Task  $task  Задача для обновления
     * @param  array<string, mixed>  $data  Валидированные данные для обновления
     */
    public function updateTask(Task $task, array $data): Task
    {
        return DB::transaction(function () use ($task, $data) {
            $task->update($data);

            // Обновление назначений, если переданы
            if (array_key_exists('assignments', $data)) {
                $this->syncAssignments($task, $data['assignments'] ?? []);
            }

            return $task;
        });
    }

    /**
     * Проверяет, является ли задача дубликатом.
     *
     * @param  array<string, mixed>  $data  Данные задачи для проверки
     */
    public function isDuplicate(array $data): bool
    {
        $dealershipId = $data['dealership_id'] ?? null;

        $query = Task::where('title', $data['title'])
            ->where('task_type', $data['task_type'])
            ->where('is_active', true);

        // Корректная обработка null для dealership_id (WHERE = NULL не работает в SQL)
        if ($dealershipId !== null) {
            $query->where('dealership_id', $dealershipId);
        } else {
            $query->whereNull('dealership_id');
        }

        // Проверка дедлайна с допуском (игнорируя несоответствие секунд)
        if (! empty($data['deadline'])) {
            // deadline приходит как ISO 8601 (с Z или offset), парсим и конвертируем в UTC
            $deadlineDate = Carbon::parse($data['deadline'])->setTimezone('UTC');
            $start = $deadlineDate->copy()->startOfMinute();
            $end = $deadlineDate->copy()->endOfMinute();

            $query->whereBetween('deadline', [$start, $end]);
        } else {
            $query->whereNull('deadline');
        }

        // Проверка описания
        if (! empty($data['description'])) {
            $query->where('description', $data['description']);
        } else {
            $query->whereNull('description');
        }

        return $query->exists();
    }

    /**
     * Синхронизирует назначения пользователей для задачи.
     *
     * Использует SoftDeletes для сохранения истории назначений.
     * При повторном назначении восстанавливает ранее удалённые записи.
     *
     * ВАЖНО: Метод должен вызываться внутри транзакции для предотвращения race conditions.
     *
     * @param  Task  $task  Задача
     * @param  array<int>  $userIds  Массив ID пользователей
     */
    protected function syncAssignments(Task $task, array $userIds): void
    {
        // lockForUpdate предотвращает race condition при параллельных запросах
        $existingAssignments = TaskAssignment::where('task_id', $task->id)
            ->lockForUpdate()
            ->pluck('user_id')
            ->toArray();

        $toAdd = array_diff($userIds, $existingAssignments);
        $toRemove = array_diff($existingAssignments, $userIds);

        // SoftDelete удалённых назначений
        if (! empty($toRemove)) {
            TaskAssignment::where('task_id', $task->id)
                ->whereIn('user_id', $toRemove)
                ->delete();
        }

        // Восстановление ранее удалённых назначений
        $restoredIds = TaskAssignment::withTrashed()
            ->where('task_id', $task->id)
            ->whereIn('user_id', $toAdd)
            ->onlyTrashed()
            ->pluck('user_id')
            ->toArray();

        if (! empty($restoredIds)) {
            TaskAssignment::withTrashed()
                ->where('task_id', $task->id)
                ->whereIn('user_id', $restoredIds)
                ->restore();
        }

        // Создание новых назначений для пользователей, которых ранее не было (bulk insert)
        $toCreate = array_diff($toAdd, $restoredIds);
        if (! empty($toCreate)) {
            $now = TimeHelper::nowUtc();
            $bulkData = array_map(fn (int $userId) => [
                'task_id' => $task->id,
                'user_id' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ], array_values($toCreate));
            TaskAssignment::insert($bulkData);
        }
    }

    /**
     * Удаляет задачу и её назначения.
     *
     * @param  Task  $task  Задача для удаления
     */
    public function deleteTask(Task $task): void
    {
        DB::transaction(function () use ($task) {
            TaskAssignment::where('task_id', $task->id)->delete();
            $task->delete();
        });
    }

    /**
     * Получает историю выполненных задач пользователя.
     *
     * @param  User  $user  Пользователь
     * @param  Request  $request  HTTP-запрос с параметрами фильтрации
     */
    public function getMyHistory(User $user, Request $request): LengthAwarePaginator
    {
        $query = Task::with([
            'creator',
            'dealership',
            'assignments.user',
            'responses' => function ($q) use ($user) {
                $q->where('user_id', $user->id)->with(['proofs', 'verifier']);
            },
        ])
            ->whereHas('assignments', function ($q) use ($user) {
                $q->where('user_id', $user->id);
            })
            ->whereHas('responses', function ($q) use ($user) {
                $q->where('user_id', $user->id);
            });

        if ($request->filled('response_status')) {
            $status = $request->input('response_status');
            $query->whereHas('responses', function ($q) use ($user, $status) {
                $q->where('user_id', $user->id)->where('status', $status);
            });
        }

        if ($request->filled('dealership_id')) {
            $query->where('dealership_id', $request->input('dealership_id'));
        }

        $query->orderByDesc('updated_at');

        $perPage = min((int) $request->input('per_page', 15), 100);

        return $query->paginate($perPage);
    }

    /**
     * Проверяет доступ к автосалону для создания задачи.
     *
     * @param  User  $user  Пользователь
     * @param  int|null  $dealershipId  ID автосалона
     * @return bool True, если доступ разрешён
     */
    public function canAccessDealership(User $user, ?int $dealershipId): bool
    {
        if ($dealershipId === null) {
            return true;
        }

        return $this->dealershipAccess->hasAccessToDealership($user, $dealershipId);
    }
}
