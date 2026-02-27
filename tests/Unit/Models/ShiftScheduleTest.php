<?php

declare(strict_types=1);

use App\Models\AutoDealership;
use App\Models\Shift;
use App\Models\ShiftSchedule;

function makeSchedule(array $attrs = []): ShiftSchedule
{
    $dealership = AutoDealership::factory()->create();

    return ShiftSchedule::create(array_merge([
        'dealership_id' => $dealership->id,
        'name' => fake()->unique()->word(),
        'sort_order' => 0,
        'start_time' => '09:00',
        'end_time' => '18:00',
        'is_active' => true,
    ], $attrs));
}

describe('ShiftSchedule Model', function () {

    describe('crossesMidnight', function () {
        it('returns false for regular day shift', function () {
            $s = makeSchedule(['start_time' => '09:00', 'end_time' => '18:00']);
            expect($s->crossesMidnight())->toBeFalse();
        });

        it('returns true for night shift crossing midnight', function () {
            $s = makeSchedule(['start_time' => '22:00', 'end_time' => '06:00']);
            expect($s->crossesMidnight())->toBeTrue();
        });

        it('returns false when start is 00:00', function () {
            $s = makeSchedule(['start_time' => '00:00', 'end_time' => '08:00']);
            expect($s->crossesMidnight())->toBeFalse();
        });

        it('returns true for 23:59-00:01', function () {
            $s = makeSchedule(['start_time' => '23:59', 'end_time' => '00:01']);
            expect($s->crossesMidnight())->toBeTrue();
        });
    });

    describe('containsTime', function () {
        it('returns true for time within regular shift', function () {
            $s = makeSchedule(['start_time' => '09:00', 'end_time' => '18:00']);
            expect($s->containsTime('12:30'))->toBeTrue();
        });

        it('returns false for time before regular shift', function () {
            $s = makeSchedule(['start_time' => '09:00', 'end_time' => '18:00']);
            expect($s->containsTime('08:30'))->toBeFalse();
        });

        it('returns false for time after regular shift', function () {
            $s = makeSchedule(['start_time' => '09:00', 'end_time' => '18:00']);
            expect($s->containsTime('18:30'))->toBeFalse();
        });

        it('returns true at exact start time', function () {
            $s = makeSchedule(['start_time' => '09:00', 'end_time' => '18:00']);
            expect($s->containsTime('09:00'))->toBeTrue();
        });

        it('returns false at exact end time (exclusive)', function () {
            $s = makeSchedule(['start_time' => '09:00', 'end_time' => '18:00']);
            expect($s->containsTime('18:00'))->toBeFalse();
        });

        it('returns true for time before midnight in crossing shift', function () {
            $s = makeSchedule(['start_time' => '22:00', 'end_time' => '06:00']);
            expect($s->containsTime('23:30'))->toBeTrue();
        });

        it('returns true for time after midnight in crossing shift', function () {
            $s = makeSchedule(['start_time' => '22:00', 'end_time' => '06:00']);
            expect($s->containsTime('02:00'))->toBeTrue();
        });

        it('returns false for daytime in midnight-crossing shift', function () {
            $s = makeSchedule(['start_time' => '22:00', 'end_time' => '06:00']);
            expect($s->containsTime('12:00'))->toBeFalse();
        });

        it('handles HH:MM:SS input from PostgreSQL', function () {
            $s = makeSchedule(['start_time' => '09:00', 'end_time' => '18:00']);
            expect($s->containsTime('12:30:45'))->toBeTrue();
        });

        it('returns true for 00:00 in midnight-crossing shift', function () {
            $s = makeSchedule(['start_time' => '22:00', 'end_time' => '06:00']);
            expect($s->containsTime('00:00'))->toBeTrue();
        });

        it('returns false for end_time of midnight-crossing shift (exclusive)', function () {
            $s = makeSchedule(['start_time' => '22:00', 'end_time' => '06:00']);
            expect($s->containsTime('06:00'))->toBeFalse();
        });
    });

    describe('isNightShift', function () {
        it('returns false for day shift 09-18', function () {
            $s = makeSchedule(['start_time' => '09:00', 'end_time' => '18:00']);
            expect($s->isNightShift())->toBeFalse();
        });

        it('returns true when starts at 20:00', function () {
            $s = makeSchedule(['start_time' => '20:00', 'end_time' => '23:00']);
            expect($s->isNightShift())->toBeTrue();
        });

        it('returns false when starts at 19:59', function () {
            $s = makeSchedule(['start_time' => '19:59', 'end_time' => '23:00']);
            // 19:59 < 20:00, end 23:00 > 06:00, doesn't cross midnight
            expect($s->isNightShift())->toBeFalse();
        });

        it('returns true when ends at 06:00', function () {
            $s = makeSchedule(['start_time' => '04:00', 'end_time' => '06:00']);
            expect($s->isNightShift())->toBeTrue();
        });

        it('returns false when ends at 06:01', function () {
            $s = makeSchedule(['start_time' => '04:00', 'end_time' => '06:01']);
            // end 06:01 > 06:00, start 04:00 < 20:00, doesn't cross midnight
            expect($s->isNightShift())->toBeFalse();
        });

        it('returns true for midnight-crossing shift', function () {
            $s = makeSchedule(['start_time' => '22:00', 'end_time' => '06:00']);
            expect($s->isNightShift())->toBeTrue();
        });

        it('returns true for shift starting at 21:00 ending at 23:00', function () {
            $s = makeSchedule(['start_time' => '21:00', 'end_time' => '23:00']);
            // start 21:00 >= 20:00
            expect($s->isNightShift())->toBeTrue();
        });

        it('returns false for afternoon shift 14-19', function () {
            $s = makeSchedule(['start_time' => '14:00', 'end_time' => '19:00']);
            expect($s->isNightShift())->toBeFalse();
        });
    });

    describe('minutesUntilStart', function () {
        it('calculates minutes for same day future', function () {
            $s = makeSchedule(['start_time' => '09:00', 'end_time' => '18:00']);
            expect($s->minutesUntilStart('08:00'))->toBe(60);
        });

        it('returns 0 at exact start time', function () {
            $s = makeSchedule(['start_time' => '09:00', 'end_time' => '18:00']);
            expect($s->minutesUntilStart('09:00'))->toBe(0);
        });

        it('wraps around midnight when past start', function () {
            $s = makeSchedule(['start_time' => '09:00', 'end_time' => '18:00']);
            // 10:00 → до 09:00 следующего дня = 23*60 = 1380
            expect($s->minutesUntilStart('10:00'))->toBe(1380);
        });

        it('calculates crossing midnight correctly', function () {
            $s = makeSchedule(['start_time' => '02:00', 'end_time' => '10:00']);
            // 23:00 → до 02:00 = 3 часа = 180 мин
            expect($s->minutesUntilStart('23:00'))->toBe(180);
        });

        it('returns 1 minute before start', function () {
            $s = makeSchedule(['start_time' => '09:00', 'end_time' => '18:00']);
            expect($s->minutesUntilStart('08:59'))->toBe(1);
        });
    });

    describe('overlaps', function () {
        it('returns false for non-overlapping regular shifts', function () {
            $d = AutoDealership::factory()->create();
            $a = makeSchedule(['dealership_id' => $d->id, 'start_time' => '09:00', 'end_time' => '13:00']);
            $b = makeSchedule(['dealership_id' => $d->id, 'start_time' => '14:00', 'end_time' => '18:00']);
            expect($a->overlaps($b))->toBeFalse();
        });

        it('returns true for partially overlapping shifts', function () {
            $d = AutoDealership::factory()->create();
            $a = makeSchedule(['dealership_id' => $d->id, 'start_time' => '09:00', 'end_time' => '14:00']);
            $b = makeSchedule(['dealership_id' => $d->id, 'start_time' => '12:00', 'end_time' => '18:00']);
            expect($a->overlaps($b))->toBeTrue();
        });

        it('returns false for adjacent shifts (end=start)', function () {
            $d = AutoDealership::factory()->create();
            $a = makeSchedule(['dealership_id' => $d->id, 'start_time' => '09:00', 'end_time' => '13:00']);
            $b = makeSchedule(['dealership_id' => $d->id, 'start_time' => '13:00', 'end_time' => '18:00']);
            expect($a->overlaps($b))->toBeFalse();
        });

        it('detects midnight-crossing overlap with regular', function () {
            $d = AutoDealership::factory()->create();
            $a = makeSchedule(['dealership_id' => $d->id, 'start_time' => '22:00', 'end_time' => '06:00']);
            $b = makeSchedule(['dealership_id' => $d->id, 'start_time' => '05:00', 'end_time' => '10:00']);
            expect($a->overlaps($b))->toBeTrue();
        });

        it('returns false for midnight-crossing and daytime shift', function () {
            $d = AutoDealership::factory()->create();
            $a = makeSchedule(['dealership_id' => $d->id, 'start_time' => '22:00', 'end_time' => '06:00']);
            $b = makeSchedule(['dealership_id' => $d->id, 'start_time' => '09:00', 'end_time' => '18:00']);
            expect($a->overlaps($b))->toBeFalse();
        });

        it('detects overlap between two midnight-crossing shifts', function () {
            $d = AutoDealership::factory()->create();
            $a = makeSchedule(['dealership_id' => $d->id, 'start_time' => '22:00', 'end_time' => '06:00']);
            $b = makeSchedule(['dealership_id' => $d->id, 'start_time' => '20:00', 'end_time' => '04:00']);
            expect($a->overlaps($b))->toBeTrue();
        });

        it('returns false for adjacent midnight-crossing shifts', function () {
            $d = AutoDealership::factory()->create();
            $a = makeSchedule(['dealership_id' => $d->id, 'start_time' => '22:00', 'end_time' => '02:00']);
            $b = makeSchedule(['dealership_id' => $d->id, 'start_time' => '02:00', 'end_time' => '08:00']);
            expect($a->overlaps($b))->toBeFalse();
        });
    });

    describe('relationships', function () {
        it('belongs to dealership', function () {
            $d = AutoDealership::factory()->create();
            $s = makeSchedule(['dealership_id' => $d->id]);
            expect($s->dealership->id)->toBe($d->id);
        });

        it('has many shifts', function () {
            $s = makeSchedule();
            Shift::factory()->count(3)->create([
                'dealership_id' => $s->dealership_id,
                'shift_schedule_id' => $s->id,
            ]);
            expect($s->shifts)->toHaveCount(3);
        });
    });

    describe('soft deletes', function () {
        it('soft deletes instead of hard delete', function () {
            $s = makeSchedule();
            $id = $s->id;
            $s->delete();

            expect(ShiftSchedule::find($id))->toBeNull();
            expect(ShiftSchedule::withTrashed()->find($id))->not->toBeNull();
        });

        it('is accessible via belongsTo from shift after soft delete', function () {
            $s = makeSchedule();
            $shift = Shift::factory()->create([
                'dealership_id' => $s->dealership_id,
                'shift_schedule_id' => $s->id,
            ]);
            $s->delete();

            $shift->refresh();
            // belongsTo does NOT filter soft deleted by default
            expect($shift->schedule)->not->toBeNull();
            expect($shift->schedule->id)->toBe($s->id);
        });
    });
});
