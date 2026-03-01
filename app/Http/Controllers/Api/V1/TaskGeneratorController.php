<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreTaskGeneratorRequest;
use App\Http\Requests\Api\V1\UpdateTaskGeneratorRequest;
use App\Http\Resources\TaskGeneratorResource;
use App\Http\Resources\TaskResource;
use App\Models\TaskGenerator;
use App\Rules\ValidAssignmentsForTaskType;
use App\Services\TaskGeneratorService;
use App\Traits\ApiResponses;
use App\Traits\HasDealershipAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TaskGeneratorController extends Controller
{
    use ApiResponses, HasDealershipAccess;

    public function __construct(
        private readonly TaskGeneratorService $generatorService,
    ) {}

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
            $escapedSearch = str_replace(['%', '_'], ['\%', '\_'], $request->search);
            $query->where('title', 'ilike', '%'.$escapedSearch.'%');
        }

        // Sorting
        $allowedSortFields = ['created_at', 'title', 'recurrence', 'is_active', 'start_date'];
        $sortField = in_array($request->get('sort_by'), $allowedSortFields, true)
            ? $request->get('sort_by')
            : 'created_at';
        $sortDir = $request->get('sort_dir') === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sortField, $sortDir);

        // Pagination
        $perPage = min($request->get('per_page', 15), 100);
        $generators = $query->paginate($perPage);

        // Transform data
        $generatorsData = $generators->getCollection()->map(fn ($g) => TaskGeneratorResource::make($g)->resolve());

        return response()->json([
            'success' => true,
            'data' => $generatorsData,
            'current_page' => $generators->currentPage(),
            'last_page' => $generators->lastPage(),
            'per_page' => $generators->perPage(),
            'total' => $generators->total(),
            'links' => [
                'first' => $generators->url(1),
                'last' => $generators->url($generators->lastPage()),
                'prev' => $generators->previousPageUrl(),
                'next' => $generators->nextPageUrl(),
            ],
        ]);
    }

    /**
     * Show a single task generator.
     */
    public function show(Request $request, $id)
    {
        $generator = TaskGenerator::with(['creator', 'dealership', 'assignments.user', 'generatedTasks.responses'])
            ->findOrFail($id);

        $this->authorize('view', $generator);

        return response()->json([
            'success' => true,
            'data' => TaskGeneratorResource::make($generator)->resolve(),
        ]);
    }

    /**
     * Create a new task generator.
     */
    public function store(StoreTaskGeneratorRequest $request)
    {
        /** @var \App\Models\User $currentUser */
        $currentUser = $request->user();

        $validated = $request->validated();

        // Проверка доступа к дилерству
        if ($accessError = $this->validateDealershipAccess($currentUser, (int) $validated['dealership_id'])) {
            return $accessError;
        }

        $generator = $this->generatorService->createGenerator($validated, $currentUser);
        $generator->load(['creator', 'dealership', 'assignments.user']);

        return response()->json([
            'success' => true,
            'data' => TaskGeneratorResource::make($generator)->resolve(),
            'message' => 'Генератор задач успешно создан',
        ], 201);
    }

    /**
     * Update a task generator.
     */
    public function update(UpdateTaskGeneratorRequest $request, $id)
    {
        $generator = TaskGenerator::findOrFail($id);

        $this->authorize('update', $generator);

        $validated = $request->validated();

        // Валидация типа задачи и количества исполнителей через переиспользуемое правило
        $taskType = $validated['task_type'] ?? $generator->task_type;
        $assignmentsInput = $validated['assignments'] ?? null;
        $currentCount = ($assignmentsInput === null) ? $generator->assignments()->count() : 0;

        $assignmentsValidator = Validator::make(
            ['assignments' => $assignmentsInput],
            ['assignments' => [new ValidAssignmentsForTaskType($taskType, $currentCount)]]
        );

        if ($assignmentsValidator->fails()) {
            $errors = $assignmentsValidator->errors();
            // Ошибка про индивидуальную задачу относится к task_type
            $assignmentsErrors = $errors->get('assignments');
            $responseErrors = [];
            foreach ($assignmentsErrors as $message) {
                $attribute = str_contains($message, 'Индивидуальная') ? 'task_type' : 'assignments';
                $responseErrors[$attribute][] = $message;
            }

            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $responseErrors,
            ], 422);
        }

        $updated = $this->generatorService->updateGenerator($generator, $validated);
        $updated->load(['creator', 'dealership', 'assignments.user']);

        return response()->json([
            'success' => true,
            'data' => TaskGeneratorResource::make($updated)->resolve(),
            'message' => 'Генератор задач успешно обновлён',
        ]);
    }

    /**
     * Delete a task generator.
     */
    public function destroy(Request $request, $id)
    {
        $generator = TaskGenerator::findOrFail($id);

        $this->authorize('delete', $generator);

        $generator->delete();

        return response()->json([
            'success' => true,
            'message' => 'Генератор задач успешно удалён',
        ]);
    }

    /**
     * Pause a task generator.
     */
    public function pause(Request $request, $id)
    {
        $generator = TaskGenerator::findOrFail($id);

        $this->authorize('pause', $generator);

        $generator->update(['is_active' => false]);
        $generator->load(['creator', 'dealership', 'assignments.user']);

        return response()->json([
            'success' => true,
            'data' => TaskGeneratorResource::make($generator)->resolve(),
            'message' => 'Генератор задач приостановлен',
        ]);
    }

    /**
     * Resume a paused task generator.
     */
    public function resume(Request $request, $id)
    {
        $generator = TaskGenerator::findOrFail($id);

        $this->authorize('resume', $generator);

        $generator->update(['is_active' => true]);
        $generator->load(['creator', 'dealership', 'assignments.user']);

        return response()->json([
            'success' => true,
            'data' => TaskGeneratorResource::make($generator)->resolve(),
            'message' => 'Генератор задач возобновлён',
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
        if (! $this->isOwner($user)) {
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
        if (! $this->isOwner($user)) {
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

        $this->authorize('viewGeneratedTasks', $generator);

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
        $tasksData = $tasks->getCollection()->map(fn ($t) => TaskResource::make($t)->resolve());

        return response()->json([
            'success' => true,
            'data' => $tasksData,
            'current_page' => $tasks->currentPage(),
            'last_page' => $tasks->lastPage(),
            'per_page' => $tasks->perPage(),
            'total' => $tasks->total(),
            'links' => [
                'first' => $tasks->url(1),
                'last' => $tasks->url($tasks->lastPage()),
                'prev' => $tasks->previousPageUrl(),
                'next' => $tasks->nextPageUrl(),
            ],
        ]);
    }

    /**
     * Get statistics for a task generator.
     *
     * Returns statistics for all time, week, month, and year periods.
     */
    public function statistics(Request $request, $id)
    {
        $generator = TaskGenerator::findOrFail($id);

        $this->authorize('viewStatistics', $generator);

        return response()->json([
            'success' => true,
            'data' => $this->generatorService->getStatistics($generator),
        ]);
    }
}
