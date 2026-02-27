<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model for tracking sent task notifications to prevent duplicates
 *
 * @property int $id
 * @property int $task_id
 * @property int $user_id
 * @property string $notification_type
 * @property \Carbon\Carbon $sent_at
 * @property \Carbon\Carbon $created_at
 */
class TaskNotification extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'task_notifications';

    /**
     * Indicates if the model should be timestamped.
     * We only use created_at, not updated_at
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'task_id',
        'user_id',
        'notification_type',
        'sent_at',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'sent_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    /**
     * Notification types constants
     */
    public const TYPE_UPCOMING_DEADLINE = 'upcoming_deadline';
    public const TYPE_OVERDUE = 'overdue';
    public const TYPE_HOUR_OVERDUE = 'hour_overdue';
    public const TYPE_UNRESPONDED_2H = 'unresponded_2h';
    public const TYPE_UNRESPONDED_6H = 'unresponded_6h';
    public const TYPE_UNRESPONDED_24H = 'unresponded_24h';

    /**
     * Get the task that owns this notification
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * Get the user that received this notification
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Check if a notification was already sent
     */
    public static function wasAlreadySent(int $taskId, int $userId, string $notificationType): bool
    {
        return self::where('task_id', $taskId)
            ->where('user_id', $userId)
            ->where('notification_type', $notificationType)
            ->exists();
    }

    /**
     * Record a sent notification
     */
    public static function recordSent(int $taskId, int $userId, string $notificationType): self
    {
        return self::create([
            'task_id' => $taskId,
            'user_id' => $userId,
            'notification_type' => $notificationType,
            'sent_at' => now(),
        ]);
    }
}
