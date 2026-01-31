<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\AutoDealership;
use App\Models\Shift;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * Сидер для создания истории смен.
 *
 * Создаёт реалистичную историю смен для сотрудников:
 * - Закрытые смены за указанный период
 * - Смены с опозданиями
 * - Открытые смены на текущий день
 */
class ShiftSeeder extends Seeder
{
    /**
     * Количество дней истории.
     */
    public static int $historyDays = 30;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('Создание истории смен за ' . self::$historyDays . ' дней...');

        $dealerships = AutoDealership::all();

        if ($dealerships->isEmpty()) {
            $this->command->warn('Автосалоны не найдены. Пропуск создания смен.');
            return;
        }

        foreach ($dealerships as $dealership) {
            $this->createShiftsForDealership($dealership);
        }
    }

    /**
     * Создать смены для автосалона.
     */
    private function createShiftsForDealership(AutoDealership $dealership): void
    {
        $employees = User::where('dealership_id', $dealership->id)
            ->where('role', Role::EMPLOYEE)
            ->get();

        if ($employees->isEmpty()) {
            $this->command->warn("Сотрудники не найдены для {$dealership->name}. Пропуск.");
            return;
        }

        $timezone = $dealership->timezone ?? '+05:00';
        $today = Carbon::today($timezone)->setTimezone('UTC');
        $startDate = $today->copy()->subDays(self::$historyDays);

        $shiftsCreated = 0;
        $lateShifts = 0;

        // Создаём смены за каждый день
        $currentDate = $startDate->copy();
        while ($currentDate->lt($today)) {
            // Пропускаем выходные (суббота и воскресенье)
            if ($currentDate->isWeekend()) {
                $currentDate->addDay();
                continue;
            }

            foreach ($employees as $employee) {
                // 90% вероятность что сотрудник работал в этот день
                if (fake()->boolean(90)) {
                    $shift = $this->createClosedShift($employee, $dealership, $currentDate);
                    $shiftsCreated++;

                    if ($shift->late_minutes > 0) {
                        $lateShifts++;
                    }
                }
            }

            $currentDate->addDay();
        }

        // Создаём открытые смены на сегодня (будний день)
        $openShiftsCreated = 0;
        if (!$today->isWeekend()) {
            foreach ($employees as $employee) {
                // 80% сотрудников работают сегодня
                if (fake()->boolean(80)) {
                    $this->createOpenShift($employee, $dealership, $today);
                    $openShiftsCreated++;
                }
            }
        }

        $this->command->info(" - {$dealership->name}: {$shiftsCreated} закрытых смен, {$lateShifts} с опозданием, {$openShiftsCreated} открытых");
    }

    /**
     * Создать закрытую смену.
     */
    private function createClosedShift(User $employee, AutoDealership $dealership, Carbon $date): Shift
    {
        $timezone = $dealership->timezone ?? '+05:00';

        // Утренняя или вечерняя смена (время в местном timezone → UTC)
        $isMorningShift = fake()->boolean(60);

        if ($isMorningShift) {
            $scheduledStart = $date->copy()->setTimezone($timezone)->setTime(9, 0)->setTimezone('UTC');
            $scheduledEnd = $date->copy()->setTimezone($timezone)->setTime(14, 0)->setTimezone('UTC');
        } else {
            $scheduledStart = $date->copy()->setTimezone($timezone)->setTime(14, 0)->setTimezone('UTC');
            $scheduledEnd = $date->copy()->setTimezone($timezone)->setTime(20, 0)->setTimezone('UTC');
        }

        // Реальное время начала (может быть небольшое отклонение)
        $actualStart = $scheduledStart->copy()->addMinutes(fake()->numberBetween(-5, 15));
        $actualEnd = $scheduledEnd->copy()->addMinutes(fake()->numberBetween(-10, 30));

        // Опоздание = разница между фактическим и запланированным началом (если пришёл позже)
        $lateMinutes = max(0, (int) $scheduledStart->diffInMinutes($actualStart, false));

        return Shift::create([
            'user_id' => $employee->id,
            'dealership_id' => $dealership->id,
            'shift_start' => $actualStart,
            'shift_end' => $actualEnd,
            'opening_photo_path' => 'shifts/demo/opening_' . $employee->id . '_' . $date->format('Ymd') . '.jpg',
            'closing_photo_path' => 'shifts/demo/closing_' . $employee->id . '_' . $date->format('Ymd') . '.jpg',
            'status' => 'closed',
            'shift_type' => $isMorningShift ? 'shift_1' : 'shift_2',
            'late_minutes' => $lateMinutes,
            'scheduled_start' => $scheduledStart,
            'scheduled_end' => $scheduledEnd,
        ]);
    }

    /**
     * Создать открытую смену на сегодня.
     */
    private function createOpenShift(User $employee, AutoDealership $dealership, Carbon $date): Shift
    {
        $timezone = $dealership->timezone ?? '+05:00';

        $scheduledStart = $date->copy()->setTimezone($timezone)->setTime(9, 0)->setTimezone('UTC');
        $scheduledEnd = $date->copy()->setTimezone($timezone)->setTime(18, 0)->setTimezone('UTC');

        // Реальное время начала (небольшое отклонение от расписания)
        $actualStart = $scheduledStart->copy()->addMinutes(fake()->numberBetween(-5, 15));

        // Опоздание = разница между фактическим и запланированным началом (если пришёл позже)
        $lateMinutes = max(0, (int) $scheduledStart->diffInMinutes($actualStart, false));

        return Shift::create([
            'user_id' => $employee->id,
            'dealership_id' => $dealership->id,
            'shift_start' => $actualStart,
            'shift_end' => null,
            'opening_photo_path' => 'shifts/demo/opening_' . $employee->id . '_' . $date->format('Ymd') . '.jpg',
            'closing_photo_path' => null,
            'status' => 'open',
            'shift_type' => 'shift_1',
            'late_minutes' => $lateMinutes,
            'scheduled_start' => $scheduledStart,
            'scheduled_end' => $scheduledEnd,
        ]);
    }
}
