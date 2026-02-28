<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Helpers\TimeHelper;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskDelegationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'task_id' => $this->task_id,
            'from_user_id' => $this->from_user_id,
            'to_user_id' => $this->to_user_id,
            'status' => $this->status->value,
            'reason' => $this->reason,
            'rejection_reason' => $this->rejection_reason,
            'responded_at' => TimeHelper::toIsoZulu($this->responded_at),
            'cancelled_by' => $this->cancelled_by,
            'created_at' => TimeHelper::toIsoZulu($this->created_at),
            'updated_at' => TimeHelper::toIsoZulu($this->updated_at),
        ];

        if ($this->relationLoaded('fromUser') && $this->fromUser) {
            $data['from_user'] = [
                'id' => $this->fromUser->id,
                'full_name' => $this->fromUser->full_name,
            ];
        }

        if ($this->relationLoaded('toUser') && $this->toUser) {
            $data['to_user'] = [
                'id' => $this->toUser->id,
                'full_name' => $this->toUser->full_name,
            ];
        }

        if ($this->relationLoaded('task') && $this->task) {
            $data['task'] = [
                'id' => $this->task->id,
                'title' => $this->task->title,
                'deadline' => TimeHelper::toIsoZulu($this->task->deadline),
                'priority' => $this->task->priority,
            ];
        }

        return $data;
    }
}
