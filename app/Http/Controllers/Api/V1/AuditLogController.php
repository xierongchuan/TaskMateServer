<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\GetAuditActorsRequest;
use App\Http\Requests\Api\V1\GetAuditLogsRequest;
use App\Models\AuditLog;
use App\Models\AutoDealership;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    /**
     * Список поддерживаемых таблиц для аудита.
     */
    private const ALLOWED_TABLES = [
        'tasks',
        'task_responses',
        'shifts',
        'users',
        'auto_dealerships',
    ];

    /**
     * Получает список логов аудита с пагинацией и фильтрацией.
     *
     * @param GetAuditLogsRequest $request HTTP-запрос с валидацией
     * @return JsonResponse
     */
    public function index(GetAuditLogsRequest $request): JsonResponse
    {
        $query = AuditLog::query()
            ->orderByDesc('created_at');

        // Фильтр по ID журнала
        if ($request->filled('log_id')) {
            $query->where('id', $request->input('log_id'));
        }

        // Фильтр по таблице
        if ($request->filled('table_name')) {
            $query->where('table_name', $request->input('table_name'));
        }

        // Фильтр по действию
        if ($request->filled('action')) {
            $query->where('action', $request->input('action'));
        }

        // Фильтр по пользователю (actor)
        if ($request->filled('actor_id')) {
            $query->where('actor_id', $request->input('actor_id'));
        }

        // Фильтр по автосалону
        if ($request->filled('dealership_id')) {
            $query->where('dealership_id', $request->input('dealership_id'));
        }

        // Фильтр по дате
        if ($request->filled('from_date')) {
            $query->whereDate('created_at', '>=', $request->input('from_date'));
        }
        if ($request->filled('to_date')) {
            $query->whereDate('created_at', '<=', $request->input('to_date'));
        }

        // Фильтр по record_id
        if ($request->filled('record_id')) {
            $query->where('record_id', $request->input('record_id'));
        }

        $perPage = $request->input('per_page', 25);
        $logs = $query->paginate((int) $perPage);

        // Enrich данными: actors и dealerships
        $actorIds = $logs->pluck('actor_id')->unique()->filter()->values()->toArray();
        $actors = User::whereIn('id', $actorIds)->get()->keyBy('id');

        $dealershipIds = $logs->pluck('dealership_id')->unique()->filter()->values()->toArray();
        $dealerships = AutoDealership::whereIn('id', $dealershipIds)->get()->keyBy('id');

        $logsData = $logs->getCollection()->map(function (AuditLog $log) use ($actors, $dealerships) {
            return $this->formatLogEntry($log, $actors, $dealerships);
        });

        return response()->json([
            'data' => $logsData,
            'current_page' => $logs->currentPage(),
            'last_page' => $logs->lastPage(),
            'per_page' => $logs->perPage(),
            'total' => $logs->total(),
        ]);
    }

    /**
     * Получает историю изменений конкретной записи.
     *
     * @param string $tableName Название таблицы
     * @param int $recordId ID записи
     * @return JsonResponse
     */
    public function forRecord(string $tableName, int $recordId): JsonResponse
    {
        // Validate table name to prevent arbitrary table access
        if (!in_array($tableName, self::ALLOWED_TABLES)) {
            return response()->json([
                'message' => 'Таблица не поддерживается'
            ], 400);
        }

        $logs = AuditLog::where('table_name', $tableName)
            ->where('record_id', $recordId)
            ->orderByDesc('created_at')
            ->get();

        // Enrich данными: actors и dealerships
        $actorIds = $logs->pluck('actor_id')->unique()->filter()->values()->toArray();
        $actors = User::whereIn('id', $actorIds)->get()->keyBy('id');

        $dealershipIds = $logs->pluck('dealership_id')->unique()->filter()->values()->toArray();
        $dealerships = AutoDealership::whereIn('id', $dealershipIds)->get()->keyBy('id');

        $logsData = $logs->map(function (AuditLog $log) use ($actors, $dealerships) {
            return $this->formatLogEntry($log, $actors, $dealerships);
        });

        return response()->json([
            'data' => $logsData,
        ]);
    }

    /**
     * Получает список пользователей для фильтра (все пользователи автосалона).
     *
     * @param GetAuditActorsRequest $request HTTP-запрос с валидацией
     * @return JsonResponse
     */
    public function actors(GetAuditActorsRequest $request): JsonResponse
    {
        $query = User::query();

        // Фильтрация по автосалону
        if ($request->filled('dealership_id')) {
            $dealershipId = $request->input('dealership_id');

            $query->where(function ($q) use ($dealershipId) {
                // Пользователи с primary dealership_id = X
                $q->where('users.dealership_id', $dealershipId)
                  // ИЛИ прикрепленные через pivot таблицу
                  ->orWhereHas('dealerships', function ($subQ) use ($dealershipId) {
                      $subQ->where('auto_dealerships.id', $dealershipId);
                  });
            });
        } else {
            // Пользователи без привязки к автосалонам (orphan users)
            $query->whereNull('users.dealership_id')
                  ->whereDoesntHave('dealerships');
        }

        // Сортировка: сначала по роли, потом по имени
        $actors = $query->orderByRaw("
                CASE role
                    WHEN 'owner' THEN 1
                    WHEN 'manager' THEN 2
                    WHEN 'employee' THEN 3
                    WHEN 'observer' THEN 4
                    ELSE 5
                END
            ")
            ->orderBy('full_name')
            ->get(['id', 'full_name', 'login', 'role']);

        return response()->json([
            'data' => $actors->map(fn ($user) => [
                'id' => $user->id,
                'full_name' => $user->full_name,
                'login' => $user->login,
                'role' => $user->role,
            ]),
        ]);
    }

    /**
     * Форматирует запись лога для ответа API.
     *
     * @param AuditLog $log
     * @param \Illuminate\Support\Collection $actors
     * @param \Illuminate\Support\Collection $dealerships
     * @return array
     */
    private function formatLogEntry(AuditLog $log, $actors, $dealerships): array
    {
        $data = $log->toArray();

        // Format datetime in UTC with Z suffix (ISO 8601 Zulu)
        if ($log->created_at) {
            $data['created_at'] = $log->created_at->copy()->setTimezone('UTC')->toIso8601ZuluString();
        }

        // Добавляем информацию об actor
        $data['actor'] = $log->actor_id && isset($actors[$log->actor_id])
            ? [
                'id' => $actors[$log->actor_id]->id,
                'full_name' => $actors[$log->actor_id]->full_name,
                'login' => $actors[$log->actor_id]->login,
            ]
            : null;

        // Добавляем информацию о dealership
        $data['dealership'] = $log->dealership_id && isset($dealerships[$log->dealership_id])
            ? [
                'id' => $dealerships[$log->dealership_id]->id,
                'name' => $dealerships[$log->dealership_id]->name,
            ]
            : null;

        // Добавляем человекочитаемое название таблицы
        $data['table_label'] = $this->getTableLabel($log->table_name);

        // Добавляем человекочитаемое название действия
        $data['action_label'] = $this->getActionLabel($log->action);

        return $data;
    }

    /**
     * Возвращает человекочитаемое название таблицы.
     *
     * @param string $tableName
     * @return string
     */
    private function getTableLabel(string $tableName): string
    {
        return match ($tableName) {
            'tasks' => 'Задачи',
            'task_responses' => 'Ответы на задачи',
            'shifts' => 'Смены',
            'users' => 'Пользователи',
            'auto_dealerships' => 'Автосалоны',
            default => $tableName,
        };
    }

    /**
     * Возвращает человекочитаемое название действия.
     *
     * @param string $action
     * @return string
     */
    private function getActionLabel(string $action): string
    {
        return match ($action) {
            'created' => 'Создание',
            'updated' => 'Обновление',
            'deleted' => 'Удаление',
            default => $action,
        };
    }
}
