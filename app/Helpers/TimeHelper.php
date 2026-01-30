<?php

declare(strict_types=1);

namespace App\Helpers;

use Carbon\Carbon;

/**
 * Централизованный helper для работы с временем.
 *
 * Вся система работает только с UTC:
 * - API принимает и отдаёт время в UTC (ISO 8601 с Z suffix)
 * - БД хранит время в UTC
 * - Frontend отвечает за конвертацию UTC ↔ локальное время клиента
 */
class TimeHelper
{
    /** Timezone для всех операций */
    public const DB_TIMEZONE = 'UTC';

    /**
     * Текущее время в UTC
     */
    public static function nowUtc(): Carbon
    {
        return Carbon::now(self::DB_TIMEZONE);
    }

    /**
     * Сегодняшняя дата в UTC
     */
    public static function todayUtc(): Carbon
    {
        return Carbon::today(self::DB_TIMEZONE);
    }

    /**
     * Парсинг ISO 8601 строки (с Z или offset) в UTC Carbon
     *
     * @param string|null $datetime ISO 8601 строка (например, "2025-01-27T10:30:00Z")
     */
    public static function parseIso(?string $datetime): ?Carbon
    {
        if ($datetime === null || $datetime === '') {
            return null;
        }

        return Carbon::parse($datetime)->setTimezone(self::DB_TIMEZONE);
    }

    /**
     * Форматирование Carbon в ISO 8601 UTC с Z suffix
     */
    public static function toIsoZulu(?Carbon $datetime): ?string
    {
        if ($datetime === null) {
            return null;
        }

        return $datetime->copy()->setTimezone(self::DB_TIMEZONE)->toIso8601ZuluString();
    }

    /**
     * Начало дня в UTC
     *
     * @param Carbon|string|null $date ISO 8601 строка, Y-m-d строка, или Carbon объект
     */
    public static function startOfDayUtc(Carbon|string|null $date = null): Carbon
    {
        if ($date === null) {
            return Carbon::now(self::DB_TIMEZONE)->startOfDay();
        }

        if ($date instanceof Carbon) {
            return $date->copy()->setTimezone(self::DB_TIMEZONE)->startOfDay();
        }

        // Простая дата (Y-m-d) - интерпретируем напрямую в UTC
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return Carbon::createFromFormat('Y-m-d', $date, self::DB_TIMEZONE)->startOfDay();
        }

        // ISO 8601 с временем/offset - парсим и конвертируем в UTC
        return Carbon::parse($date)->setTimezone(self::DB_TIMEZONE)->startOfDay();
    }

    /**
     * Конец дня в UTC
     *
     * @param Carbon|string|null $date ISO 8601 строка, Y-m-d строка, или Carbon объект
     */
    public static function endOfDayUtc(Carbon|string|null $date = null): Carbon
    {
        if ($date === null) {
            return Carbon::now(self::DB_TIMEZONE)->endOfDay();
        }

        if ($date instanceof Carbon) {
            return $date->copy()->setTimezone(self::DB_TIMEZONE)->endOfDay();
        }

        // Простая дата (Y-m-d) - интерпретируем напрямую в UTC
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return Carbon::createFromFormat('Y-m-d', $date, self::DB_TIMEZONE)->endOfDay();
        }

        // ISO 8601 с временем/offset - парсим и конвертируем в UTC
        return Carbon::parse($date)->setTimezone(self::DB_TIMEZONE)->endOfDay();
    }

    /**
     * Проверка: дедлайн прошёл (сравнение в UTC)
     */
    public static function isDeadlinePassed(?Carbon $deadline): bool
    {
        if ($deadline === null) {
            return false;
        }

        return $deadline->lt(self::nowUtc());
    }

    /**
     * Границы текущего дня в UTC, рассчитанные по timezone автосалона.
     *
     * @param string $timezone Timezone автосалона (например, "Asia/Tashkent", "+05:00")
     * @return array{start: Carbon, end: Carbon}
     */
    public static function dayBoundariesForTimezone(string $timezone): array
    {
        $now = Carbon::now($timezone);

        return [
            'start' => $now->copy()->startOfDay()->setTimezone(self::DB_TIMEZONE),
            'end' => $now->copy()->endOfDay()->setTimezone(self::DB_TIMEZONE),
        ];
    }

    /**
     * Начало недели в UTC (понедельник)
     */
    public static function startOfWeekUtc(): Carbon
    {
        return Carbon::now(self::DB_TIMEZONE)->startOfWeek();
    }

    /**
     * Конец недели в UTC (воскресенье)
     */
    public static function endOfWeekUtc(): Carbon
    {
        return Carbon::now(self::DB_TIMEZONE)->endOfWeek();
    }

    /**
     * Начало месяца в UTC
     */
    public static function startOfMonthUtc(): Carbon
    {
        return Carbon::now(self::DB_TIMEZONE)->startOfMonth();
    }

    /**
     * Конец месяца в UTC
     */
    public static function endOfMonthUtc(): Carbon
    {
        return Carbon::now(self::DB_TIMEZONE)->endOfMonth();
    }
}
