<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\TimeHelper;
use App\Http\Controllers\Controller;
use App\Models\Shift;
use App\Models\Task;
use App\Models\User;
use App\Services\EmployeeStatsService;
use App\Services\ReportService;
use App\Traits\HasDealershipAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    use HasDealershipAccess;

    public function __construct(
        private readonly EmployeeStatsService $employeeStatsService,
        private readonly ReportService $reportService,
    ) {}

    /**
     * Возвращает сводный отчёт за указанный период.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');

        if (! $dateFrom || ! $dateTo) {
            return response()->json(['message' => 'Параметры date_from и date_to обязательны'], 400);
        }

        $from = TimeHelper::startOfDayUtc($dateFrom);
        $to = TimeHelper::endOfDayUtc($dateTo);

        $dealershipResult = $this->resolveDealershipFilter($request, $user);
        if ($dealershipResult instanceof JsonResponse) {
            return $dealershipResult;
        }
        $dealershipId = $dealershipResult;

        $report = $this->reportService->generateReport($dealershipId, $from, $to, $dateFrom, $dateTo);

        return response()->json($report);
    }

    /**
     * Возвращает детали проблемы по типу.
     */
    public function issueDetails(Request $request, string $issueType): JsonResponse
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

                $items = $employeesQuery->get()->map(function ($employee) use ($from, $to): array {
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
    private function scopeByDealership(mixed $query, ?int $dealershipId): void
    {
        if ($dealershipId) {
            $query->where('dealership_id', $dealershipId);
        }
    }
}
