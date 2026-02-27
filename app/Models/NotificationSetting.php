<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NotificationSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'dealership_id',
        'channel_type',
        'is_enabled',
        'notification_time',
        'notification_day',
        'notification_offset',
        'recipient_roles',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'recipient_roles' => 'array',
    ];

    // Notification channel types
    public const CHANNEL_TASK_ASSIGNED = 'task_assigned';
    public const CHANNEL_TASK_DEADLINE_30MIN = 'task_deadline_30min';
    public const CHANNEL_TASK_OVERDUE = 'task_overdue';
    public const CHANNEL_TASK_HOUR_LATE = 'task_hour_late';
    public const CHANNEL_SHIFT_LATE = 'shift_late';
    public const CHANNEL_TASK_POSTPONED = 'task_postponed';
    public const CHANNEL_SHIFT_REPLACEMENT = 'shift_replacement';
    public const CHANNEL_DAILY_SUMMARY = 'daily_summary';
    public const CHANNEL_WEEKLY_REPORT = 'weekly_report';

    public static function getAllChannelTypes(): array
    {
        return [
            self::CHANNEL_TASK_ASSIGNED,
            self::CHANNEL_TASK_DEADLINE_30MIN,
            self::CHANNEL_TASK_OVERDUE,
            self::CHANNEL_TASK_HOUR_LATE,
            self::CHANNEL_SHIFT_LATE,
            self::CHANNEL_TASK_POSTPONED,
            self::CHANNEL_SHIFT_REPLACEMENT,
            self::CHANNEL_DAILY_SUMMARY,
            self::CHANNEL_WEEKLY_REPORT,
        ];
    }

    public static function getChannelLabel(string $channelType): string
    {
        return match ($channelType) {
            self::CHANNEL_TASK_ASSIGNED => 'Назначение задачи',
            self::CHANNEL_TASK_DEADLINE_30MIN => 'Напоминание за 30 минут',
            self::CHANNEL_TASK_OVERDUE => 'Просрочка задачи',
            self::CHANNEL_TASK_HOUR_LATE => 'Просрочка на час',
            self::CHANNEL_SHIFT_LATE => 'Опоздание на смену',
            self::CHANNEL_TASK_POSTPONED => 'Перенос задачи',
            self::CHANNEL_SHIFT_REPLACEMENT => 'Замещение смены',
            self::CHANNEL_DAILY_SUMMARY => 'Ежедневная сводка',
            self::CHANNEL_WEEKLY_REPORT => 'Еженедельный отчёт',
            default => $channelType,
        };
    }

    /**
     * Check if a notification channel is enabled for a dealership
     */
    public static function isChannelEnabled(int $dealershipId, string $channelType): bool
    {
        $setting = static::where('dealership_id', $dealershipId)
            ->where('channel_type', $channelType)
            ->first();

        // Default to disabled (opt-in) - users must explicitly enable notifications
        return $setting ? $setting->is_enabled : false;
    }

    /**
     * Get notification time for a channel
     */
    public static function getNotificationTime(int $dealershipId, string $channelType): ?string
    {
        $setting = static::where('dealership_id', $dealershipId)
            ->where('channel_type', $channelType)
            ->first();

        return $setting?->notification_time;
    }

    /**
     * Get notification offset (in minutes) for a channel
     */
    public static function getNotificationOffset(int $dealershipId, string $channelType): ?int
    {
        $setting = static::where('dealership_id', $dealershipId)
            ->where('channel_type', $channelType)
            ->first();

        return $setting?->notification_offset;
    }

    /**
     * Get recipient roles for a channel
     */
    public static function getRecipientRoles(int $dealershipId, string $channelType): ?array
    {
        $setting = static::where('dealership_id', $dealershipId)
            ->where('channel_type', $channelType)
            ->first();

        return $setting?->recipient_roles;
    }

    /**
     * Relationship to dealership
     */
    public function dealership()
    {
        return $this->belongsTo(AutoDealership::class, 'dealership_id');
    }
}
