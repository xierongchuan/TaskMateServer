<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DealershipResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'address' => $this->address,
            'phone' => $this->phone,
            'timezone' => $this->timezone,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'users' => $this->whenLoaded('users'),
            'shifts' => $this->whenLoaded('shifts'),
            'tasks' => $this->whenLoaded('tasks'),
            'important_links' => $this->whenLoaded('importantLinks'),
            'shift_schedules' => $this->whenLoaded('shiftSchedules'),
        ];
    }
}
