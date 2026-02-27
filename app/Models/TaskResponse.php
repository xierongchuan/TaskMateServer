<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Модель ответа на задачу.
 *
 * @property int $id
 * @property int $task_id
 * @property int $user_id
 * @property int|null $shift_id
 * @property string $status
 * @property string|null $comment
 * @property \Carbon\Carbon|null $responded_at
 * @property bool $completed_during_shift
 * @property \Carbon\Carbon|null $verified_at
 * @property int|null $verified_by
 * @property string|null $rejection_reason
 * @property int $rejection_count
 * @property string $submission_source
 * @property bool $uses_shared_proofs
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property-read Task $task
 * @property-read User $user
 * @property-read Shift|null $shift
 * @property-read User|null $verifier
 * @property-read \Illuminate\Database\Eloquent\Collection<TaskProof> $proofs
 * @property-read \Illuminate\Database\Eloquent\Collection<TaskVerificationHistory> $verificationHistory
 */
class TaskResponse extends Model
{
    use HasFactory;
    use Auditable;

    protected $table = 'task_responses';

    protected $fillable = [
        'task_id',
        'user_id',
        'shift_id',
        'status',
        'comment',
        'responded_at',
        'completed_during_shift',
        'verified_at',
        'verified_by',
        'rejection_reason',
        'rejection_count',
        'submission_source',
        'uses_shared_proofs',
        'created_at',
    ];

    protected $casts = [
        'responded_at' => 'datetime',
        'verified_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'completed_during_shift' => 'boolean',
        'rejection_count' => 'integer',
        'uses_shared_proofs' => 'boolean',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'shift_id');
    }

    /**
     * Верификатор (менеджер/владелец, одобривший/отклонивший доказательство).
     */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * Доказательства выполнения (файлы).
     */
    public function proofs(): HasMany
    {
        return $this->hasMany(TaskProof::class, 'task_response_id');
    }

    /**
     * История верификации.
     */
    public function verificationHistory(): HasMany
    {
        return $this->hasMany(TaskVerificationHistory::class, 'task_response_id')
            ->orderByDesc('created_at');
    }

    /**
     * Проверить, отклонен ли ответ.
     */
    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    /**
     * Проверить, ожидает ли ответ проверки.
     */
    public function isPendingReview(): bool
    {
        return $this->status === 'pending_review';
    }

    /**
     * Проверить, одобрен ли ответ.
     */
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Проверить, был ли ответ создан через complete_for_all.
     */
    public function wasSharedSubmission(): bool
    {
        return $this->submission_source === 'shared';
    }

    /**
     * Проверить, можно ли переотправить ответ.
     */
    public function canResubmit(): bool
    {
        return $this->status === 'rejected';
    }

    /**
     * Получить эффективные доказательства.
     *
     * Если uses_shared_proofs = true, возвращает shared_proofs задачи.
     * Иначе возвращает индивидуальные proofs этого response.
     *
     * @return \Illuminate\Database\Eloquent\Collection<TaskProof|\App\Models\TaskSharedProof>
     */
    public function getEffectiveProofsAttribute(): \Illuminate\Database\Eloquent\Collection
    {
        if ($this->uses_shared_proofs) {
            return $this->task->sharedProofs ?? collect();
        }

        return $this->proofs ?? collect();
    }
}
