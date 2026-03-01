<?php

declare(strict_types=1);

namespace App\Models;

use App\Helpers\TimeHelper;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TaskGenerator extends Model
{
    use HasFactory;

    protected $table = 'task_generators';

    protected $fillable = [
        'title',
        'description',
        'comment',
        'creator_id',
        'dealership_id',
        'recurrence',
        'recurrence_time',
        'deadline_time',
        'recurrence_days_of_week',
        'recurrence_days_of_month',
        'start_date',
        'end_date',
        'last_generated_at',
        'task_type',
        'response_type',
        'priority',
        'tags',
        'notification_settings',
        'is_active',
    ];

    protected $casts = [
        'start_date' => 'datetime',
        'end_date' => 'datetime',
        'last_generated_at' => 'datetime',
        'tags' => 'array',
        'notification_settings' => 'array',
        'is_active' => 'boolean',
        'recurrence_days_of_week' => 'array',
        'recurrence_days_of_month' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Mutator for tags to ensure they are stored with unescaped unicode.
     */
    public function setTagsAttribute($value)
    {
        $this->attributes['tags'] = $value ? json_encode($value, JSON_UNESCAPED_UNICODE) : null;
    }

    /**
     * Check if this generator should generate a task today.
     *
     * Logic:
     * 1. Generator must be active
     * 2. Current date must be within start_date and end_date range
     * 3. Today must not be a holiday (from CalendarDay)
     * 4. Task must not have been generated today (prevents duplicates)
     * 5. Current time must be >= recurrence_time (scheduled appear time)
     * 6. Today must match the recurrence pattern (daily/weekly/monthly)
     *
     * All time operations are in UTC.
     *
     * @param  Carbon|null  $now  Current UTC time (defaults to TimeHelper::nowUtc())
     * @param  bool|null  $preloadedIsHoliday  Pre-computed holiday status for the generator's dealership.
     *                                         When provided, skips the CalendarDay::isHoliday() DB call,
     *                                         eliminating N+1 queries in batch processing contexts.
     */
    public function shouldGenerateToday(?Carbon $now = null, ?bool $preloadedIsHoliday = null): bool
    {
        $now = $now ?? TimeHelper::nowUtc();

        // Check if generator is active
        if (! $this->is_active) {
            return false;
        }

        // Check if start_date has passed (all dates in UTC)
        $startDate = $this->start_date->copy()->setTimezone('UTC');
        if ($now->lessThan($startDate->startOfDay())) {
            return false;
        }

        // Check if end_date has passed (if set)
        if ($this->end_date) {
            $endDate = $this->end_date->copy()->setTimezone('UTC');
            if ($now->greaterThan($endDate->endOfDay())) {
                return false;
            }
        }

        // Check if today is a holiday.
        // Use pre-computed value when available (batch processing) to avoid N+1 queries.
        $isHoliday = $preloadedIsHoliday ?? CalendarDay::isHoliday($now, $this->dealership_id);
        if ($isHoliday) {
            return false;
        }

        // Check if already generated today (prevents duplicate generation)
        if ($this->last_generated_at) {
            $lastRun = $this->last_generated_at->copy()->setTimezone('UTC');
            if ($lastRun->isSameDay($now)) {
                return false;
            }
        }

        // Check if scheduled time has arrived (in UTC)
        $scheduledTime = $this->getAppearTimeForDate($now);
        if ($now->lessThan($scheduledTime)) {
            return false;
        }

        // Check recurrence type (day matching)
        return match ($this->recurrence) {
            'daily' => true,
            'weekly' => $this->isWeeklyRunDay($now),
            'monthly' => $this->isMonthlyRunDay($now),
            default => false,
        };
    }

    /**
     * Check if today is one of the selected days for weekly recurrence.
     */
    private function isWeeklyRunDay(Carbon $now): bool
    {
        $days = $this->recurrence_days_of_week ?? [];

        if (empty($days)) {
            return false;
        }

        return in_array($now->dayOfWeekIso, $days, true);
    }

    /**
     * Check if today is one of the selected days for monthly recurrence.
     *
     * Supports:
     * - Positive days (1-31): specific day of month
     * - Negative days (-1, -2): last day, second-to-last day
     * - Fallback: if day doesn't exist in month (e.g., 31 in February),
     *   it falls back to the last valid day of the month
     */
    private function isMonthlyRunDay(Carbon $now): bool
    {
        $days = $this->recurrence_days_of_month ?? [];

        if (empty($days)) {
            return false;
        }

        $currentDay = $now->day;
        $daysInMonth = $now->daysInMonth;

        foreach ($days as $targetDay) {
            if ($targetDay > 0) {
                // Positive day: use fallback to last valid day if needed
                $effectiveDay = min($targetDay, $daysInMonth);
                if ($currentDay === $effectiveDay) {
                    return true;
                }
            } else {
                // Negative day: -1 = last day, -2 = second-to-last, etc.
                $effectiveDay = $daysInMonth + $targetDay + 1;
                if ($effectiveDay > 0 && $currentDay === $effectiveDay) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get the appear time for a date (in UTC).
     * recurrence_time is stored as HH:mm:ss in UTC.
     */
    public function getAppearTimeForDate(Carbon $date): Carbon
    {
        $time = Carbon::createFromFormat('H:i:s', $this->recurrence_time, 'UTC');

        return $date->copy()->setTimezone('UTC')->setTime($time->hour, $time->minute, 0);
    }

    /**
     * Get the deadline time for a date (in UTC).
     * deadline_time is stored as HH:mm:ss in UTC.
     */
    public function getDeadlineTimeForDate(Carbon $date): Carbon
    {
        $time = Carbon::createFromFormat('H:i:s', $this->deadline_time, 'UTC');

        return $date->copy()->setTimezone('UTC')->setTime($time->hour, $time->minute, 0);
    }

    // Relationships

    public function creator()
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function dealership()
    {
        return $this->belongsTo(AutoDealership::class, 'dealership_id');
    }

    public function assignments()
    {
        return $this->hasMany(TaskGeneratorAssignment::class, 'generator_id');
    }

    public function assignedUsers()
    {
        return $this->belongsToMany(User::class, 'task_generator_assignments', 'generator_id', 'user_id')
            ->withTimestamps();
    }

    public function generatedTasks()
    {
        return $this->hasMany(Task::class, 'generator_id');
    }

    // Scopes

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForDealership($query, ?int $dealershipId)
    {
        if ($dealershipId === null) {
            return $query->whereNull('dealership_id');
        }

        return $query->where('dealership_id', $dealershipId);
    }
}
