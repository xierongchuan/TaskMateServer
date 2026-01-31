<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Миграция данных из settings (shift_1_*, shift_2_*) в таблицу shift_schedules.
 *
 * Для каждого автосалона создаёт записи расписания смен на основе текущих настроек.
 * Если настроек нет — создаёт дефолтную смену 09:00-18:00.
 */
return new class extends Migration
{
    public function up(): void
    {
        $dealerships = DB::table('auto_dealerships')->get();
        $now = now();

        foreach ($dealerships as $dealership) {
            $shift1Start = $this->getSetting('shift_1_start_time', $dealership->id) ?? '09:00';
            $shift1End = $this->getSetting('shift_1_end_time', $dealership->id) ?? '18:00';

            // Всегда создаём первую смену
            DB::table('shift_schedules')->insert([
                'dealership_id' => $dealership->id,
                'name' => 'Смена 1',
                'sort_order' => 0,
                'start_time' => $shift1Start,
                'end_time' => $shift1End,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // Вторую смену создаём только если она была явно настроена
            $shift2Start = $this->getSetting('shift_2_start_time', $dealership->id);
            $shift2End = $this->getSetting('shift_2_end_time', $dealership->id);

            if ($shift2Start !== null && $shift2End !== null) {
                DB::table('shift_schedules')->insert([
                    'dealership_id' => $dealership->id,
                    'name' => 'Смена 2',
                    'sort_order' => 1,
                    'start_time' => $shift2Start,
                    'end_time' => $shift2End,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('shift_schedules')->truncate();
    }

    private function getSetting(string $key, int $dealershipId): ?string
    {
        // Сначала ищем настройку для конкретного автосалона
        $setting = DB::table('settings')
            ->where('key', $key)
            ->where('dealership_id', $dealershipId)
            ->first();

        if ($setting) {
            return $setting->value;
        }

        // Fallback на глобальную настройку
        $global = DB::table('settings')
            ->where('key', $key)
            ->whereNull('dealership_id')
            ->first();

        return $global?->value;
    }
};
