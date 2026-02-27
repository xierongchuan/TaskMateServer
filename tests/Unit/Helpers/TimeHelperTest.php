<?php

declare(strict_types=1);

use App\Helpers\TimeHelper;
use Carbon\Carbon;

describe('TimeHelper', function () {
    describe('constants', function () {
        it('has correct db timezone', function () {
            expect(TimeHelper::DB_TIMEZONE)->toBe('UTC');
        });
    });

    describe('nowUtc', function () {
        it('returns current time in UTC', function () {
            // Act
            $now = TimeHelper::nowUtc();

            // Assert
            expect($now)->toBeInstanceOf(Carbon::class);
            expect($now->timezone->getName())->toBe('UTC');
        });
    });

    describe('todayUtc', function () {
        it('returns today in UTC timezone', function () {
            // Act
            $today = TimeHelper::todayUtc();

            // Assert
            expect($today)->toBeInstanceOf(Carbon::class);
            expect($today->timezone->getName())->toBe('UTC');
            expect($today->hour)->toBe(0);
            expect($today->minute)->toBe(0);
            expect($today->second)->toBe(0);
        });
    });

    describe('parseIso', function () {
        it('returns null for null input', function () {
            expect(TimeHelper::parseIso(null))->toBeNull();
        });

        it('returns null for empty string', function () {
            expect(TimeHelper::parseIso(''))->toBeNull();
        });

        it('parses ISO 8601 with Z suffix', function () {
            $result = TimeHelper::parseIso('2025-06-15T10:30:00Z');

            expect($result)->toBeInstanceOf(Carbon::class);
            expect($result->timezone->getName())->toBe('UTC');
            expect($result->year)->toBe(2025);
            expect($result->month)->toBe(6);
            expect($result->day)->toBe(15);
            expect($result->hour)->toBe(10);
            expect($result->minute)->toBe(30);
        });

        it('parses ISO 8601 with positive offset', function () {
            // 10:30 UTC+5 = 05:30 UTC
            $result = TimeHelper::parseIso('2025-06-15T10:30:00+05:00');

            expect($result)->toBeInstanceOf(Carbon::class);
            expect($result->timezone->getName())->toBe('UTC');
            expect($result->hour)->toBe(5);
            expect($result->minute)->toBe(30);
        });

        it('parses ISO 8601 with negative offset', function () {
            // 10:30 UTC-5 = 15:30 UTC
            $result = TimeHelper::parseIso('2025-06-15T10:30:00-05:00');

            expect($result)->toBeInstanceOf(Carbon::class);
            expect($result->timezone->getName())->toBe('UTC');
            expect($result->hour)->toBe(15);
            expect($result->minute)->toBe(30);
        });
    });

    describe('toIsoZulu', function () {
        it('returns null for null input', function () {
            expect(TimeHelper::toIsoZulu(null))->toBeNull();
        });

        it('returns ISO 8601 string with Z suffix', function () {
            $date = Carbon::create(2025, 6, 15, 10, 30, 0, 'UTC');
            $result = TimeHelper::toIsoZulu($date);

            expect($result)->toBe('2025-06-15T10:30:00Z');
        });

        it('converts non-UTC timezone to UTC', function () {
            // 15:30 in UTC+5 = 10:30 UTC
            $date = Carbon::create(2025, 6, 15, 15, 30, 0, 'Asia/Yekaterinburg');
            $result = TimeHelper::toIsoZulu($date);

            expect($result)->toBe('2025-06-15T10:30:00Z');
        });
    });

    describe('startOfDayUtc', function () {
        it('returns start of today in UTC by default', function () {
            // Act
            $start = TimeHelper::startOfDayUtc();

            // Assert
            expect($start)->toBeInstanceOf(Carbon::class);
            expect($start->timezone->getName())->toBe('UTC');
            expect($start->hour)->toBe(0);
            expect($start->minute)->toBe(0);
            expect($start->second)->toBe(0);
        });

        it('accepts date string and returns UTC start of that day', function () {
            // Act
            $start = TimeHelper::startOfDayUtc('2025-06-15');

            // Assert
            expect($start->timezone->getName())->toBe('UTC');
            expect($start->toDateString())->toBe('2025-06-15');
            expect($start->hour)->toBe(0);
            expect($start->minute)->toBe(0);
        });

        it('accepts ISO 8601 string with offset', function () {
            // 2025-06-15T10:30:00+05:00 = 2025-06-15T05:30:00Z
            // Start of that day in UTC = 2025-06-15T00:00:00Z
            $start = TimeHelper::startOfDayUtc('2025-06-15T10:30:00+05:00');

            expect($start->timezone->getName())->toBe('UTC');
            expect($start->toDateString())->toBe('2025-06-15');
            expect($start->hour)->toBe(0);
        });

        it('accepts Carbon object', function () {
            // Arrange
            $date = Carbon::create(2025, 7, 20, 15, 30, 0, 'UTC');

            // Act
            $start = TimeHelper::startOfDayUtc($date);

            // Assert
            expect($start->timezone->getName())->toBe('UTC');
            expect($start->toDateString())->toBe('2025-07-20');
            expect($start->hour)->toBe(0);
        });
    });

    describe('endOfDayUtc', function () {
        it('returns end of today in UTC by default', function () {
            // Act
            $end = TimeHelper::endOfDayUtc();

            // Assert
            expect($end)->toBeInstanceOf(Carbon::class);
            expect($end->timezone->getName())->toBe('UTC');
            expect($end->hour)->toBe(23);
            expect($end->minute)->toBe(59);
            expect($end->second)->toBe(59);
        });

        it('accepts date string and returns UTC end of that day', function () {
            // Act
            $end = TimeHelper::endOfDayUtc('2025-06-15');

            // Assert
            expect($end->timezone->getName())->toBe('UTC');
            expect($end->toDateString())->toBe('2025-06-15');
            expect($end->hour)->toBe(23);
            expect($end->minute)->toBe(59);
        });

        it('accepts Carbon object', function () {
            // Arrange
            $date = Carbon::create(2025, 7, 20, 15, 30, 0, 'UTC');

            // Act
            $end = TimeHelper::endOfDayUtc($date);

            // Assert
            expect($end->timezone->getName())->toBe('UTC');
            expect($end->toDateString())->toBe('2025-07-20');
            expect($end->hour)->toBe(23);
        });
    });

    describe('isDeadlinePassed', function () {
        it('returns false for null deadline', function () {
            expect(TimeHelper::isDeadlinePassed(null))->toBeFalse();
        });

        it('returns true for past deadline', function () {
            // Arrange
            $deadline = Carbon::now('UTC')->subHour();

            // Act & Assert
            expect(TimeHelper::isDeadlinePassed($deadline))->toBeTrue();
        });

        it('returns false for future deadline', function () {
            // Arrange
            $deadline = Carbon::now('UTC')->addHour();

            // Act & Assert
            expect(TimeHelper::isDeadlinePassed($deadline))->toBeFalse();
        });
    });

    describe('startOfWeekUtc', function () {
        it('returns start of week in UTC (Monday 00:00)', function () {
            // Act
            $start = TimeHelper::startOfWeekUtc();

            // Assert
            expect($start)->toBeInstanceOf(Carbon::class);
            expect($start->timezone->getName())->toBe('UTC');
            expect($start->dayOfWeekIso)->toBe(1); // Monday
            expect($start->hour)->toBe(0);
            expect($start->minute)->toBe(0);
        });
    });

    describe('endOfWeekUtc', function () {
        it('returns end of week in UTC (Sunday 23:59:59)', function () {
            // Act
            $end = TimeHelper::endOfWeekUtc();

            // Assert
            expect($end)->toBeInstanceOf(Carbon::class);
            expect($end->timezone->getName())->toBe('UTC');
            expect($end->dayOfWeekIso)->toBe(7); // Sunday
            expect($end->hour)->toBe(23);
            expect($end->minute)->toBe(59);
        });
    });

    describe('startOfMonthUtc', function () {
        it('returns start of month in UTC (1st day 00:00)', function () {
            // Act
            $start = TimeHelper::startOfMonthUtc();

            // Assert
            expect($start)->toBeInstanceOf(Carbon::class);
            expect($start->timezone->getName())->toBe('UTC');
            expect($start->day)->toBe(1);
            expect($start->hour)->toBe(0);
            expect($start->minute)->toBe(0);
        });
    });

    describe('endOfMonthUtc', function () {
        it('returns end of month in UTC', function () {
            // Act
            $end = TimeHelper::endOfMonthUtc();

            // Assert
            expect($end)->toBeInstanceOf(Carbon::class);
            expect($end->timezone->getName())->toBe('UTC');
            expect($end->hour)->toBe(23);
            expect($end->minute)->toBe(59);
        });
    });
});
