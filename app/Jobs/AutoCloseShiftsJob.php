<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ShiftStatus;
use App\Helpers\TimeHelper;
use App\Models\AutoDealership;
use App\Models\Shift;
use App\Services\SettingsService;
use App\Services\ShiftService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Авто-закрытие смен после окончания запланированного времени.
 *
 * Для каждого дилерства с включённой настройкой auto_close_shifts
 * находит открытые смены, у которых scheduled_end уже прошёл,
 * и закрывает их без фото.
 */
class AutoCloseShiftsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60;
    public int $uniqueFor = 300;

    public function __construct()
    {
        $this->onQueue('shift_auto_close');
    }

    public function handle(SettingsService $settingsService, ShiftService $shiftService): void
    {
        $now = TimeHelper::nowUtc();
        Log::info('AutoCloseShiftsJob started', ['time_utc' => $now->toIso8601ZuluString()]);

        $closedCount = 0;

        $dealershipIds = AutoDealership::pluck('id');

        foreach ($dealershipIds as $dealershipId) {
            $autoClose = (bool) $settingsService->getSettingWithFallback(
                'auto_close_shifts',
                $dealershipId,
                false
            );

            if (!$autoClose) {
                continue;
            }

            $openShifts = Shift::where('dealership_id', $dealershipId)
                ->whereIn('status', ShiftStatus::activeStatusValues())
                ->whereNull('shift_end')
                ->where('scheduled_end', '<=', $now)
                ->get();

            foreach ($openShifts as $shift) {
                try {
                    $shiftService->closeShiftWithoutPhoto($shift, ShiftStatus::CLOSED->value);
                    $closedCount++;

                    Log::info('Auto-closed shift', [
                        'shift_id' => $shift->id,
                        'user_id' => $shift->user_id,
                        'dealership_id' => $dealershipId,
                        'scheduled_end' => $shift->scheduled_end->toIso8601ZuluString(),
                    ]);
                } catch (\Throwable $e) {
                    Log::error('Failed to auto-close shift', [
                        'shift_id' => $shift->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        Log::info('AutoCloseShiftsJob completed', ['closed_count' => $closedCount]);
    }
}
