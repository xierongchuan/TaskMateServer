<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShiftSchedule extends Model
{
    use HasFactory;
    use Auditable;

    protected $table = 'shift_schedules';

    protected $fillable = [
        'dealership_id',
        'name',
        'sort_order',
        'start_time',
        'end_time',
        'is_active',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Нормализует время к формату HH:MM (без секунд).
     */
    private static function normalizeTime(string $time): string
    {
        $parts = explode(':', $time);

        return sprintf('%02d:%02d', (int) $parts[0], (int) ($parts[1] ?? 0));
    }

    public function getStartTimeNormalized(): string
    {
        return self::normalizeTime($this->start_time);
    }

    public function getEndTimeNormalized(): string
    {
        return self::normalizeTime($this->end_time);
    }

    public function dealership(): BelongsTo
    {
        return $this->belongsTo(AutoDealership::class, 'dealership_id');
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class, 'shift_schedule_id');
    }

    /**
     * Проверяет, пересекает ли смена полночь (например 22:00-06:00).
     */
    public function crossesMidnight(): bool
    {
        $start = $this->getStartTimeNormalized();
        $end = $this->getEndTimeNormalized();

        return $end < $start;
    }

    /**
     * Проверяет, попадает ли указанное время (HH:MM) в интервал смены.
     */
    public function containsTime(string $time): bool
    {
        $time = self::normalizeTime($time);
        $start = $this->getStartTimeNormalized();
        $end = $this->getEndTimeNormalized();

        if ($this->crossesMidnight()) {
            // 22:00-06:00 → время >= 22:00 ИЛИ время < 06:00
            return $time >= $start || $time < $end;
        }

        // 09:00-18:00 → время >= 09:00 И время < 18:00
        return $time >= $start && $time < $end;
    }

    /**
     * Определяет, является ли смена ночной.
     * Ночная = начинается >= 20:00, или заканчивается <= 06:00, или пересекает полночь.
     * start_time/end_time хранятся в локальном времени автосалона.
     */
    public function isNightShift(): bool
    {
        $start = $this->timeToMinutes($this->getStartTimeNormalized());
        $end = $this->timeToMinutes($this->getEndTimeNormalized());

        $nightStart = 20 * 60; // 1200
        $nightEnd = 6 * 60;    // 360

        return $this->crossesMidnight() || $start >= $nightStart || ($end > 0 && $end <= $nightEnd);
    }

    /**
     * Вычисляет количество минут от указанного времени до начала смены.
     * Учитывает переход через полночь.
     *
     * @return int Минуты до начала (всегда положительное число, максимум 1440)
     */
    public function minutesUntilStart(string $time): int
    {
        $currentMinutes = $this->timeToMinutes(self::normalizeTime($time));
        $startMinutes = $this->timeToMinutes($this->getStartTimeNormalized());

        $diff = $startMinutes - $currentMinutes;

        if ($diff < 0) {
            $diff += 1440; // 24 * 60
        }

        return $diff;
    }

    /**
     * Проверяет, пересекается ли эта смена с другой.
     */
    public function overlaps(self $other): bool
    {
        // Преобразуем оба интервала в минуты и проверяем пересечение
        $a = $this->getMinuteIntervals();
        $b = $other->getMinuteIntervals();

        foreach ($a as [$aStart, $aEnd]) {
            foreach ($b as [$bStart, $bEnd]) {
                if ($aStart < $bEnd && $bStart < $aEnd) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Возвращает интервалы в минутах (0-1440).
     * Если смена пересекает полночь — возвращает два интервала.
     *
     * @return array<array{int, int}>
     */
    private function getMinuteIntervals(): array
    {
        $start = $this->timeToMinutes($this->start_time);
        $end = $this->timeToMinutes($this->end_time);

        if ($end <= $start) {
            // Пересечение полуночи: [start, 1440) + [0, end)
            return [[$start, 1440], [0, $end]];
        }

        return [[$start, $end]];
    }

    private function timeToMinutes(string $time): int
    {
        $parts = explode(':', $time);

        return (int) $parts[0] * 60 + (int) ($parts[1] ?? 0);
    }
}
