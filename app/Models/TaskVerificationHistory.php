<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Модель для хранения истории верификации доказательств выполнения задач.
 *
 * @property int $id
 * @property int $task_response_id
 * @property string $action
 * @property int $performed_by
 * @property string|null $reason
 * @property string|null $previous_status
 * @property string $new_status
 * @property int $proof_count
 * @property \Carbon\Carbon $created_at
 *
 * @property-read TaskResponse $taskResponse
 * @property-read User $performer
 */
class TaskVerificationHistory extends Model
{
    use HasFactory;

    /**
     * Отключаем автоматические timestamps Laravel.
     */
    public $timestamps = false;

    /**
     * Имя таблицы.
     */
    protected $table = 'task_verification_history';

    /**
     * Константы действий.
     */
    public const ACTION_SUBMITTED = 'submitted';
    public const ACTION_APPROVED = 'approved';
    public const ACTION_REJECTED = 'rejected';
    public const ACTION_RESUBMITTED = 'resubmitted';

    /**
     * Атрибуты, которые можно массово присваивать.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'task_response_id',
        'action',
        'performed_by',
        'reason',
        'previous_status',
        'new_status',
        'proof_count',
        'created_at',
    ];

    /**
     * Приведение типов атрибутов.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'created_at' => 'datetime',
        'proof_count' => 'integer',
        'task_response_id' => 'integer',
        'performed_by' => 'integer',
    ];

    /**
     * Связь с ответом на задачу.
     */
    public function taskResponse(): BelongsTo
    {
        return $this->belongsTo(TaskResponse::class, 'task_response_id');
    }

    /**
     * Связь с пользователем, выполнившим действие.
     */
    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    /**
     * Получить человекочитаемое название действия.
     */
    public function getActionLabelAttribute(): string
    {
        return match ($this->action) {
            self::ACTION_SUBMITTED => 'Отправлено на проверку',
            self::ACTION_APPROVED => 'Одобрено',
            self::ACTION_REJECTED => 'Отклонено',
            self::ACTION_RESUBMITTED => 'Повторно отправлено',
            default => $this->action,
        };
    }
}
