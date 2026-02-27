<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\AutoDealership;
use App\Models\Shift;
use App\Models\Task;
use App\Models\TaskResponse;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * Сидер для создания записей аудита.
 *
 * Создаёт реалистичную историю действий пользователей в системе.
 */
class AuditLogSeeder extends Seeder
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
        $this->command->info('Создание записей аудита за ' . self::$historyDays . ' дней...');

        $dealerships = AutoDealership::all();

        if ($dealerships->isEmpty()) {
            $this->command->warn('Автосалоны не найдены. Пропуск создания аудита.');
            return;
        }

        $totalLogs = 0;

        foreach ($dealerships as $dealership) {
            $logsCreated = $this->createAuditLogsForDealership($dealership);
            $totalLogs += $logsCreated;
            $this->command->info(" - {$dealership->name}: {$logsCreated} записей аудита");
        }

        $this->command->info("Всего создано записей аудита: {$totalLogs}");
    }

    /**
     * Создать записи аудита для автосалона.
     */
    private function createAuditLogsForDealership(AutoDealership $dealership): int
    {
        $manager = User::where('dealership_id', $dealership->id)
            ->where('role', Role::MANAGER)
            ->first();

        $employees = User::where('dealership_id', $dealership->id)
            ->where('role', Role::EMPLOYEE)
            ->get();

        if (!$manager || $employees->isEmpty()) {
            return 0;
        }

        $logsCreated = 0;
        $startDate = Carbon::now()->subDays(self::$historyDays);
        $midDate = Carbon::now()->subDays(max(1, (int) (self::$historyDays / 2)));
        $recentDate = Carbon::now()->subDays(max(1, (int) (self::$historyDays / 4)));

        // Логи создания пользователей (в начале периода)
        foreach ($employees as $employee) {
            AuditLog::create([
                'table_name' => 'users',
                'record_id' => $employee->id,
                'actor_id' => $manager->id,
                'dealership_id' => $dealership->id,
                'action' => 'created',
                'payload' => [
                    'login' => $employee->login,
                    'full_name' => $employee->full_name,
                    'role' => $employee->role->value,
                ],
                'created_at' => fake()->dateTimeBetween($startDate, $midDate),
            ]);
            $logsCreated++;
        }

        // Логи обновления пользователей (в середине периода)
        $usersToUpdate = $employees->random(min(2, $employees->count()));
        foreach ($usersToUpdate as $user) {
            AuditLog::create([
                'table_name' => 'users',
                'record_id' => $user->id,
                'actor_id' => $manager->id,
                'dealership_id' => $dealership->id,
                'action' => 'updated',
                'payload' => [
                    'changes' => [
                        'phone' => [
                            'old' => fake()->phoneNumber(),
                            'new' => $user->phone,
                        ],
                    ],
                ],
                'created_at' => fake()->dateTimeBetween($midDate, $recentDate),
            ]);
            $logsCreated++;
        }

        // Логи создания задач
        $tasks = Task::where('dealership_id', $dealership->id)
            ->limit(20)
            ->get();

        foreach ($tasks as $task) {
            AuditLog::create([
                'table_name' => 'tasks',
                'record_id' => $task->id,
                'actor_id' => $task->creator_id,
                'dealership_id' => $dealership->id,
                'action' => 'created',
                'payload' => [
                    'title' => $task->title,
                    'task_type' => $task->task_type,
                    'response_type' => $task->response_type,
                    'deadline' => $task->deadline?->toIso8601String(),
                ],
                'created_at' => $task->created_at ?? fake()->dateTimeBetween($startDate, 'now'),
            ]);
            $logsCreated++;
        }

        // Логи обновления задач (архивирование)
        $archivedTasks = Task::where('dealership_id', $dealership->id)
            ->whereNotNull('archived_at')
            ->limit(10)
            ->get();

        foreach ($archivedTasks as $task) {
            AuditLog::create([
                'table_name' => 'tasks',
                'record_id' => $task->id,
                'actor_id' => null, // Системное действие
                'dealership_id' => $dealership->id,
                'action' => 'updated',
                'payload' => [
                    'changes' => [
                        'is_active' => ['old' => true, 'new' => false],
                        'archived_at' => ['old' => null, 'new' => $task->archived_at?->toIso8601String()],
                        'archive_reason' => ['old' => null, 'new' => $task->archive_reason],
                    ],
                ],
                'created_at' => $task->archived_at ?? fake()->dateTimeBetween($recentDate, 'now'),
            ]);
            $logsCreated++;
        }

        // Логи создания ответов на задачи
        $responses = TaskResponse::whereHas('task', function ($query) use ($dealership) {
            $query->where('dealership_id', $dealership->id);
        })->limit(30)->get();

        foreach ($responses as $response) {
            AuditLog::create([
                'table_name' => 'task_responses',
                'record_id' => $response->id,
                'actor_id' => $response->user_id,
                'dealership_id' => $dealership->id,
                'action' => 'created',
                'payload' => [
                    'task_id' => $response->task_id,
                    'status' => $response->status,
                ],
                'created_at' => $response->responded_at ?? fake()->dateTimeBetween($startDate, 'now'),
            ]);
            $logsCreated++;
        }

        // Логи верификации ответов (обновления)
        $verifiedResponses = TaskResponse::whereHas('task', function ($query) use ($dealership) {
            $query->where('dealership_id', $dealership->id);
        })->whereNotNull('verified_at')->limit(10)->get();

        foreach ($verifiedResponses as $response) {
            AuditLog::create([
                'table_name' => 'task_responses',
                'record_id' => $response->id,
                'actor_id' => $response->verified_by ?? $manager->id,
                'dealership_id' => $dealership->id,
                'action' => 'updated',
                'payload' => [
                    'changes' => [
                        'status' => ['old' => 'pending_review', 'new' => $response->status],
                    ],
                    'verified_by' => $response->verified_by ?? $manager->id,
                ],
                'created_at' => $response->verified_at ?? fake()->dateTimeBetween($recentDate, 'now'),
            ]);
            $logsCreated++;
        }

        // Логи открытия/закрытия смен
        $shifts = Shift::where('dealership_id', $dealership->id)
            ->limit(40)
            ->get();

        foreach ($shifts as $shift) {
            // Лог открытия смены
            AuditLog::create([
                'table_name' => 'shifts',
                'record_id' => $shift->id,
                'actor_id' => $shift->user_id,
                'dealership_id' => $dealership->id,
                'action' => 'created',
                'payload' => [
                    'user_id' => $shift->user_id,
                    'shift_start' => $shift->shift_start?->toIso8601String(),
                    'status' => 'open',
                ],
                'created_at' => $shift->shift_start,
            ]);
            $logsCreated++;

            // Лог закрытия смены (если закрыта)
            if ($shift->status === 'closed' && $shift->shift_end) {
                AuditLog::create([
                    'table_name' => 'shifts',
                    'record_id' => $shift->id,
                    'actor_id' => $shift->user_id,
                    'dealership_id' => $dealership->id,
                    'action' => 'updated',
                    'payload' => [
                        'changes' => [
                            'status' => ['old' => 'open', 'new' => 'closed'],
                            'shift_end' => ['old' => null, 'new' => $shift->shift_end->toIso8601String()],
                        ],
                    ],
                    'created_at' => $shift->shift_end,
                ]);
                $logsCreated++;
            }
        }

        // Логи обновления настроек автосалона
        for ($i = 0; $i < 3; $i++) {
            AuditLog::create([
                'table_name' => 'auto_dealerships',
                'record_id' => $dealership->id,
                'actor_id' => $manager->id,
                'dealership_id' => $dealership->id,
                'action' => 'updated',
                'payload' => [
                    'changes' => [
                        fake()->randomElement(['name', 'address', 'phone', 'description']) => [
                            'old' => fake()->word(),
                            'new' => fake()->word(),
                        ],
                    ],
                ],
                'created_at' => fake()->dateTimeBetween($startDate, 'now'),
            ]);
            $logsCreated++;
        }

        return $logsCreated;
    }
}
