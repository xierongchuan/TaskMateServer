<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DelegationStatus;
use App\Helpers\TimeHelper;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Модель запроса на делегирование задачи.
 *
 * @property int $id
 * @property int $task_id
 * @property int $from_user_id
 * @property int $to_user_id
 * @property DelegationStatus $status
 * @property string|null $reason
 * @property string|null $rejection_reason
 * @property \Carbon\Carbon|null $responded_at
 * @property int|null $cancelled_by
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class TaskDelegation extends Model
{
    use HasFactory;

    protected $table = 'task_delegations';

    protected $fillable = [
        'task_id',
        'from_user_id',
        'to_user_id',
        'status',
        'reason',
        'rejection_reason',
        'responded_at',
        'cancelled_by',
    ];

    protected $casts = [
        'status' => DelegationStatus::class,
        'responded_at' => 'datetime',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id');
    }

    public function fromUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    public function toUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function isPending(): bool
    {
        return $this->status === DelegationStatus::PENDING;
    }

    /**
     * Сериализация для API ответа.
     *
     * @return array<string, mixed>
     */
    public function toApiArray(): array
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
