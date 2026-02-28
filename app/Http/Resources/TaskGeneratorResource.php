<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Helpers\TimeHelper;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskGeneratorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);

        // Load tasks with responses for accurate status calculation
        $tasks = $this->generatedTasks()->with('responses')->get();

        // Add statistics
        $data['total_generated'] = $tasks->count();

        $data['completed_count'] = $tasks->filter(function ($task) {
            if ($task->archived_at !== null && $task->archive_reason === 'completed') {
                return true;
            }

            return $task->status === 'completed';
        })->count();

        $data['expired_count'] = $tasks->filter(function ($task) {
            if ($task->archived_at !== null && $task->archive_reason === 'expired') {
                return true;
            }

            return $task->status === 'overdue';
        })->count();

        // All datetime fields in UTC with Z suffix
        $data['start_date'] = TimeHelper::toIsoZulu($this->start_date);
        $data['end_date'] = TimeHelper::toIsoZulu($this->end_date);
        $data['last_generated_at'] = TimeHelper::toIsoZulu($this->last_generated_at);
        $data['created_at'] = TimeHelper::toIsoZulu($this->created_at);
        $data['updated_at'] = TimeHelper::toIsoZulu($this->updated_at);

        return $data;
    }
}
