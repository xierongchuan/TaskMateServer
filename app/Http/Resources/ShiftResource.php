<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\Role;
use App\Enums\ShiftStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resource для смены с Bearer-авторизованными URL для фото.
 *
 * Контроль доступа к фото:
 * - Owner/Manager: видят все фото
 * - Employee: видят только свои фото
 * - Observer: не видят фото
 */
class ShiftResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'dealership_id' => $this->dealership_id,
            'shift_schedule_id' => $this->shift_schedule_id,
            'shift_start' => $this->shift_start?->toIso8601String(),
            'shift_end' => $this->shift_end?->toIso8601String(),
            'scheduled_start' => $this->scheduled_start?->toIso8601String(),
            'scheduled_end' => $this->scheduled_end?->toIso8601String(),
            'status' => $this->status,
            'late_minutes' => $this->late_minutes,
            'is_late' => ($this->status === ShiftStatus::LATE->value || $this->late_minutes > 0),
            'archived_tasks_processed' => $this->archived_tasks_processed,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];

        // Include photo URLs only if user has access
        // Используем стабильные URLs с Bearer token авторизацией (не signed URLs)
        $currentUser = $request->user();
        if ($currentUser && $this->canViewPhotos($currentUser)) {
            $data['opening_photo_url'] = $this->generateStablePhotoUrl('opening');
            $data['closing_photo_url'] = $this->generateStablePhotoUrl('closing');
        } else {
            $data['opening_photo_url'] = null;
            $data['closing_photo_url'] = null;
        }

        // Include relations if loaded
        if ($this->relationLoaded('user') && $this->user) {
            $data['user'] = [
                'id' => $this->user->id,
                'full_name' => $this->user->full_name,
            ];
        }

        if ($this->relationLoaded('schedule') && $this->schedule) {
            $data['schedule'] = [
                'id' => $this->schedule->id,
                'name' => $this->schedule->name,
            ];
        }

        if ($this->relationLoaded('dealership') && $this->dealership) {
            $data['dealership'] = [
                'id' => $this->dealership->id,
                'name' => $this->dealership->name,
            ];
        }

        return $data;
    }

    /**
     * Check if user can view shift photos.
     *
     * @param  \App\Models\User  $user
     */
    private function canViewPhotos($user): bool
    {
        // Owner and Manager can view all photos
        if (in_array($user->role, [Role::OWNER, Role::MANAGER], true)) {
            return true;
        }

        // Employee can view only their own shift photos
        if ($user->role === Role::EMPLOYEE) {
            return $this->user_id === $user->id;
        }

        // Observer cannot view photos
        return false;
    }

    /**
     * Генерация стабильного URL для фото смены.
     *
     * Используется Bearer token авторизация вместо signed URL.
     * URL никогда не меняется → стабильная загрузка без race conditions.
     */
    private function generateStablePhotoUrl(string $type): ?string
    {
        $path = $type === 'opening' ? $this->opening_photo_path : $this->closing_photo_path;

        if (! $path) {
            return null;
        }

        // URL без /api/v1 prefix - axios baseURL уже содержит этот prefix
        return "/shift-photos/{$this->id}/{$type}";
    }
}
