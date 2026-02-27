<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Модель для хранения выходных и рабочих дней.
 *
 * Позволяет настраивать календарь как глобально (dealership_id = null),
 * так и для конкретного автосалона.
 *
 * Логика работы:
 * - Если у автосалона НЕТ собственного календаря за год — используется глобальный (fallback)
 * - Если у автосалона ЕСТЬ собственный календарь за год — используется ТОЛЬКО он (без fallback)
 * - При первом изменении календаря автосалона — копируются все глобальные записи за год
 */
class CalendarDay extends Model
{
    use HasFactory;

    protected $table = 'calendar_days';

    protected $fillable = [
        'dealership_id',
        'date',
        'type',
        'description',
    ];

    protected $casts = [
        'date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Связь с автосалоном.
     */
    public function dealership(): BelongsTo
    {
        return $this->belongsTo(AutoDealership::class, 'dealership_id');
    }

    /**
     * Проверяет, есть ли у автосалона собственный календарь за указанный год.
     *
     * @param int $year Год для проверки
     * @param int $dealershipId ID автосалона
     * @return bool true если есть хотя бы одна запись с этим dealership_id за год
     */
    public static function hasOwnCalendarForYear(int $year, int $dealershipId): bool
    {
        $startDate = Carbon::createFromDate($year, 1, 1)->toDateString();
        $endDate = Carbon::createFromDate($year, 12, 31)->toDateString();

        return self::whereBetween('date', [$startDate, $endDate])
            ->where('dealership_id', $dealershipId)
            ->exists();
    }

    /**
     * Копирует все глобальные записи за год для указанного автосалона.
     * Используется при первом изменении календаря автосалоном.
     *
     * @param int $year Год для копирования
     * @param int $dealershipId ID автосалона
     * @return int Количество скопированных записей
     */
    public static function copyGlobalToDealer(int $year, int $dealershipId): int
    {
        $startDate = Carbon::createFromDate($year, 1, 1)->toDateString();
        $endDate = Carbon::createFromDate($year, 12, 31)->toDateString();

        $globalRecords = self::whereBetween('date', [$startDate, $endDate])
            ->whereNull('dealership_id')
            ->get();

        $count = 0;
        $now = Carbon::now();
        $records = [];

        foreach ($globalRecords as $record) {
            $records[] = [
                'dealership_id' => $dealershipId,
                'date' => $record->date->toDateString(),
                'type' => $record->type,
                'description' => $record->description,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $count++;
        }

        if (! empty($records)) {
            foreach (array_chunk($records, 100) as $chunk) {
                DB::table('calendar_days')->insert($chunk);
            }
        }

        return $count;
    }

    /**
     * Удаляет кастомный календарь автосалона за год (возврат к глобальному).
     *
     * @param int $year Год
     * @param int $dealershipId ID автосалона
     * @return int Количество удалённых записей
     */
    public static function resetToGlobal(int $year, int $dealershipId): int
    {
        $startDate = Carbon::createFromDate($year, 1, 1)->toDateString();
        $endDate = Carbon::createFromDate($year, 12, 31)->toDateString();

        return self::whereBetween('date', [$startDate, $endDate])
            ->where('dealership_id', $dealershipId)
            ->delete();
    }

    /**
     * Проверяет, является ли указанная дата выходным днём.
     *
     * Логика:
     * - Если у автосалона ЕСТЬ собственный календарь за год — используется ТОЛЬКО он
     * - Если у автосалона НЕТ собственного календаря — fallback на глобальные настройки
     *
     * ВАЖНО: Дата конвертируется в timezone автосалона перед сравнением.
     * Приоритет timezone: автосалон -> глобальная настройка -> дефолт.
     */
    public static function isHoliday(Carbon $date, ?int $dealershipId = null): bool
    {
        // Получаем timezone через SettingsService (с fallback на глобальный)
        $settingsService = app(\App\Services\SettingsService::class);
        $timezone = $settingsService->getTimezone($dealershipId);

        // Конвертируем UTC в локальный timezone для определения календарной даты
        $localDate = $date->copy()->setTimezone($timezone);
        $dateStr = $localDate->toDateString();
        $year = (int) $localDate->format('Y');

        // Если указан dealership и у него ЕСТЬ собственный календарь за этот год
        if ($dealershipId !== null && self::hasOwnCalendarForYear($year, $dealershipId)) {
            // Используем ТОЛЬКО записи автосалона, без fallback
            $record = self::where('date', $dateStr)
                ->where('dealership_id', $dealershipId)
                ->first();

            // Если записи нет — это рабочий день (нет fallback!)
            return $record !== null && $record->type === 'holiday';
        }

        // Fallback на глобальные настройки (для dealership без своего календаря или dealershipId = null)
        $globalRecord = self::where('date', $dateStr)
            ->whereNull('dealership_id')
            ->first();

        return $globalRecord !== null && $globalRecord->type === 'holiday';
    }

    /**
     * Проверяет, является ли указанная дата рабочим днём.
     */
    public static function isWorkday(Carbon $date, ?int $dealershipId = null): bool
    {
        return !self::isHoliday($date, $dealershipId);
    }

    /**
     * Массовая установка выходных по дням недели.
     *
     * @param int $year Год
     * @param array $weekdays Дни недели (1=Пн, 7=Вс)
     * @param int|null $dealershipId ID автосалона или null для глобальных
     * @param string $type Тип дня: 'holiday' или 'workday'
     */
    public static function setWeekdaysForYear(
        int $year,
        array $weekdays,
        ?int $dealershipId = null,
        string $type = 'holiday'
    ): int {
        $startDate = Carbon::createFromDate($year, 1, 1);
        $endDate = Carbon::createFromDate($year, 12, 31);
        $count = 0;

        $records = [];
        $now = Carbon::now();

        for ($date = $startDate->copy(); $date->lte($endDate); $date->addDay()) {
            if (in_array($date->dayOfWeekIso, $weekdays)) {
                $records[] = [
                    'dealership_id' => $dealershipId,
                    'date' => $date->toDateString(),
                    'type' => $type,
                    'description' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $count++;
            }
        }

        // Используем upsert для добавления/обновления
        if (!empty($records)) {
            foreach (array_chunk($records, 100) as $chunk) {
                DB::table('calendar_days')->upsert(
                    $chunk,
                    ['dealership_id', 'date'],
                    ['type', 'description', 'updated_at']
                );
            }
        }

        return $count;
    }

    /**
     * Получить календарь на год.
     *
     * Логика:
     * - Если у автосалона ЕСТЬ собственный календарь — возвращаем ТОЛЬКО его записи
     * - Если у автосалона НЕТ собственного календаря — возвращаем глобальные записи
     *
     * @param int $year Год
     * @param int|null $dealershipId ID автосалона или null для глобальных
     * @return Collection Коллекция записей CalendarDay
     */
    public static function getYearCalendar(int $year, ?int $dealershipId = null): Collection
    {
        $startDate = Carbon::createFromDate($year, 1, 1)->toDateString();
        $endDate = Carbon::createFromDate($year, 12, 31)->toDateString();

        // Если указан dealership и у него есть собственный календарь
        if ($dealershipId !== null && self::hasOwnCalendarForYear($year, $dealershipId)) {
            // Возвращаем ТОЛЬКО записи автосалона
            return self::whereBetween('date', [$startDate, $endDate])
                ->where('dealership_id', $dealershipId)
                ->orderBy('date')
                ->get();
        }

        // Иначе возвращаем глобальные записи
        return self::whereBetween('date', [$startDate, $endDate])
            ->whereNull('dealership_id')
            ->orderBy('date')
            ->get();
    }

    /**
     * Получить только выходные дни за год.
     */
    public static function getHolidaysForYear(int $year, ?int $dealershipId = null): Collection
    {
        return self::getYearCalendar($year, $dealershipId)
            ->where('type', 'holiday');
    }

    /**
     * Очистить все записи за год.
     */
    public static function clearYear(int $year, ?int $dealershipId = null): int
    {
        $startDate = Carbon::createFromDate($year, 1, 1)->toDateString();
        $endDate = Carbon::createFromDate($year, 12, 31)->toDateString();

        $query = self::whereBetween('date', [$startDate, $endDate]);

        if ($dealershipId !== null) {
            $query->where('dealership_id', $dealershipId);
        } else {
            $query->whereNull('dealership_id');
        }

        return $query->delete();
    }

    /**
     * Устанавливает или удаляет конкретный день.
     */
    public static function setDay(
        Carbon $date,
        string $type,
        ?int $dealershipId = null,
        ?string $description = null
    ): self {
        return self::updateOrCreate(
            [
                'dealership_id' => $dealershipId,
                'date' => $date->toDateString(),
            ],
            [
                'type' => $type,
                'description' => $description,
            ]
        );
    }

    /**
     * Удаляет настройку для конкретного дня (возвращает к дефолту — рабочий).
     */
    public static function removeDay(Carbon $date, ?int $dealershipId = null): bool
    {
        return self::where('date', $date->toDateString())
            ->where('dealership_id', $dealershipId)
            ->delete() > 0;
    }

    /**
     * Конвертация в API массив.
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'date' => $this->date->toDateString(),
            'type' => $this->type,
            'description' => $this->description,
            'dealership_id' => $this->dealership_id,
        ];
    }
}
