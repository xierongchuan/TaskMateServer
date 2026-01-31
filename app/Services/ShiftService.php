<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ShiftStatus;
use App\Models\Shift;
use App\Models\User;
use App\Models\Task;
use App\Models\TaskResponse;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Contracts\FileValidatorInterface;

class ShiftService
{
    /**
     * Пресет валидации для фото смен.
     */
    private const VALIDATION_PRESET = 'shift_photo';

    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly FileValidatorInterface $fileValidator
    ) {
    }

    /**
     * Open a new shift for a user
     *
     * @param User $user
     * @param UploadedFile $photo
     * @param User|null $replacingUser
     * @param string|null $reason
     * @return Shift
     * @throws \InvalidArgumentException
     */
    public function openShift(User $user, UploadedFile $photo, ?User $replacingUser = null, ?string $reason = null, ?int $dealershipId = null): Shift
    {
        // Use provided dealershipId or fallback to user's primary dealership
        $dealershipId = $dealershipId ?? $user->dealership_id;

        // Validate user belongs to a dealership
        if (!$dealershipId) {
            throw new \InvalidArgumentException('User must belong to a dealership to open a shift');
        }

        $now = Carbon::now();

        // Get dealership-specific settings (before transaction for performance)
        $scheduledStart = $this->getScheduledStartTime($now, $dealershipId);
        $scheduledEnd = $this->getScheduledEndTime($now, $dealershipId);
        $lateTolerance = $this->settingsService->getLateTolerance($dealershipId);

        // Calculate if user is late
        $lateMinutes = (int) max(0, $now->diffInMinutes($scheduledStart));
        $isLate = $lateMinutes > $lateTolerance;

        // Determine shift status
        $status = $isLate ? ShiftStatus::LATE->value : ShiftStatus::OPEN->value;

        // Determine shift type based on day of week
        $dayOfWeek = $now->dayOfWeek; // 0 = Sunday, 6 = Saturday
        $shiftType = ($dayOfWeek === 0 || $dayOfWeek === 6) ? 'weekend' : 'regular';

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
                if ($photoPath && Storage::exists($photoPath)) {
                    Storage::delete($photoPath);
                }
                throw new \InvalidArgumentException('User already has an open shift in this dealership');
            }

            // Create shift record
            $shift = Shift::create([
                'user_id' => $user->id,
                'dealership_id' => $dealershipId,
                'shift_start' => $now,
                'scheduled_start' => $scheduledStart,
                'scheduled_end' => $scheduledEnd,
                'opening_photo_path' => $photoPath,
                'status' => $status,
                'shift_type' => $shiftType,
                'late_minutes' => $lateMinutes,
            ]);

            DB::commit();

            Log::info("Shift opened for user {$user->id} in dealership {$dealershipId}", [
                'shift_id' => $shift->id,
                'status' => $status,
                'late_minutes' => $lateMinutes,
                'is_replacement' => false,
            ]);

            return $shift;
        } catch (\Exception $e) {
            DB::rollBack();

            // Clean up photo if shift creation failed
            if ($photoPath && Storage::exists($photoPath)) {
                Storage::delete($photoPath);
            }

            Log::error("Failed to open shift for user {$user->id}", [
                'error' => $e->getMessage(),
                'dealership_id' => $dealershipId,
            ]);

            throw new \InvalidArgumentException('Failed to open shift: ' . $e->getMessage());
        }
    }

    /**
     * Close a shift
     *
     * @param Shift $shift
     * @param UploadedFile $photo
     * @return Shift
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
            if ($photoPath && Storage::exists($photoPath)) {
                Storage::delete($photoPath);
            }

            Log::error("Failed to close shift for user {$shift->user_id}", [
                'error' => $e->getMessage(),
                'shift_id' => $shift->id,
            ]);

            throw new \InvalidArgumentException('Failed to close shift: ' . $e->getMessage());
        }
    }

    /**
     * Get user's current open shift
     *
     * @param User $user
     * @param int|null $dealershipId
     * @return Shift|null
     */
    public function getUserOpenShift(User $user, ?int $dealershipId = null): ?Shift
    {
        $query = Shift::where('user_id', $user->id)
            ->whereIn('status', ShiftStatus::activeStatusValues());

        if ($dealershipId) {
            $query->where('dealership_id', $dealershipId);
        }

        return $query->first();
    }

    /**
     * Get current open shifts for a dealership
     *
     * @param int|null $dealershipId
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getCurrentShifts(?int $dealershipId = null)
    {
        $query = Shift::with(['user', 'dealership'])
            ->whereIn('status', ShiftStatus::activeStatusValues())
            ->orderBy('shift_start', 'desc');

        if ($dealershipId) {
            $query->where('dealership_id', $dealershipId);
        }

        return $query->get();
    }

    /**
     * Get shift statistics for a dealership and period
     *
     * @param int|null $dealershipId
     * @param Carbon|null $startDate
     * @param Carbon|null $endDate
     * @return array
     */
    public function getShiftStatistics(?int $dealershipId = null, ?Carbon $startDate = null, ?Carbon $endDate = null): array
    {
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
        $lateShifts = $query->where('status', ShiftStatus::LATE->value)->count();
        $avgLateMinutes = $query->whereNotNull('late_minutes')->avg('late_minutes') ?? 0;

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
     *
     * @param Shift $shift
     * @param string $status
     * @return Shift
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
     * @param UploadedFile $photo
     * @param string $type
     * @param int $userId
     * @param int $dealershipId
     * @return string
     * @throws \InvalidArgumentException
     */
    private function storeShiftPhoto(UploadedFile $photo, string $type, int $userId, int $dealershipId): string
    {
        // Валидация через FileValidator с пресетом для фото смен
        $this->fileValidator->validate($photo, self::VALIDATION_PRESET);

        $extension = strtolower($photo->getClientOriginalExtension());
        $filename = $type . '_' . time() . '_' . $userId . '.' . $extension;
        $path = "dealerships/{$dealershipId}/shifts/{$userId}/" . date('Y/m/d');

        return $photo->storeAs($path, $filename, 'public');
    }

    /**
     * Get scheduled start time based on dealership settings
     *
     * @param Carbon $dateTime
     * @param int $dealershipId
     * @return Carbon
     */
    private function getScheduledStartTime(Carbon $dateTime, int $dealershipId): Carbon
    {
        $hour = (int) $dateTime->format('H');

        // First shift: 00:00 - 12:59
        if ($hour < 13) {
            $startTime = $this->settingsService->getShiftStartTime($dealershipId, 1);
            return $dateTime->copy()->setTimeFromTimeString($startTime);
        }

        // Second shift: 13:00 - 23:59
        $startTime = $this->settingsService->getShiftStartTime($dealershipId, 2);
        return $dateTime->copy()->setTimeFromTimeString($startTime);
    }

    /**
     * Get scheduled end time based on dealership settings
     *
     * @param Carbon $dateTime
     * @param int $dealershipId
     * @return Carbon
     */
    private function getScheduledEndTime(Carbon $dateTime, int $dealershipId): Carbon
    {
        $hour = (int) $dateTime->format('H');

        // First shift: 00:00 - 12:59
        if ($hour < 13) {
            $endTime = $this->settingsService->getShiftEndTime($dealershipId, 1);
            $endDateTime = $dateTime->copy()->setTimeFromTimeString($endTime);

            // If end time is earlier than start time (e.g., night shift crossing midnight)
            if ($endDateTime->lt($dateTime)) {
                $endDateTime->addDay();
            }

            return $endDateTime;
        }

        // Second shift: 13:00 - 23:59
        $endTime = $this->settingsService->getShiftEndTime($dealershipId, 2);
        $endDateTime = $dateTime->copy()->setTimeFromTimeString($endTime);

        // If end time is earlier than start time (e.g., night shift crossing midnight)
        if ($endDateTime->lt($dateTime)) {
            $endDateTime->addDay();
        }

        return $endDateTime;
    }

    /**
     * Log incomplete tasks for a shift
     *
     * @param Shift $shift
     * @param User $user
     * @return void
     */
    private function logIncompleteTasks(Shift $shift, User $user): void
    {
        // Get tasks assigned to user that are due during the shift period
        $tasks = Task::where(function ($query) use ($shift, $user) {
                $query->whereHas('assignments', function ($q) use ($user) {
                    $q->where('user_id', $user->id);
                })->orWhere('task_type', 'group');
            })
            ->where('dealership_id', $shift->dealership_id)
            ->where('is_active', true)
            ->where(function ($query) use ($shift) {
                $query->whereBetween('deadline', [$shift->shift_start, $shift->shift_end ?? Carbon::now()])
                    ->orWhereNull('deadline');
            })
            ->whereDoesntHave('responses', function ($q) use ($user) {
                $q->where('user_id', $user->id)
                  ->whereIn('status', ['completed', 'acknowledged']);
            })
            ->get();

        foreach ($tasks as $task) {
            Log::info("Incomplete task at shift end", [
                'shift_id' => $shift->id,
                'task_id' => $task->id,
                'user_id' => $user->id,
                'dealership_id' => $shift->dealership_id,
            ]);
        }
    }

    /**
     * Validate user can work with shifts in their dealership
     *
     * @param User $user
     * @param int|null $dealershipId
     * @return bool
     */
    public function validateUserDealership(User $user, ?int $dealershipId = null): bool
    {
        if (!$dealershipId) {
            return (bool) $user->dealership_id;
        }

        // Check primary dealership
        if ($user->dealership_id === $dealershipId) {
            return true;
        }

        // Allow admins and owners to operate in any dealership
        if (in_array($user->role, ['admin', 'owner'])) {
            return true;
        }

        // Check attached dealerships (many-to-many)
        return $user->dealerships()->where('auto_dealerships.id', $dealershipId)->exists();
    }

    /**
     * Get shifts for a user with dealership context
     *
     * @param User $user
     * @param array $filters
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getUserShifts(User $user, array $filters = [])
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

        return $query->orderBy('shift_start', 'desc')->get();
    }
}
