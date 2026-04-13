<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\TimeHelper;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Сервис для фильтрации задач.
 *
 * Извлекает всю логику фильтрации из TaskController
 * для улучшения читаемости и тестируемости кода.
 */
class TaskFilterService
{
    public function __construct(
        private readonly DealershipAccessService $dealershipAccess,
    ) {}

    /**
     * Применяет все фильтры и возвращает пагинированный результат.
     *
     * @param  Request  $request  HTTP-запрос с параметрами фильтрации
     * @param  User  $currentUser  Текущий пользователь
     */
    public function getFilteredTasks(Request $request, User $currentUser): LengthAwarePaginator
    {
        $query = Task::with([
            'creator',
            'dealership',
            'assignments.user',
            'responses.user',
            'responses.proofs',
            'responses.verifier',
            'sharedProofs',
        ]);

        $this->applyDateRangeFilter($query, $request);
        $this->applyDealershipFilter($query, $request, $currentUser);
        $this->applyBasicFilters($query, $request);
        $this->applyDeadlineFilters($query, $request);
        $this->applyGeneratorFilters($query, $request);
        $this->applySearchFilter($query, $request);
        $this->applyStatusFilter($query, $request);

        // Исключаем архивные задачи
        $query->whereNull('archived_at');

        $perPage = min($request->integer('per_page', 15), 100);

        $allowedSortFields = ['created_at', 'title', 'priority', 'deadline'];
        $sortField = $request->get('sort_by', 'created_at');
        $sortDir = $request->get('sort_dir', 'desc');

        if (! in_array($sortField, $allowedSortFields, true)) {
            $sortField = 'created_at';
        }
        if (! in_array($sortDir, ['asc', 'desc'], true)) {
            $sortDir = 'desc';
        }

        return $query->orderBy($sortField, $sortDir)->paginate($perPage);
    }

    /**
     * Применяет фильтр по диапазону дат.
     */
    protected function applyDateRangeFilter(Builder $query, Request $request): void
    {
        $dateRange = $request->query('date_range');
        $status = $request->query('status');

        if (! $dateRange || $dateRange === 'all') {
            return;
        }

        [$dateStart, $dateEnd] = $this->getDateBoundaries($dateRange);

        if ($dateStart && $dateEnd) {
            if ($status === 'completed') {
                $query->whereHas('responses', function ($q) use ($dateStart, $dateEnd) {
                    $q->where('status', 'completed')->whereBetween('responded_at', [$dateStart, $dateEnd]);
                });
            } else {
                $query->whereBetween('deadline', [$dateStart, $dateEnd]);
            }
        }
    }

    /**
     * Возвращает границы дат для заданного диапазона.
     *
     * @param  string  $dateRange  Тип диапазона (today, week, month)
     * @return array{0: Carbon|null, 1: Carbon|null}
     */
    protected function getDateBoundaries(string $dateRange): array
    {
        return match ($dateRange) {
            "today" => [TimeHelper::startOfDayUtc(), TimeHelper::endOfDayUtc()],
            "week" => [TimeHelper::startOfWeekUtc(), TimeHelper::endOfWeekUtc()],
            "month" => [TimeHelper::startOfMonthUtc(), TimeHelper::endOfMonthUtc()],
            default => [null, null],
        };
    }

    /**
     * Применяет фильтр по автосалону с учётом прав доступа.
     */
    protected function applyDealershipFilter(Builder $query, Request $request, User $currentUser): void
    {
        $dealershipId = $request->filled('dealership_id') ? $request->integer('dealership_id') : null;

        if (! $this->dealershipAccess->isOwner($currentUser)) {
            if ($dealershipId) {
                if (! $this->dealershipAccess->hasAccessToDealership($currentUser, $dealershipId)) {
                    // Если фильтрация по недоступному автосалону - возвращаем пустой результат
                    $query->where('dealership_id', -1);
                } else {
                    $query->where('dealership_id', $dealershipId);
                }
            } else {
                // Показываем задачи из всех доступных автосалонов
                $this->dealershipAccess->scopeTasksByAccessibleDealerships($query, $currentUser);
            }
        } elseif ($dealershipId) {
            $query->where('dealership_id', $dealershipId);
        }
    }

    /**
     * Применяет базовые фильтры (тип задачи, активность, создатель, тип ответа, теги, приоритет).
     */
    protected function applyBasicFilters(Builder $query, Request $request): void
    {
        // Фильтр по типу задачи
        if ($request->filled('task_type')) {
            $query->where('task_type', $request->query('task_type'));
        }

        // Фильтр по активности
        if ($request->has('is_active')) {
            $isActive = filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($isActive !== null) {
                $query->where('is_active', $isActive);
            }
        }

        // Фильтр по создателю
        if ($request->filled('creator_id')) {
            $query->where('creator_id', $request->integer('creator_id'));
        }

        // Фильтр по типу ответа
        if ($request->filled('response_type')) {
            $query->where('response_type', $request->query('response_type'));
        }

        // Фильтр по тегам
        if ($request->filled('tags')) {
            $tags = $request->query('tags');
            $tagsArray = is_array($tags) ? $tags : explode(',', $tags);
            $tagsArray = array_map('trim', $tagsArray);

            $query->where(function ($q) use ($tagsArray) {
                foreach ($tagsArray as $tag) {
                    $q->orWhereJsonContains('tags', $tag);
                }
            });
        }

        // Фильтр по приоритету
        if ($request->filled('priority')) {
            $query->where('priority', $request->query('priority'));
        }

        // Фильтр по исполнителю (assigned_to)
        if ($request->filled('assigned_to')) {
            $query->whereHas('assignments', function ($q) use ($request) {
                $q->where('user_id', $request->integer('assigned_to'));
            });
        }
    }

    /**
     * Применяет фильтры по дедлайну.
     */
    protected function applyDeadlineFilters(Builder $query, Request $request): void
    {
        // Фильтр по началу дедлайна
        if ($request->filled('deadline_from')) {
            try {
                $query->where('deadline', '>=', Carbon::parse($request->query('deadline_from')));
            } catch (\Exception) {
                // Некорректная дата - игнорируем фильтр
            }
        }

        // Фильтр по концу дедлайна
        if ($request->filled('deadline_to')) {
            try {
                $query->where('deadline', '<=', Carbon::parse($request->query('deadline_to')));
            } catch (\Exception) {
                // Некорректная дата - игнорируем фильтр
            }
        }
    }

    /**
     * Применяет фильтры по генератору задач.
     */
    protected function applyGeneratorFilters(Builder $query, Request $request): void
    {
        // Фильтр по конкретному генератору
        if ($request->filled('generator_id')) {
            $query->where('generator_id', $request->integer('generator_id'));
        }

        // Фильтр по источнику задачи (из генератора или нет)
        $fromGenerator = $request->query('from_generator');
        if ($fromGenerator === 'yes') {
            $query->whereNotNull('generator_id');
        } elseif ($fromGenerator === 'no') {
            $query->whereNull('generator_id');
        }
    }

    /**
     * Применяет поисковый фильтр.
     */
    protected function applySearchFilter(Builder $query, Request $request): void
    {
        $search = $request->query('search');
        if (! $search) {
            return;
        }

        $escapedSearch = str_replace(['%', '_'], ["\%", "\_"], $search);

        $query->where(function ($q) use ($escapedSearch) {
            $q->where('title', 'ILIKE', "%{$escapedSearch}%")
                ->orWhere('description', 'ILIKE', "%{$escapedSearch}%")
                ->orWhere('comment', 'ILIKE', "%{$escapedSearch}%")
                ->orWhereRaw('tags::text ILIKE ?', ["%{$escapedSearch}%"]);
        });
    }

    /**
     * Применяет фильтр по статусу задачи.
     */
    protected function applyStatusFilter(Builder $query, Request $request): void
    {
        $status = $request->query('status');
        if (! $status) {
            return;
        }

        $nowUtc = TimeHelper::nowUtc();

        switch (strtolower($status)) {
            case 'active':
                $query->where('is_active', true)->whereNull('archived_at');
                break;

            case 'completed':
                $query->completed();
                break;

            case 'pending_review':
                $query->whereHas('responses', fn ($q) => $q->where('status', 'pending_review'));
                break;

            case 'overdue':
                $query->overdue($nowUtc);
                break;

            case 'pending':
                $query
                    ->where('is_active', true)
                    ->whereDoesntHave(
                        'responses',
                        fn ($q) => $q->whereIn('status', ['completed', 'acknowledged', 'pending_review']),
                    );
                break;

            case 'acknowledged':
                $query->whereHas('responses', fn ($q) => $q->where('status', 'acknowledged'));
                break;
        }
    }
}
