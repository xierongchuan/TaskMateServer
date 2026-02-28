<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DelegationStatus;
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
}
