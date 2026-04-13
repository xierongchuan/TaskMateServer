<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\FileValidatorInterface;
use App\Enums\ShiftStatus;
use App\Exceptions\ScheduleAmbiguousException;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ShiftService
{
    /**
     * Пресет валидации для фото смен.
     */
    private const VALIDATION_PRESET = 'shift_photo';

    /**
     * Диск хранения фото смен.
     */
    private const STORAGE_DISK = 'shift_photos';

    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly FileValidatorInterface $fileValidator,
    ) {}

    /**
     * Open a new shift for a user
     *
     * @throws \InvalidArgumentException
     * @throws ScheduleAmbiguousException
     */
    public function openShift(
        User $user,
        UploadedFile $photo,
        ?User $replacingUser = null,
        ?string $reason = null,
        ?int $dealershipId = null,
        ?int $shiftScheduleId = null,
    ): Shift {
        // Use provided dealershipId or fallback to user's primary dealership
        $dealershipId = $dealershipId ?? $user->dealership_id;

        // Validate user belongs to a dealership
        if (! $dealershipId) {
            throw new \InvalidArgumentException('User must belong to a dealership to open a shift');
        }

        $now = Carbon::now();

        // Определяем расписание смены по текущему времени
        $timezone = $this->settingsService->getTimezone($dealershipId);
        $localNow = $now->copy()->setTimezone($timezone);
        $localTimeStr = $localNow->format('H:i');

        $lateTolerance = $this->settingsService->getLateTolerance($dealershipId);
        $schedule = $this->resolveShiftSchedule($dealershipId, $localTimeStr, $lateTolerance, $shiftScheduleId);

        // Вычисляем scheduled_start и scheduled_end в UTC
        $scheduledStart = $this->scheduleTimeToUtc($localNow, $schedule->start_time, $timezone);
        $scheduledEnd = $this->scheduleTimeToUtc($localNow, $schedule->end_time, $timezone);

        // Если end_time < start_time — смена пересекает полночь, end на следующий день
        if ($schedule->crossesMidnight()) {
            if ($scheduledEnd->lte($scheduledStart)) {
                $scheduledEnd->addDay();
            }
        }

        // Если текущее время после start → late_minutes = разница
        // Если до start (раннее открытие) → late_minutes = 0
        $lateMinutes = 0;
        if ($now->gt($scheduledStart)) {
            $lateMinutes = (int) abs($now->diffInMinutes($scheduledStart));
        }
        $isLate = $lateMinutes > $lateTolerance;

        // Determine shift status
        $status = $isLate ? ShiftStatus::LATE->value : ShiftStatus::OPEN->value;

        // Store photo
        $photoPath = $this->storeShiftPhoto($photo, 'opening', $user->id, $dealershipId);

        try {
            DB::beginTransaction();

            // Проверка существующей открытой смены с блокировкой для предотвращения race condition
            $existingShift = Shift::where('user_id', $user->id)
                ->where('dealership_id', $dealershipId)
                ->whereIn('status', ShiftStatus::activeStatusValues())
                ->lockForUpdate()
                ->first();

            if ($existingShift) {
                DB::rollBack();
                // Clean up photo
                if ($photoPath && Storage::disk(self::STORAGE_DISK)->exists($photoPath)) {
                    Storage::disk(self::STORAGE_DISK)->delete($photoPath);
                }
                throw new \InvalidArgumentException('User already has an open shift in this dealership');
            }

            // Create shift record
            $shift = Shift::create([
                'user_id' => $user->id,
                'dealership_id' => $dealershipId,
                'shift_schedule_id' => $schedule->id,
                'shift_start' => $now,
                'scheduled_start' => $scheduledStart,
                'scheduled_end' => $scheduledEnd,
                'opening_photo_path' => $photoPath,
                'status' => $status,
                'late_minutes' => $lateMinutes,
            ]);

            DB::commit();

            Log::info("Shift opened for user {$user->id} in dealership {$dealershipId}", [
                'shift_id' => $shift->id,
                'schedule' => $schedule->name,
                'status' => $status,
                'late_minutes' => $lateMinutes,
                'is_replacement' => false,
            ]);

            return $shift;
        } catch (\Exception $e) {
            DB::rollBack();

            // Clean up photo if shift creation failed
            if ($photoPath && Storage::disk(self::STORAGE_DISK)->exists($photoPath)) {
                Storage::disk(self::STORAGE_DISK)->delete($photoPath);
            }

            Log::error("Failed to open shift for user {$user->id}", [
                'error' => $e->getMessage(),
                'dealership_id' => $dealershipId,
            ]);

            throw new \InvalidArgumentException('Failed to open shift: '.$e->getMessage());
        }
    }

    /**
     * Close a shift
     *
     * @throws \InvalidArgumentException
     */
    public function closeShift(Shift $shift, UploadedFile $photo): Shift
    {
        if ($shift->status === ShiftStatus::CLOSED->value) {
            throw new \InvalidArgumentException('Shift is already closed');
        }

        $now = Carbon::now();

        // Store photo
        $photoPath = $this->storeShiftPhoto($photo, 'closing', $shift->user_id, $shift->dealership_id);

        try {
            DB::beginTransaction();

            // Update shift record
            $shift->update([
                'shift_end' => $now,
                'closing_photo_path' => $photoPath,
                'status' => ShiftStatus::CLOSED->value,
            ]);

            // Log incomplete tasks
            $this->logIncompleteTasks($shift, $shift->user);

            DB::commit();

            Log::info("Shift closed for user {$shift->user_id}", [
                'shift_id' => $shift->id,
                'duration' => $shift->shift_start->diffInMinutes($now),
            ]);

            return $shift;
        } catch (\Exception $e) {
            DB::rollBack();

            // Clean up photo if shift update failed
            if ($photoPath && Storage::disk(self::STORAGE_DISK)->exists($photoPath)) {
                Storage::disk(self::STORAGE_DISK)->delete($photoPath);
            }

            Log::error("Failed to close shift for user {$shift->user_id}", [
                'error' => $e->getMessage(),
                'shift_id' => $shift->id,
            ]);

            throw new \InvalidArgumentException('Failed to close shift: '.$e->getMessage());
        }
    }

    /**
     * Get user's current open shift
     */
    public function getUserOpenShift(User $user, ?int $dealershipId = null): ?Shift
    {
        $query = Shift::where('user_id', $user->id)->whereIn('status', ShiftStatus::activeStatusValues());

        if ($dealershipId) {
            $query->where('dealership_id', $dealershipId);
        }

        return $query->first();
    }

    /**
     * Get current open shifts for a dealership
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getCurrentShifts(?int $dealershipId = null)
    {
        $query = Shift::with(['user', 'dealership', 'schedule'])
            ->whereIn('status', ShiftStatus::activeStatusValues())
            ->orderBy('shift_start', 'desc');

        if ($dealershipId) {
            $query->where('dealership_id', $dealershipId);
        }

        return $query->get();
    }

    /**
     * Get shift statistics for a dealership and period
     */
    public function getShiftStatistics(
        ?int $dealershipId = null,
        ?Carbon $startDate = null,
        ?Carbon $endDate = null,
    ): array {
        $query = Shift::query();

        if ($dealershipId) {
            $query->where('dealership_id', $dealershipId);
        }

        if ($startDate) {
            $query->where('shift_start', '>=', $startDate);
        }

        if ($endDate) {
            $query->where('shift_start', '<=', $endDate);
        }

        $totalShifts = $query->count();
        $lateShifts = (clone $query)->where('status', ShiftStatus::LATE->value)->count();
        $avgLateMinutes = (clone $query)->whereNotNull('late_minutes')->avg('late_minutes') ?? 0;

        return [
            'total_shifts' => $totalShifts,
            'late_shifts' => $lateShifts,
            'avg_late_minutes' => round($avgLateMinutes, 2),
            'period' => [
                'start' => $startDate?->format('Y-m-d'),
                'end' => $endDate?->format('Y-m-d'),
            ],
        ];
    }

    /**
     * Close shift without photo (for manual close)
     */
    public function closeShiftWithoutPhoto(Shift $shift, string $status): Shift
    {
        $shift->update([
            'shift_end' => Carbon::now(),
            'status' => $status,
        ]);

        // Логируем незавершённые задачи (как в closeShift)
        $shift->load('user');
        $this->logIncompleteTasks($shift, $shift->user);

        return $shift;
    }

    /**
     * Store shift photo with proper path structure
     *
     * @throws \InvalidArgumentException
     */
    private function storeShiftPhoto(UploadedFile $photo, string $type, int $userId, int $dealershipId): string
    {
        // Валидация через FileValidator с пресетом для фото смен
        $this->fileValidator->validate($photo, self::VALIDATION_PRESET);

        $extension = strtolower($photo->getClientOriginalExtension());
        $filename = $type.'_'.time().'_'.$userId.'.'.$extension;
        $path = "dealerships/{$dealershipId}/shifts/{$userId}/".date('Y/m/d');

        return $photo->storeAs($path, $filename, self::STORAGE_DISK);
    }

    /**
     * Возвращает список расписаний, доступных для открытия смены прямо сейчас.
     *
     * @throws \InvalidArgumentException
     */
    public function getAvailableSchedulesForNow(int $dealershipId): Collection
    {
        $timezone = $this->settingsService->getTimezone($dealershipId);
        $localNow = Carbon::now()->setTimezone($timezone);
        $localTimeStr = $localNow->format('H:i');

        $lateTolerance = $this->settingsService->getLateTolerance($dealershipId);

        return $this->resolveAvailableSchedules($dealershipId, $localTimeStr, $lateTolerance);
    }

    /**
     * Определяет расписание смены для текущего локального времени.
     *
     * Логика:
     * 1. Если время попадает в интервал активной смены → эта смена
     * 2. Если не попадает → ищем ближайшую следующую смену в пределах lateTolerance
     * 3. Если нет следующей → ищем смену, завершившуюся ≤ lateTolerance минут назад
     *
     * При нескольких кандидатах и указанном $shiftScheduleId — проверяем принадлежность.
     * При нескольких кандидатах без $shiftScheduleId — бросаем ScheduleAmbiguousException.
     *
     * @throws \InvalidArgumentException
     * @throws ScheduleAmbiguousException
     */
    private function resolveShiftSchedule(
        int $dealershipId,
        string $localTime,
        int $lateTolerance,
        ?int $shiftScheduleId = null,
    ): ShiftSchedule {
        $candidates = $this->resolveAvailableSchedules($dealershipId, $localTime, $lateTolerance);

        if ($candidates->isEmpty()) {
            throw new \InvalidArgumentException('Не удалось определить смену для текущего времени');
        }

        // Если указан конкретный ID расписания — проверяем его наличие среди кандидатов
        if ($shiftScheduleId !== null) {
            $selected = $candidates->firstWhere('id', $shiftScheduleId);
            if (! $selected) {
                throw new \InvalidArgumentException(
                    'Указанное расписание смены недоступно для открытия в текущее время',
                );
            }

            return $selected;
        }

        if ($candidates->count() === 1) {
            return $candidates->first();
        }

        // Несколько кандидатов без явного указания расписания
        throw new ScheduleAmbiguousException(
            'Невозможно автоматически определить смену: несколько расписаний активны одновременно. Укажите shift_schedule_id.',
            $candidates,
        );
    }

    /**
     * Собирает коллекцию расписаний, доступных для открытия смены в указанное время.
     *
     * Фазы (возвращаются кандидаты только ОДНОЙ фазы):
     * Phase 1: все расписания, содержащие localTime (containsTime)
     * Phase 2 (если phase 1 пуста): все расписания, до начала которых ≤ lateTolerance минут
     * Phase 3 (если phase 2 пуста): все расписания, завершившиеся ≤ lateTolerance минут назад
     *
     * @throws \InvalidArgumentException
     */
    private function resolveAvailableSchedules(int $dealershipId, string $localTime, int $lateTolerance): Collection
    {
        $schedules = ShiftSchedule::where('dealership_id', $dealershipId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        if ($schedules->isEmpty()) {
            throw new \InvalidArgumentException('Не настроены смены для автосалона');
        }

        // Phase 1: смены, в интервал которых попадает текущее время
        $phase1 = $schedules->filter(fn (ShiftSchedule $s) => $s->containsTime($localTime))->values();
        if ($phase1->isNotEmpty()) {
            return $phase1;
        }

        // Phase 2: смены, до начала которых ≤ lateTolerance минут (раннее открытие)
        $phase2 = $schedules
            ->filter(function (ShiftSchedule $s) use ($localTime, $lateTolerance) {
                $minutes = $s->minutesUntilStart($localTime);

                // minutesUntilStart возвращает 0-1439; если 0 — именно сейчас начало (but containsTime уже обработало)
                // Отбираем только реально "до начала" (minutes > 0)
                return $minutes > 0 && $minutes <= $lateTolerance;
            })
            ->values();
        if ($phase2->isNotEmpty()) {
            return $phase2;
        }

        // Phase 3: смены, завершившиеся ≤ lateTolerance минут назад
        $phase3 = $schedules
            ->filter(function (ShiftSchedule $s) use ($localTime, $lateTolerance) {
                $endMinutes = $this->timeToMinutes($s->end_time);
                $currentMinutes = $this->timeToMinutes($localTime);
                $diff = $currentMinutes - $endMinutes;

                if ($diff < 0) {
                    $diff += 1440;
                }

                return $diff <= $lateTolerance;
            })
            ->values();

        return $phase3;
    }

    /**
     * Конвертирует локальное время расписания (HH:MM) в UTC Carbon для конкретной даты.
     */
    private function scheduleTimeToUtc(Carbon $localNow, string $time, string $timezone): Carbon
    {
        // Normalize to HH:MM:SS — DB may return HH:MM:SS, input may be HH:MM
        $parts = explode(':', $time);
        $normalized = sprintf('%s:%s:%s', $parts[0], $parts[1] ?? '00', $parts[2] ?? '00');

        return $localNow->copy()->setTimeFromTimeString($normalized)->setTimezone('UTC');
    }

    private function timeToMinutes(string $time): int
    {
        $parts = explode(':', $time);

        return (int) $parts[0] * 60 + (int) ($parts[1] ?? 0);
    }

    /**
     * Log incomplete tasks for a shift
     */
    private function logIncompleteTasks(Shift $shift, User $user): void
    {
        // Get tasks assigned to user that are due during the shift period
        $tasks = Task::where(function ($query) use ($user) {
            $query
                ->whereHas('assignments', function ($q) use ($user) {
                    $q->where('user_id', $user->id);
                })
                ->orWhere('task_type', 'group');
        })
            ->where('dealership_id', $shift->dealership_id)
            ->where('is_active', true)
            ->where(function ($query) use ($shift) {
                $query
                    ->whereBetween('deadline', [$shift->shift_start, $shift->shift_end ?? Carbon::now()])
                    ->orWhereNull('deadline');
            })
            ->whereDoesntHave('responses', function ($q) use ($user) {
                $q->where('user_id', $user->id)->whereIn('status', ['completed', 'acknowledged']);
            })
            ->get();

        foreach ($tasks as $task) {
            Log::info('Incomplete task at shift end', [
                'shift_id' => $shift->id,
                'task_id' => $task->id,
                'user_id' => $user->id,
                'dealership_id' => $shift->dealership_id,
            ]);
        }
    }

    /**
     * Validate user can work with shifts in their dealership
     */
    public function validateUserDealership(User $user, ?int $dealershipId = null): bool
    {
        if (! $dealershipId) {
            return (bool) $user->dealership_id;
        }

        // Check primary dealership
        if ($user->dealership_id === $dealershipId) {
            return true;
        }

        // Allow owners to operate in any dealership
        if ($user->role === \App\Enums\Role::OWNER) {
            return true;
        }

        // Check attached dealerships (many-to-many)
        return $user->dealerships()->where('auto_dealerships.id', $dealershipId)->exists();
    }

    /**
     * Get shifts for a user with dealership context.
     *
     * Когда передан $perPage — возвращает LengthAwarePaginator для постраничной навигации.
     * Без $perPage — возвращает полную коллекцию (обратная совместимость).
     *
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator|\Illuminate\Database\Eloquent\Collection
     */
    public function getUserShifts(User $user, array $filters = [], ?int $perPage = null)
    {
        $query = Shift::where('user_id', $user->id)
            ->where('dealership_id', $user->dealership_id)
            ->with(['dealership']);

        // Apply filters
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['date_from'])) {
            $query->where('shift_start', '>=', $filters['date_from']);
        }

        if (isset($filters['date_to'])) {
            $query->where('shift_start', '<=', $filters['date_to']);
        }

        $query->orderBy('shift_start', 'desc');

        if ($perPage !== null) {
            return $query->paginate($perPage);
        }

        return $query->get();
    }
}
