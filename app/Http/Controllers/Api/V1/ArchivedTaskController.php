<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\TaskResource;
use App\Models\Task;
use App\Traits\HasDealershipAccess;
use Carbon\Carbon;
use Illuminate\Http\Request;
use League\Csv\Writer;

class ArchivedTaskController extends Controller
{
    use HasDealershipAccess;

    /**
     * Применить общие фильтры архивных задач (dealership access, archive_reason, date range).
     */
    private function applyArchiveFilters(Request $request, $query, \App\Models\User $user): ?\Illuminate\Http\JsonResponse
    {
        if ($request->has('dealership_id')) {
            $dealershipId = (int) $request->dealership_id;
            if ($accessError = $this->validateDealershipAccess($user, $dealershipId)) {
                return $accessError;
            }
            $query->where('dealership_id', $dealershipId);
        } else {
            $this->scopeByAccessibleDealerships($query, $user);
        }

        if ($request->has('archive_reason')) {
            $query->where('archive_reason', $request->archive_reason);
        }

        if ($request->has('date_from')) {
            $dateFrom = Carbon::parse($request->date_from)->setTimezone('UTC')->startOfDay();
            $query->where('archived_at', '>=', $dateFrom);
        }

        if ($request->has('date_to')) {
            $dateTo = Carbon::parse($request->date_to)->setTimezone('UTC')->endOfDay();
            $query->where('archived_at', '<=', $dateTo);
        }

        return null;
    }

    /**
     * List archived tasks with filtering.
     */
    public function index(Request $request)
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $query = Task::with([
            'creator',
            'dealership',
            'assignments.user',
            'generator',
            'responses.user',
            'responses.proofs',
            'sharedProofs',
        ])
            ->whereNotNull('archived_at');

        if ($accessError = $this->applyArchiveFilters($request, $query, $user)) {
            return $accessError;
        }

        // Filter by priority
        if ($request->has('priority')) {
            $query->where('priority', $request->priority);
        }

        // Filter by task type
        if ($request->has('task_type')) {
            $query->where('task_type', $request->task_type);
        }

        // Filter by response type
        if ($request->has('response_type')) {
            $query->where('response_type', $request->response_type);
        }

        // Filter by generator
        if ($request->has('generator_id')) {
            $query->where('generator_id', $request->generator_id);
        }

        // Filter by assignee
        if ($request->has('assignee_id')) {
            $query->whereHas('assignments', function ($q) use ($request) {
                $q->where('user_id', $request->assignee_id);
            });
        }

        // Filter by tags
        if ($request->has('tags')) {
            $tags = is_array($request->tags) ? $request->tags : explode(',', $request->tags);
            foreach ($tags as $tag) {
                $query->whereJsonContains('tags', trim($tag));
            }
        }

        // Search by title/description
        if ($request->has('search')) {
            $escapedSearch = str_replace(['%', '_'], ['\%', '\_'], $request->search);
            $query->where(function ($q) use ($escapedSearch) {
                $q->where('title', 'ilike', '%'.$escapedSearch.'%')
                    ->orWhere('description', 'ilike', '%'.$escapedSearch.'%');
            });
        }

        // Sorting
        $allowedSortFields = ['archived_at', 'created_at', 'deadline', 'title', 'priority', 'task_type'];
        $sortField = in_array($request->get('sort_by'), $allowedSortFields, true)
            ? $request->get('sort_by')
            : 'archived_at';
        $sortDir = $request->get('sort_dir') === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sortField, $sortDir);

        // Pagination
        $perPage = min($request->get('per_page', 15), 100);
        $tasks = $query->paginate($perPage);

        // Transform data
        $tasks->getCollection()->transform(fn ($t) => TaskResource::make($t)->resolve());

        return response()->json($tasks);
    }

    /**
     * Restore a task from archive.
     */
    public function restore(Request $request, $id)
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $task = Task::whereNotNull('archived_at')->findOrFail($id);

        // Verify user has access to the task's dealership
        if ($accessError = $this->validateDealershipAccess($user, $task->dealership_id)) {
            return $accessError;
        }

        // Restore the task
        $task->update([
            'is_active' => true,
            'archived_at' => null,
            'archive_reason' => null,
        ]);

        $task->load(['creator', 'dealership', 'assignments.user', 'generator']);

        return response()->json([
            'success' => true,
            'data' => TaskResource::make($task)->resolve(),
            'message' => 'Task restored from archive',
        ]);
    }

    /**
     * Export archived tasks to CSV.
     */
    public function export(Request $request)
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $query = Task::with(['creator', 'dealership', 'assignments.user', 'responses'])
            ->whereNotNull('archived_at');

        if ($accessError = $this->applyArchiveFilters($request, $query, $user)) {
            return $accessError;
        }

        $query->orderBy('archived_at', 'desc');

        // Streaming CSV для экономии памяти при больших объёмах
        return response()->streamDownload(function () use ($query) {
            $csv = Writer::createFromStream(fopen('php://output', 'wb'));
            $csv->insertOne(['ID', 'Title', 'Status', 'Archive Reason', 'Archived At', 'Dealership', 'Creator', 'Assignees']);

            $query->chunk(500, function ($tasks) use ($csv) {
                foreach ($tasks as $task) {
                    $csv->insertOne([
                        $task->id,
                        $task->title,
                        $task->status ?? '',
                        $task->archive_reason ?? '',
                        $task->archived_at?->toIso8601ZuluString() ?? '',
                        $task->dealership?->name ?? '',
                        $task->creator?->full_name ?? '',
                        $task->assignments->pluck('user.full_name')->implode('; '),
                    ]);
                }
            });
        }, 'archived_tasks_'.date('Y-m-d').'.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }

    /**
     * Get archive statistics.
     */
    public function statistics(Request $request)
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $query = Task::whereNotNull('archived_at');

        if ($accessError = $this->applyArchiveFilters($request, $query, $user)) {
            return $accessError;
        }

        $stats = (clone $query)->selectRaw("
            COUNT(*) as total,
            SUM(CASE WHEN archive_reason = 'completed' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN archive_reason = 'completed_late' THEN 1 ELSE 0 END) as completed_late,
            SUM(CASE WHEN archive_reason IN ('expired', 'expired_after_shift') THEN 1 ELSE 0 END) as expired
        ")->first();

        return response()->json([
            'total' => (int) ($stats->total ?? 0),
            'completed' => (int) ($stats->completed ?? 0),
            'completed_late' => (int) ($stats->completed_late ?? 0),
            'expired' => (int) ($stats->expired ?? 0),
        ]);
    }
}
