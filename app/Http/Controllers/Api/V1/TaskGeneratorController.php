<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TaskGenerator;
use App\Models\TaskGeneratorAssignment;
use App\Traits\HasDealershipAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Carbon\Carbon;
use App\Enums\Role;

class TaskGeneratorController extends Controller
{
    use HasDealershipAccess;

    /**
     * Проверяет доступ к генератору задач.
     */
    private function validateGeneratorAccess(Request $request, TaskGenerator $generator): ?JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        if (!$this->hasAccessToDealership($user, $generator->dealership_id)) {
            return response()->json([
                'success' => false,
                'message' => 'Нет доступа к этому генератору задач',
            ], 403);
        }
        return null;
    }
    /**
     * List all task generators with filtering.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $query = TaskGenerator::with(['creator', 'dealership', 'assignments.user']);

        // Filter by dealership
        if ($request->has('dealership_id')) {
            $query->where('dealership_id', $request->dealership_id);
        } elseif ($user->role !== Role::OWNER) {
            // Non-owner users see only their dealership's generators
            $query->where('dealership_id', $user->dealership_id);
        }

        // Filter by active status
        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        }

        // Filter by recurrence type
        if ($request->has('recurrence')) {
            $query->where('recurrence', $request->recurrence);
        }

        // Search by title
        if ($request->has('search')) {
            $query->where('title', 'ilike', '%' . $request->search . '%');
        }

        // Sorting
        $sortField = $request->get('sort_by', 'created_at');
        $sortDir = $request->get('sort_dir', 'desc');
        $query->orderBy($sortField, $sortDir);

        // Pagination
        $perPage = min($request->get('per_page', 15), 100);
        $generators = $query->paginate($perPage);

        // Transform data
        $generators->getCollection()->transform(fn($g) => $g->toApiArray());

        return response()->json($generators);
    }

    /**
     * Show a single task generator.
     */
    public function show(Request $request, $id)
    {
        $generator = TaskGenerator::with(['creator', 'dealership', 'assignments.user'])
            ->findOrFail($id);

        // Проверка доступа к генератору
        if ($accessError = $this->validateGeneratorAccess($request, $generator)) {
            return $accessError;
        }

        return response()->json([
            'success' => true,
            'data' => $generator->toApiArray(),
        ]);
    }

    /**
     * Create a new task generator.
     */
    public function store(Request $request)
    {
        /** @var \App\Models\User $currentUser */
        $currentUser = $request->user();

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'comment' => 'nullable|string',
            'dealership_id' => 'required|exists:auto_dealerships,id',
            'recurrence' => 'required|in:daily,weekly,monthly',
            'recurrence_time' => 'required|date_format:H:i',
            'deadline_time' => 'required|date_format:H:i',
            // Support both old (single int) and new (array) formats for backwards compatibility
            'recurrence_day_of_week' => 'nullable|integer|min:1|max:7',
            'recurrence_day_of_month' => 'nullable|integer|min:-2|max:31',
            'recurrence_days_of_week' => 'nullable|array|max:7',
            'recurrence_days_of_week.*' => 'integer|min:1|max:7',
            'recurrence_days_of_month' => 'nullable|array|max:31',
            'recurrence_days_of_month.*' => 'integer|min:-2|max:31|not_in:0',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'task_type' => 'nullable|in:individual,group',
            'response_type' => 'nullable|in:notification,completion,completion_with_proof',
            'priority' => 'nullable|in:low,medium,high',
            'tags' => 'nullable|array',
            'notification_settings' => 'nullable|array',
            'assignments' => 'required|array|min:1',
            'assignments.*' => 'exists:users,id',
        ]);

        // Проверка доступа к дилерству
        if ($accessError = $this->validateDealershipAccess($currentUser, (int) $validated['dealership_id'])) {
            return $accessError;
        }

        // Handle backwards compatibility: convert old single-value fields to arrays
        $daysOfWeek = $validated['recurrence_days_of_week'] ?? null;
        $daysOfMonth = $validated['recurrence_days_of_month'] ?? null;

        // If old format is provided, convert to array
        if (empty($daysOfWeek) && !empty($validated['recurrence_day_of_week'])) {
            $daysOfWeek = [$validated['recurrence_day_of_week']];
        }
        if (empty($daysOfMonth) && !empty($validated['recurrence_day_of_month'])) {
            $daysOfMonth = [$validated['recurrence_day_of_month']];
        }

        // Validate recurrence requirements
        if ($validated['recurrence'] === 'weekly' && empty($daysOfWeek)) {
            return response()->json([
                'success' => false,
                'message' => 'recurrence_days_of_week is required for weekly recurrence',
            ], 422);
        }

        if ($validated['recurrence'] === 'monthly' && empty($daysOfMonth)) {
            return response()->json([
                'success' => false,
                'message' => 'recurrence_days_of_month is required for monthly recurrence',
            ], 422);
        }

        // Валидация типа задачи и количества исполнителей
        $taskType = $validated['task_type'] ?? 'individual';
        $assignmentCount = count($validated['assignments']);

        if ($taskType === 'group' && $assignmentCount === 0) {
            return response()->json([
                'success' => false,
                'message' => 'Для групповой задачи необходимо указать хотя бы одного исполнителя',
                'errors' => ['assignments' => ['Для групповой задачи необходимо указать хотя бы одного исполнителя']],
            ], 422);
        }

        if ($taskType === 'individual' && $assignmentCount > 1) {
            return response()->json([
                'success' => false,
                'message' => 'Индивидуальная задача не может иметь более одного исполнителя',
                'errors' => ['task_type' => ['Индивидуальная задача не может иметь более одного исполнителя. Используйте групповую задачу для нескольких исполнителей.']],
            ], 422);
        }

        $user = $request->user();

        $generator = TaskGenerator::create([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'comment' => $validated['comment'] ?? null,
            'creator_id' => $user->id,
            'dealership_id' => $validated['dealership_id'],
            'recurrence' => $validated['recurrence'],
            'recurrence_time' => $validated['recurrence_time'] . ':00',
            'deadline_time' => $validated['deadline_time'] . ':00',
            'recurrence_days_of_week' => $daysOfWeek,
            'recurrence_days_of_month' => $daysOfMonth,
            'start_date' => Carbon::parse($validated['start_date'])->setTimezone('UTC'),
            'end_date' => isset($validated['end_date'])
                ? Carbon::parse($validated['end_date'])->setTimezone('UTC')
                : null,
            'task_type' => $validated['task_type'] ?? 'individual',
            'response_type' => $validated['response_type'] ?? 'notification',
            'priority' => $validated['priority'] ?? 'medium',
            'tags' => $validated['tags'] ?? null,
            'notification_settings' => $validated['notification_settings'] ?? null,
            'is_active' => true,
        ]);

        // Create assignments
        foreach ($validated['assignments'] as $userId) {
            TaskGeneratorAssignment::create([
                'generator_id' => $generator->id,
                'user_id' => $userId,
            ]);
        }

        $generator->load(['creator', 'dealership', 'assignments.user']);

        return response()->json([
            'success' => true,
            'data' => $generator->toApiArray(),
            'message' => 'Task generator created successfully',
        ], 201);
    }

    /**
     * Update a task generator.
     */
    public function update(Request $request, $id)
    {
        $generator = TaskGenerator::findOrFail($id);

        // Проверка доступа к генератору
        if ($accessError = $this->validateGeneratorAccess($request, $generator)) {
            return $accessError;
        }

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'comment' => 'nullable|string',
            'recurrence' => 'sometimes|in:daily,weekly,monthly',
            'recurrence_time' => 'sometimes|date_format:H:i',
            'deadline_time' => 'sometimes|date_format:H:i',
            // Support both old (single int) and new (array) formats for backwards compatibility
            'recurrence_day_of_week' => 'nullable|integer|min:1|max:7',
            'recurrence_day_of_month' => 'nullable|integer|min:-2|max:31',
            'recurrence_days_of_week' => 'nullable|array|max:7',
            'recurrence_days_of_week.*' => 'integer|min:1|max:7',
            'recurrence_days_of_month' => 'nullable|array|max:31',
            'recurrence_days_of_month.*' => 'integer|min:-2|max:31|not_in:0',
            'start_date' => 'sometimes|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'task_type' => 'nullable|in:individual,group',
            'response_type' => 'nullable|in:notification,completion,completion_with_proof',
            'priority' => 'nullable|in:low,medium,high',
            'tags' => 'nullable|array',
            'notification_settings' => 'nullable|array',
            'assignments' => 'sometimes|array|min:1',
            'assignments.*' => 'exists:users,id',
        ]);

        // Валидация типа задачи и количества исполнителей
        $taskType = $validated['task_type'] ?? $generator->task_type;

        // Определяем количество исполнителей
        if (isset($validated['assignments'])) {
            $assignmentCount = count($validated['assignments']);
        } else {
            $assignmentCount = $generator->assignments()->count();
        }

        if ($taskType === 'group' && $assignmentCount === 0) {
            return response()->json([
                'success' => false,
                'message' => 'Для групповой задачи необходимо указать хотя бы одного исполнителя',
                'errors' => ['assignments' => ['Для групповой задачи необходимо указать хотя бы одного исполнителя']],
            ], 422);
        }

        if ($taskType === 'individual' && $assignmentCount > 1) {
            return response()->json([
                'success' => false,
                'message' => 'Индивидуальная задача не может иметь более одного исполнителя',
                'errors' => ['task_type' => ['Индивидуальная задача не может иметь более одного исполнителя. Используйте групповую задачу для нескольких исполнителей.']],
            ], 422);
        }

        $updateData = [];

        if (isset($validated['title'])) {
            $updateData['title'] = $validated['title'];
        }
        if (array_key_exists('description', $validated)) {
            $updateData['description'] = $validated['description'];
        }
        if (array_key_exists('comment', $validated)) {
            $updateData['comment'] = $validated['comment'];
        }
        if (isset($validated['recurrence'])) {
            $updateData['recurrence'] = $validated['recurrence'];
        }
        if (isset($validated['recurrence_time'])) {
            $updateData['recurrence_time'] = $validated['recurrence_time'] . ':00';
        }
        if (isset($validated['deadline_time'])) {
            $updateData['deadline_time'] = $validated['deadline_time'] . ':00';
        }

        // Handle backwards compatibility for recurrence days
        if (array_key_exists('recurrence_days_of_week', $validated)) {
            $updateData['recurrence_days_of_week'] = $validated['recurrence_days_of_week'];
        } elseif (array_key_exists('recurrence_day_of_week', $validated)) {
            // Convert old single value to array
            $updateData['recurrence_days_of_week'] = $validated['recurrence_day_of_week']
                ? [$validated['recurrence_day_of_week']]
                : null;
        }

        if (array_key_exists('recurrence_days_of_month', $validated)) {
            $updateData['recurrence_days_of_month'] = $validated['recurrence_days_of_month'];
        } elseif (array_key_exists('recurrence_day_of_month', $validated)) {
            // Convert old single value to array
            $updateData['recurrence_days_of_month'] = $validated['recurrence_day_of_month']
                ? [$validated['recurrence_day_of_month']]
                : null;
        }

        if (isset($validated['start_date'])) {
            $updateData['start_date'] = Carbon::parse($validated['start_date'])->setTimezone('UTC');
        }
        if (array_key_exists('end_date', $validated)) {
            $updateData['end_date'] = $validated['end_date']
                ? Carbon::parse($validated['end_date'])->setTimezone('UTC')
                : null;
        }
        if (isset($validated['task_type'])) {
            $updateData['task_type'] = $validated['task_type'];
        }
        if (isset($validated['response_type'])) {
            $updateData['response_type'] = $validated['response_type'];
        }
        if (isset($validated['priority'])) {
            $updateData['priority'] = $validated['priority'];
        }
        if (array_key_exists('tags', $validated)) {
            $updateData['tags'] = $validated['tags'];
        }
        if (array_key_exists('notification_settings', $validated)) {
            $updateData['notification_settings'] = $validated['notification_settings'];
        }

        $generator->update($updateData);

        // Update assignments if provided
        if (isset($validated['assignments'])) {
            // Remove old assignments
            TaskGeneratorAssignment::where('generator_id', $generator->id)->delete();

            // Create new assignments
            foreach ($validated['assignments'] as $userId) {
                TaskGeneratorAssignment::create([
                    'generator_id' => $generator->id,
                    'user_id' => $userId,
                ]);
            }
        }

        $generator->load(['creator', 'dealership', 'assignments.user']);

        return response()->json([
            'success' => true,
            'data' => $generator->toApiArray(),
            'message' => 'Task generator updated successfully',
        ]);
    }

    /**
     * Delete a task generator.
     */
    public function destroy(Request $request, $id)
    {
        $generator = TaskGenerator::findOrFail($id);

        // Проверка доступа к генератору
        if ($accessError = $this->validateGeneratorAccess($request, $generator)) {
            return $accessError;
        }

        $generator->delete();

        return response()->json([
            'success' => true,
            'message' => 'Task generator deleted successfully',
        ]);
    }

    /**
     * Pause a task generator.
     */
    public function pause(Request $request, $id)
    {
        $generator = TaskGenerator::findOrFail($id);

        // Проверка доступа к генератору
        if ($accessError = $this->validateGeneratorAccess($request, $generator)) {
            return $accessError;
        }

        $generator->update(['is_active' => false]);
        $generator->load(['creator', 'dealership', 'assignments.user']);

        return response()->json([
            'success' => true,
            'data' => $generator->toApiArray(),
            'message' => 'Task generator paused',
        ]);
    }

    /**
     * Resume a paused task generator.
     */
    public function resume(Request $request, $id)
    {
        $generator = TaskGenerator::findOrFail($id);

        // Проверка доступа к генератору
        if ($accessError = $this->validateGeneratorAccess($request, $generator)) {
            return $accessError;
        }

        $generator->update(['is_active' => true]);
        $generator->load(['creator', 'dealership', 'assignments.user']);

        return response()->json([
            'success' => true,
            'data' => $generator->toApiArray(),
            'message' => 'Task generator resumed',
        ]);
    }

    /**
     * Pause all active task generators (Owner only).
     * Для не-owner фильтрует по доступным дилерствам.
     */
    public function pauseAll(Request $request)
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $query = TaskGenerator::where('is_active', true);

        // Не-owner может остановить только генераторы своих дилерств
        if (!$this->isOwner($user)) {
            $query->whereIn('dealership_id', $this->getAccessibleDealershipIds($user));
        }

        $count = $query->update(['is_active' => false]);

        return response()->json([
            'success' => true,
            'message' => "Остановлено генераторов: {$count}",
            'paused_count' => $count,
        ]);
    }

    /**
     * Resume all paused task generators (Owner only).
     * Для не-owner фильтрует по доступным дилерствам.
     */
    public function resumeAll(Request $request)
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $query = TaskGenerator::where('is_active', false);

        // Не-owner может запустить только генераторы своих дилерств
        if (!$this->isOwner($user)) {
            $query->whereIn('dealership_id', $this->getAccessibleDealershipIds($user));
        }

        $count = $query->update(['is_active' => true]);

        return response()->json([
            'success' => true,
            'message' => "Запущено генераторов: {$count}",
            'resumed_count' => $count,
        ]);
    }

    /**
     * Get tasks generated by this generator.
     */
    public function generatedTasks(Request $request, $id)
    {
        $generator = TaskGenerator::findOrFail($id);

        // Проверка доступа к генератору
        if ($accessError = $this->validateGeneratorAccess($request, $generator)) {
            return $accessError;
        }

        $query = $generator->generatedTasks()
            ->with(['creator', 'dealership', 'assignments.user', 'responses']);

        // Filter by archived status
        if ($request->has('archived')) {
            $archived = filter_var($request->archived, FILTER_VALIDATE_BOOLEAN);
            if ($archived) {
                $query->whereNotNull('archived_at');
            } else {
                $query->whereNull('archived_at');
            }
        }

        $perPage = min($request->get('per_page', 15), 100);
        $tasks = $query->orderBy('scheduled_date', 'desc')->paginate($perPage);

        // Transform data
        $tasks->getCollection()->transform(fn($t) => $t->toApiArray());

        return response()->json($tasks);
    }

    /**
     * Get statistics for a task generator.
     *
     * Returns statistics for all time, week, month, and year periods.
     */
    public function statistics(Request $request, $id)
    {
        $generator = TaskGenerator::findOrFail($id);

        // Проверка доступа к генератору
        if ($accessError = $this->validateGeneratorAccess($request, $generator)) {
            return $accessError;
        }

        $allTime = $this->getStatsForPeriod($generator, null);
        $week = $this->getStatsForPeriod($generator, 7);
        $month = $this->getStatsForPeriod($generator, 30);
        $year = $this->getStatsForPeriod($generator, 365);

        // Calculate average completion time (in minutes)
        $avgCompletionTime = $this->calculateAverageCompletionTime($generator);

        return response()->json([
            'success' => true,
            'data' => [
                'generator_id' => $generator->id,
                'all_time' => $allTime,
                'week' => $week,
                'month' => $month,
                'year' => $year,
                'average_completion_time_minutes' => $avgCompletionTime,
            ],
        ]);
    }

    /**
     * Get statistics for a specific period.
     *
     * Counts tasks based on their actual status:
     * - Completed: archived with reason 'completed' OR active with 'completed' response status
     * - Expired: archived with reason 'expired' OR active but past deadline without completion
     * - Pending: active tasks not yet completed or expired
     */
    private function getStatsForPeriod(TaskGenerator $generator, ?int $days): array
    {
        $query = $generator->generatedTasks()->with(['responses', 'assignments']);

        if ($days !== null) {
            $startDate = Carbon::now()->subDays($days)->startOfDay();
            $query = $query->where('scheduled_date', '>=', $startDate);
        }

        $tasksInPeriod = (clone $query)->get();

        $totalGenerated = $tasksInPeriod->count();

        $completedCount = 0;
        $expiredCount = 0;
        $pendingCount = 0;
        $onTimeCount = 0;

        foreach ($tasksInPeriod as $task) {
            // Get the calculated status from the Task model (uses responses)
            $status = $task->status;

            if ($task->archived_at !== null) {
                // For archived tasks, use archive_reason
                if ($task->archive_reason === 'completed') {
                    $completedCount++;
                    // Check if completed on time
                    if ($task->deadline && Carbon::parse($task->archived_at)->lte(Carbon::parse($task->deadline))) {
                        $onTimeCount++;
                    }
                } elseif ($task->archive_reason === 'expired') {
                    $expiredCount++;
                } else {
                    // Other archive reasons (manual, etc.) - count as pending for statistics
                    $pendingCount++;
                }
            } else {
                // For active tasks, use the calculated status
                if ($status === 'completed') {
                    $completedCount++;
                    // Check if completed on time - find the completion response time
                    $completedResponse = $task->responses->where('status', 'completed')->sortByDesc('responded_at')->first();
                    if ($completedResponse && $task->deadline) {
                        if (Carbon::parse($completedResponse->responded_at)->lte(Carbon::parse($task->deadline))) {
                            $onTimeCount++;
                        }
                    }
                } elseif ($status === 'overdue') {
                    $expiredCount++;
                } else {
                    // pending, acknowledged, pending_review - all count as pending
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
     * Calculate average completion time in minutes.
     *
     * Considers both archived completed tasks and active tasks with completed responses.
     */
    private function calculateAverageCompletionTime(TaskGenerator $generator): ?float
    {
        $tasks = $generator->generatedTasks()
            ->with(['responses'])
            ->whereNotNull('appear_date')
            ->get();

        if ($tasks->isEmpty()) {
            return null;
        }

        $totalMinutes = 0;
        $count = 0;

        foreach ($tasks as $task) {
            $appearDate = Carbon::parse($task->appear_date);
            $completedAt = null;

            // Check if archived with completed reason
            if ($task->archived_at !== null && $task->archive_reason === 'completed') {
                $completedAt = Carbon::parse($task->archived_at);
            } else {
                // Check for completed response
                $completedResponse = $task->responses->where('status', 'completed')->sortByDesc('responded_at')->first();
                if ($completedResponse) {
                    $completedAt = Carbon::parse($completedResponse->responded_at);
                }
            }

            if ($completedAt) {
                $minutes = $appearDate->diffInMinutes($completedAt);

                // Sanity check - if completion time is negative or extremely long, skip
                if ($minutes > 0 && $minutes < 60 * 24 * 7) { // Less than a week
                    $totalMinutes += $minutes;
                    $count++;
                }
            }
        }

        return $count > 0 ? round($totalMinutes / $count, 2) : null;
    }
}

