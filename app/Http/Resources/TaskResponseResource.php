<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Helpers\TimeHelper;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskResponseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'status' => $this->status,
            'comment' => $this->comment,
            'responded_at' => TimeHelper::toIsoZulu($this->responded_at),
            'verified_at' => TimeHelper::toIsoZulu($this->verified_at),
            'verified_by' => $this->verified_by,
            'rejection_reason' => $this->rejection_reason,
            'rejection_count' => $this->rejection_count ?? 0,
            'submission_source' => $this->submission_source ?? 'individual',
            'uses_shared_proofs' => $this->uses_shared_proofs ?? false,
        ];

        if ($this->relationLoaded('user') && $this->user) {
            $data['user'] = [
                'id' => $this->user->id,
                'full_name' => $this->user->full_name,
            ];
        }

        if ($this->relationLoaded('verifier') && $this->verifier) {
            $data['verifier'] = [
                'id' => $this->verifier->id,
                'full_name' => $this->verifier->full_name,
            ];
        }

        if ($this->relationLoaded('proofs')) {
            $data['proofs'] = TaskProofResource::collection($this->proofs);
        }

        return $data;
    }
}
