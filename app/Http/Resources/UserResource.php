<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = [
            'id'           => $this->id,
            'login'        => $this->login,
            'full_name'    => $this->full_name,
            'role'         => $this->role,
            'phone_number' => $this->phone,
            'dealership_id' => $this->dealership_id,
        ];

        // Include dealership data if loaded
        if ($this->relationLoaded('dealership') && $this->dealership) {
            $data['dealership'] = [
                'id' => $this->dealership->id,
                'name' => $this->dealership->name,
                'address' => $this->dealership->address,
                'phone' => $this->dealership->phone,
                'is_active' => $this->dealership->is_active,
            ];
        }

        // Include dealerships data if loaded (for many-to-many)
        if ($this->relationLoaded('dealerships') && $this->dealerships->isNotEmpty()) {
            $data['dealerships'] = $this->dealerships->map(function ($dealership) {
                return [
                    'id' => $dealership->id,
                    'name' => $dealership->name,
                    'address' => $dealership->address,
                    'phone' => $dealership->phone,
                    'is_active' => $dealership->is_active,
                ];
            });
        }

        return $data;
    }
}
