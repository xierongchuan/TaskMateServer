<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Traits\HasDealershipAccess;
use Illuminate\Http\Request;
use Carbon\Carbon;
use App\Enums\Role;

class ArchivedTaskController extends Controller
{
    use HasDealershipAccess;

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

        // Filter by dealership with access validation
        if ($request->has('dealership_id')) {
            $dealershipId = (int) $request->dealership_id;
            if ($accessError = $this->validateDealershipAccess($user, $dealershipId)) {
                return $accessError;
            }
            $query->where('dealership_id', $dealershipId);
        } else {
            // Scope by accessible dealerships
            $this->scopeByAccessibleDealerships($query, $user);
        }

        // Filter by archive reason (completed, expired)
        if ($request->has('archive_reason')) {
            $query->where('archive_reason', $request->archive_reason);
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

        // Filter by date range (dates come as ISO 8601 strings)
        if ($request->has('date_from')) {
            $dateFrom = Carbon::parse($request->date_from)->setTimezone('UTC')->startOfDay();
            $query->where('archived_at', '>=', $dateFrom);
        }

        if ($request->has('date_to')) {
            $dateTo = Carbon::parse($request->date_to)->setTimezone('UTC')->endOfDay();
            $query->where('archived_at', '<=', $dateTo);
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
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'ilike', '%' . $search . '%')
                  ->orWhere('description', 'ilike', '%' . $search . '%');
            });
        }

        // Sorting
        $sortField = $request->get('sort_by', 'archived_at');
        $sortDir = $request->get('sort_dir', 'desc');
        $query->orderBy($sortField, $sortDir);

        // Pagination
        $perPage = min($request->get('per_page', 15), 100);
        $tasks = $query->paginate($perPage);

        // Transform data
        $tasks->getCollection()->transform(fn($t) => $t->toApiArray());

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
            'data' => $task->toApiArray(),
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

        $query = Task::with(['creator', 'dealership', 'assignments.user'])
            ->whereNotNull('archived_at');

        // Apply same filters as index with access validation
        if ($request->has('dealership_id')) {
            $dealershipId = (int) $request->dealership_id;
            if ($accessError = $this->validateDealershipAccess($user, $dealershipId)) {
                return $accessError;
            }
            $query->where('dealership_id', $dealershipId);
        } else {
            // Scope by accessible dealerships
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

        $tasks = $query->orderBy('archived_at', 'desc')->get();

        // Generate CSV - dates in UTC ISO format
        $csvContent = "ID,Title,Status,Archive Reason,Archived At,Dealership,Creator,Assignees\n";

        foreach ($tasks as $task) {
            $assignees = $task->assignments->pluck('user.full_name')->implode('; ');
            $archivedAt = $task->archived_at
                ? $task->archived_at->toIso8601ZuluString()
                : '';

            $csvContent .= sprintf(
                "%d,\"%s\",\"%s\",\"%s\",\"%s\",\"%s\",\"%s\",\"%s\"\n",
                $task->id,
                str_replace('"', '""', $task->title),
                $task->status ?? '',
                $task->archive_reason ?? '',
                $archivedAt,
                $task->dealership?->name ?? '',
                $task->creator?->full_name ?? '',
                str_replace('"', '""', $assignees)
            );
        }

        return response($csvContent, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="archived_tasks_' . date('Y-m-d') . '.csv"',
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

        // Filter by dealership with access validation
        if ($request->has('dealership_id')) {
            $dealershipId = (int) $request->dealership_id;
            if ($accessError = $this->validateDealershipAccess($user, $dealershipId)) {
                return $accessError;
            }
            $query->where('dealership_id', $dealershipId);
        } else {
            // Scope by accessible dealerships
            $this->scopeByAccessibleDealerships($query, $user);
        }

        $total = $query->count();
        $completed = (clone $query)->where('archive_reason', 'completed')->count();
        $completedLate = (clone $query)->where('archive_reason', 'completed_late')->count();
        $expired = (clone $query)->whereIn('archive_reason', ['expired', 'expired_after_shift'])->count();

        return response()->json([
            'total' => $total,
            'completed' => $completed,
            'completed_late' => $completedLate,
            'expired' => $expired,
        ]);
    }
}
