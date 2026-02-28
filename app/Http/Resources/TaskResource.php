<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Helpers\TimeHelper;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);

        // Add calculated status
        $data['status'] = $this->status;

        // All datetime fields in UTC with Z suffix (ISO 8601 Zulu)
        $data['appear_date'] = TimeHelper::toIsoZulu($this->appear_date);
        $data['deadline'] = TimeHelper::toIsoZulu($this->deadline);
        $data['archived_at'] = TimeHelper::toIsoZulu($this->archived_at);
        $data['created_at'] = TimeHelper::toIsoZulu($this->created_at);
        $data['updated_at'] = TimeHelper::toIsoZulu($this->updated_at);

        // Responses with user info for group task progress tracking
        if ($this->relationLoaded('responses')) {
            $data['responses'] = TaskResponseResource::collection($this->responses);
        }

        // Общие файлы задачи (для групповых задач с complete_for_all)
        if ($this->relationLoaded('sharedProofs')) {
            $data['shared_proofs'] = TaskSharedProofResource::collection($this->sharedProofs);
        } else {
            $data['shared_proofs'] = [];
        }

        // Делегации задачи
        if ($this->relationLoaded('delegations')) {
            $data['delegations'] = TaskDelegationResource::collection($this->delegations);
        }

        // Completion progress for group tasks
        if ($this->task_type === 'group') {
            $assignments = $this->relationLoaded('assignments') ? $this->assignments : collect();
            $responses = $this->relationLoaded('responses') ? $this->responses : collect();

            $totalAssignees = $assignments->count();
            $completedCount = $responses->where('status', 'completed')->pluck('user_id')->unique()->count();
            $pendingReviewCount = $responses->where('status', 'pending_review')->pluck('user_id')->unique()->count();
            $rejectedCount = $responses->where('status', 'rejected')->pluck('user_id')->unique()->count();
            $pendingCount = max(0, $totalAssignees - $completedCount - $pendingReviewCount - $rejectedCount);

            $data['completion_progress'] = [
                'total_assignees' => $totalAssignees,
                'completed_count' => $completedCount,
                'pending_review_count' => $pendingReviewCount,
                'rejected_count' => $rejectedCount,
                'pending_count' => $pendingCount,
                'percentage' => $totalAssignees > 0 ? (int) round(($completedCount / $totalAssignees) * 100) : 0,
            ];
        }

        return $data;
    }
}
