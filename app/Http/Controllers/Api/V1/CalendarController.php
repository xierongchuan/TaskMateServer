<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\BulkUpdateCalendarRequest;
use App\Http\Requests\Api\V1\UpdateCalendarDayRequest;
use App\Http\Resources\CalendarDayResource;
use App\Models\CalendarDay;
use App\Traits\HasDealershipAccess;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API для управления календарём выходных/рабочих дней.
 */
class CalendarController extends Controller
{
    use HasDealershipAccess;

    /**
     * Получить календарь на год.
     *
     * GET /api/v1/calendar/{year}
     */
    public function index(Request $request, int $year): JsonResponse
    {
        $dealershipId = $request->query('dealership_id') !== null
            ? (int) $request->query('dealership_id')
            : null;

        $usesGlobal = $dealershipId === null
            || ! CalendarDay::hasOwnCalendarForYear($year, $dealershipId);

        $calendar = CalendarDay::getYearCalendar($year, $dealershipId);

        // Группируем по месяцам для удобства
        $grouped = [];
        foreach ($calendar as $day) {
            $month = Carbon::parse($day->date)->month;
            $grouped[$month][] = CalendarDayResource::make($day)->resolve();
        }

        return response()->json([
            'success' => true,
            'data' => [
                'year' => $year,
                'dealership_id' => $dealershipId,
                'uses_global' => $usesGlobal,
                'months' => $grouped,
                'holidays_count' => $calendar->where('type', 'holiday')->count(),
            ],
        ]);
    }

    /**
     * Получить все выходные за год (только даты).
     *
     * GET /api/v1/calendar/{year}/holidays
     */
    public function holidays(Request $request, int $year): JsonResponse
    {
        $dealershipId = $request->query('dealership_id') !== null
            ? (int) $request->query('dealership_id')
            : null;

        $usesGlobal = $dealershipId === null
            || ! CalendarDay::hasOwnCalendarForYear($year, $dealershipId);

        $holidays = CalendarDay::getHolidaysForYear($year, $dealershipId);

        return response()->json([
            'success' => true,
            'data' => [
                'year' => $year,
                'dealership_id' => $dealershipId,
                'uses_global' => $usesGlobal,
                'dates' => $holidays->pluck('date')->map(fn ($d) => $d->toDateString())->values(),
                'count' => $holidays->count(),
            ],
        ]);
    }

    /**
     * Установить/обновить конкретный день.
     *
     * PUT /api/v1/calendar/{date}
     *
     * При первом изменении для автосалона автоматически копируются
     * все глобальные записи за год.
     */
    public function update(UpdateCalendarDayRequest $request, string $date): JsonResponse
    {
        /** @var \App\Models\User $currentUser */
        $currentUser = $request->user();

        $data = $request->validated();
        $carbonDate = Carbon::parse($data['date']);
        $dealershipId = $data['dealership_id'] ?? null;
        $year = (int) $carbonDate->format('Y');

        // Проверка доступа к дилерству, если указан
        if ($dealershipId !== null) {
            if ($accessError = $this->validateDealershipAccess($currentUser, $dealershipId)) {
                return $accessError;
            }
        }

        $copiedFromGlobal = false;

        // При первом изменении для автосалона — копируем глобальные записи
        if ($dealershipId !== null && ! CalendarDay::hasOwnCalendarForYear($year, $dealershipId)) {
            CalendarDay::copyGlobalToDealer($year, $dealershipId);
            $copiedFromGlobal = true;
        }

        $calendarDay = CalendarDay::setDay(
            $carbonDate,
            $data['type'],
            $dealershipId,
            $data['description'] ?? null
        );

        return response()->json([
            'success' => true,
            'message' => 'День календаря обновлён',
            'data' => CalendarDayResource::make($calendarDay)->resolve(),
            'meta' => [
                'copied_from_global' => $copiedFromGlobal,
            ],
        ]);
    }

    /**
     * Удалить настройку дня (вернуть к дефолту — рабочий).
     *
     * DELETE /api/v1/calendar/{date}
     */
    public function destroy(Request $request, string $date): JsonResponse
    {
        /** @var \App\Models\User $currentUser */
        $currentUser = $request->user();
        $dealershipId = $request->query('dealership_id') !== null
            ? (int) $request->query('dealership_id')
            : null;

        // Проверка доступа к дилерству, если указан
        if ($dealershipId !== null) {
            if ($accessError = $this->validateDealershipAccess($currentUser, $dealershipId)) {
                return $accessError;
            }
        }

        try {
            $carbonDate = Carbon::parse($date);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Неверный формат даты',
            ], 422);
        }

        $deleted = CalendarDay::removeDay($carbonDate, $dealershipId);

        return response()->json([
            'success' => true,
            'message' => $deleted ? 'День календаря удалён' : 'Запись не найдена',
            'data' => ['deleted' => $deleted],
        ]);
    }

    /**
     * Массовое обновление дней.
     *
     * POST /api/v1/calendar/bulk
     *
     * Поддерживаемые операции:
     * - set_weekdays: установить все указанные дни недели как выходные/рабочие
     * - set_dates: установить конкретные даты
     * - clear_year: очистить все записи за год
     *
     * При первом изменении для автосалона автоматически копируются
     * все глобальные записи за год (кроме операции clear_year).
     */
    public function bulkUpdate(BulkUpdateCalendarRequest $request): JsonResponse
    {
        /** @var \App\Models\User $currentUser */
        $currentUser = $request->user();

        $data = $request->validated();
        $dealershipId = $data['dealership_id'] ?? null;
        $year = (int) $data['year'];

        // Проверка доступа к дилерству, если указан
        if ($dealershipId !== null) {
            if ($accessError = $this->validateDealershipAccess($currentUser, $dealershipId)) {
                return $accessError;
            }
        }

        $copiedFromGlobal = false;

        // При первом изменении для автосалона — копируем глобальные записи
        // (кроме операции clear_year, которая по сути и есть "сброс")
        if ($dealershipId !== null
            && $data['operation'] !== 'clear_year'
            && ! CalendarDay::hasOwnCalendarForYear($year, $dealershipId)) {
            CalendarDay::copyGlobalToDealer($year, $dealershipId);
            $copiedFromGlobal = true;
        }

        $affectedCount = 0;

        switch ($data['operation']) {
            case 'set_weekdays':
                $affectedCount = CalendarDay::setWeekdaysForYear(
                    $year,
                    $data['weekdays'],
                    $dealershipId,
                    $data['type']
                );
                break;

            case 'set_dates':
                foreach ($data['dates'] as $dateStr) {
                    CalendarDay::setDay(
                        Carbon::parse($dateStr),
                        $data['type'],
                        $dealershipId
                    );
                    $affectedCount++;
                }
                break;

            case 'clear_year':
                $affectedCount = CalendarDay::clearYear($year, $dealershipId);
                break;
        }

        return response()->json([
            'success' => true,
            'message' => "Массовая операция '{$data['operation']}' выполнена",
            'data' => [
                'operation' => $data['operation'],
                'year' => $year,
                'dealership_id' => $dealershipId,
                'affected_count' => $affectedCount,
                'uses_global' => $dealershipId === null
                    || ! CalendarDay::hasOwnCalendarForYear($year, $dealershipId),
            ],
            'meta' => [
                'copied_from_global' => $copiedFromGlobal,
            ],
        ]);
    }

    /**
     * Проверить, является ли дата выходным.
     *
     * GET /api/v1/calendar/check/{date}
     */
    public function check(Request $request, string $date): JsonResponse
    {
        $dealershipId = $request->query('dealership_id') !== null
            ? (int) $request->query('dealership_id')
            : null;

        try {
            $carbonDate = Carbon::parse($date);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Неверный формат даты',
            ], 422);
        }

        $isHoliday = CalendarDay::isHoliday($carbonDate, $dealershipId);

        return response()->json([
            'success' => true,
            'data' => [
                'date' => $carbonDate->toDateString(),
                'is_holiday' => $isHoliday,
                'is_workday' => ! $isHoliday,
                'day_of_week' => $carbonDate->dayOfWeekIso,
                'dealership_id' => $dealershipId,
            ],
        ]);
    }

    /**
     * Сбросить кастомный календарь автосалона к глобальному.
     *
     * DELETE /api/v1/calendar/{year}/reset
     */
    public function resetToGlobal(Request $request, int $year): JsonResponse
    {
        /** @var \App\Models\User $currentUser */
        $currentUser = $request->user();

        $dealershipId = $request->query('dealership_id') !== null
            ? (int) $request->query('dealership_id')
            : null;

        if ($dealershipId === null) {
            return response()->json([
                'success' => false,
                'message' => 'dealership_id обязателен для операции сброса',
            ], 422);
        }

        // Проверка доступа к дилерству
        if ($accessError = $this->validateDealershipAccess($currentUser, $dealershipId)) {
            return $accessError;
        }

        // Проверка наличия кастомного календаря
        if (! CalendarDay::hasOwnCalendarForYear($year, $dealershipId)) {
            return response()->json([
                'success' => true,
                'message' => 'Автосалон уже использует глобальный календарь',
                'data' => [
                    'year' => $year,
                    'dealership_id' => $dealershipId,
                    'deleted_count' => 0,
                ],
            ]);
        }

        $deletedCount = CalendarDay::resetToGlobal($year, $dealershipId);

        return response()->json([
            'success' => true,
            'message' => 'Календарь сброшен к глобальному',
            'data' => [
                'year' => $year,
                'dealership_id' => $dealershipId,
                'deleted_count' => $deletedCount,
            ],
        ]);
    }
}
