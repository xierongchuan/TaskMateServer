<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\AutoDealership;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskGenerator;
use App\Models\TaskGeneratorAssignment;
use App\Models\TaskProof;
use App\Models\TaskResponse;
use App\Models\TaskSharedProof;
use App\Models\TaskVerificationHistory;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class TaskSeeder extends Seeder
{
    /**
     * Number of days to generate history for.
     * Can be overridden by setting this static property before running the seeder.
     */
    public static int $historyDays = 30;

    /**
     * Default timezone for demo data (местное время для ввода).
     * Времена в генераторах указываются в этом timezone и конвертируются в UTC.
     */
    private const DEFAULT_LOCAL_TIMEZONE = '+05:00';

    /**
     * Причины отклонения для задач с доказательствами.
     */
    private const REJECTION_REASONS = [
        'Нечёткое изображение, пожалуйста переснимите',
        'На фото не видно выполненной работы',
        'Требуется фото с другого ракурса',
        'Файл повреждён, загрузите заново',
        'Недостаточно доказательств выполнения',
        'Необходимо показать результат работы',
    ];

    /**
     * Названия задач с доказательствами.
     */
    private const PROOF_TASK_TITLES = [
        'Фото отчёт по уборке территории',
        'Фото готового автомобиля после мойки',
        'Фото выкладки в шоуруме',
        'Фото результата ремонта',
        'Фото клиента с автомобилем (с согласия)',
        'Фото чека/документов',
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info("Creating Tasks with " . self::$historyDays . " days history...");

        $dealerships = AutoDealership::all();

        if ($dealerships->isEmpty()) {
            $this->command->warn('No dealerships found. Skipping task generation.');
            return;
        }

        foreach ($dealerships as $dealership) {
            $manager = User::where('dealership_id', $dealership->id)
                ->where('role', Role::MANAGER)
                ->first();

            if (!$manager) {
                // Try finding any manager linked to this dealership potentially?
                // For now, assume the standard structure where manager has dealership_id
                $this->command->warn("No manager found for dealership {$dealership->name}. Skipping.");
                continue;
            }

            $employees = User::where('dealership_id', $dealership->id)
                ->where('role', Role::EMPLOYEE)
                ->get()
                ->all();

            if (empty($employees)) {
                $this->command->warn("No employees found for dealership {$dealership->name}. Skipping.");
                continue;
            }

            $this->createTaskGeneratorsWithHistory($dealership, $manager, $employees);
            $this->createOneTimeTasks($dealership, $manager, $employees);
            $this->createTasksWithProofs($dealership, $manager, $employees);
        }
    }

    private function createTaskGeneratorsWithHistory($dealership, $manager, array $employees): void
    {
        // Времена указаны в местном формате (DEFAULT_LOCAL_TIMEZONE)
        // и будут автоматически конвертированы в UTC при создании
        $generators = [
            [
                'title' => 'Ежедневная проверка автомобилей',
                'description' => 'Проверить состояние всех автомобилей на площадке',
                'recurrence' => 'daily',
                'recurrence_time' => '09:00', // 09:00 местное → 04:00 UTC
                'deadline_time' => '12:00',   // 12:00 местное → 07:00 UTC
                'task_type' => 'group',
                'response_type' => 'completion',
                'priority' => 'high',
                'tags' => ['проверка', 'автомобили'],
                'history_days' => self::$historyDays,
            ],
            [
                'title' => 'Еженедельный отчет по продажам',
                'description' => 'Подготовить отчет по продажам за неделю',
                'recurrence' => 'weekly',
                'recurrence_time' => '10:00',
                'deadline_time' => '18:00',
                'recurrence_days_of_week' => [5], // Friday
                'task_type' => 'individual',
                'response_type' => 'completion',
                'priority' => 'medium',
                'tags' => ['отчет', 'продажи'],
                'history_days' => self::$historyDays,
            ],
            [
                'title' => 'Ежемесячная инвентаризация',
                'description' => 'Провести инвентаризацию склада запчастей',
                'recurrence' => 'monthly',
                'recurrence_time' => '09:00',
                'deadline_time' => '18:00',
                'recurrence_days_of_month' => [-1], // Last day of month
                'task_type' => 'group',
                'response_type' => 'completion',
                'priority' => 'high',
                'tags' => ['инвентаризация', 'склад'],
                'history_days' => self::$historyDays,
            ],
            [
                'title' => 'Утренняя уборка шоурума',
                'description' => 'Ежедневная уборка и подготовка шоурума к открытию',
                'recurrence' => 'daily',
                'recurrence_time' => '08:00',
                'deadline_time' => '09:30',
                'task_type' => 'individual',
                'response_type' => 'notification',
                'priority' => 'medium',
                'tags' => ['уборка', 'шоурум'],
                'history_days' => min(90, self::$historyDays), // Cap at historyDays
            ],
            [
                'title' => 'Еженедельное совещание команды',
                'description' => 'Обсуждение планов и результатов работы',
                'recurrence' => 'weekly',
                'recurrence_time' => '14:00',
                'deadline_time' => '15:00',
                'recurrence_days_of_week' => [1], // Monday
                'task_type' => 'group',
                'response_type' => 'notification',
                'priority' => 'low',
                'tags' => ['совещание', 'команда'],
                'history_days' => min(180, self::$historyDays),
            ],
        ];

        $totalTasks = 0;

        foreach ($generators as $genData) {
            $startDate = Carbon::today()->subDays($genData['history_days']);

            // Конвертируем местное время в UTC для хранения в БД
            $recurrenceTimeUtc = Carbon::createFromFormat('H:i', $genData['recurrence_time'], self::DEFAULT_LOCAL_TIMEZONE)
                ->setTimezone('UTC')
                ->format('H:i:s');
            $deadlineTimeUtc = Carbon::createFromFormat('H:i', $genData['deadline_time'], self::DEFAULT_LOCAL_TIMEZONE)
                ->setTimezone('UTC')
                ->format('H:i:s');

            $generator = TaskGenerator::create([
                'title' => $genData['title'],
                'description' => $genData['description'],
                'creator_id' => $manager->id,
                'dealership_id' => $dealership->id,
                'recurrence' => $genData['recurrence'],
                'recurrence_time' => $recurrenceTimeUtc,
                'deadline_time' => $deadlineTimeUtc,
                'recurrence_days_of_week' => $genData['recurrence_days_of_week'] ?? null,
                'recurrence_days_of_month' => $genData['recurrence_days_of_month'] ?? null,
                'start_date' => $startDate,
                'task_type' => $genData['task_type'],
                'response_type' => $genData['response_type'],
                'priority' => $genData['priority'],
                'tags' => $genData['tags'],
                'is_active' => true,
            ]);

            // Assign employees
            $assignedEmployees = [];
            if ($genData['task_type'] === 'group') {
                foreach ($employees as $emp) {
                    TaskGeneratorAssignment::create([
                        'generator_id' => $generator->id,
                        'user_id' => $emp->id,
                    ]);
                    $assignedEmployees[] = $emp;
                }
            } else {
                // Individual - assign to random employee
                $emp = $employees[array_rand($employees)];
                TaskGeneratorAssignment::create([
                    'generator_id' => $generator->id,
                    'user_id' => $emp->id,
                ]);
                $assignedEmployees[] = $emp;
            }

            $tasksCreated = $this->generateHistoricalTasks(
                $generator,
                $assignedEmployees,
                $genData['history_days']
            );
            $totalTasks += $tasksCreated;
        }

        $this->command->info(" - Created " . count($generators) . " Task Generators with {$totalTasks} historical tasks for {$dealership->name}");
    }

    private function generateHistoricalTasks(TaskGenerator $generator, array $assignedEmployees, int $historyDays): int
    {
        $tasksCreated = 0;
        // Работаем в UTC для корректного определения дней
        $today = Carbon::today('UTC');
        $startDate = $today->copy()->subDays($historyDays);

        // Время уже в UTC (конвертировано при создании генератора)
        $recurrenceTime = Carbon::createFromFormat('H:i:s', $generator->recurrence_time, 'UTC');
        $deadlineTime = Carbon::createFromFormat('H:i:s', $generator->deadline_time, 'UTC');

        $currentDate = $startDate->copy();

        while ($currentDate->lte($today)) {
            $shouldGenerate = false;

            switch ($generator->recurrence) {
                case 'daily':
                    $shouldGenerate = true;
                    break;
                case 'weekly':
                    $daysOfWeek = $generator->recurrence_days_of_week ?? [];
                    $shouldGenerate = in_array($currentDate->dayOfWeekIso, $daysOfWeek, true);
                    break;
                case 'monthly':
                    $daysOfMonth = $generator->recurrence_days_of_month ?? [];
                    foreach ($daysOfMonth as $targetDay) {
                        if ($targetDay > 0) {
                            $effectiveDay = min($targetDay, $currentDate->daysInMonth);
                            if ($currentDate->day === $effectiveDay) {
                                $shouldGenerate = true;
                                break;
                            }
                        } else {
                            $effectiveDay = $currentDate->daysInMonth + $targetDay + 1;
                            if ($effectiveDay > 0 && $currentDate->day === $effectiveDay) {
                                $shouldGenerate = true;
                                break;
                            }
                        }
                    }
                    break;
            }

            if ($shouldGenerate) {
                // Check if already exists to avoid duplicates if re-running without fresh
                // But seeding usually assumes fresh or append. We'll proceed.

                $appearDate = $currentDate->copy()->setTime($recurrenceTime->hour, $recurrenceTime->minute, 0);
                $deadline = $currentDate->copy()->setTime($deadlineTime->hour, $deadlineTime->minute, 0);

                if ($deadline->lt($appearDate)) {
                    $deadline->addDay();
                }

                $task = Task::create([
                    'generator_id' => $generator->id,
                    'title' => $generator->title,
                    'description' => $generator->description,
                    'creator_id' => $generator->creator_id,
                    'dealership_id' => $generator->dealership_id,
                    'appear_date' => $appearDate,
                    'deadline' => $deadline,
                    'scheduled_date' => $currentDate->copy(),
                    'task_type' => $generator->task_type,
                    'response_type' => $generator->response_type,
                    'priority' => $generator->priority,
                    'tags' => $generator->tags,
                    'is_active' => true,
                ]);

                foreach ($assignedEmployees as $emp) {
                    TaskAssignment::create([
                        'task_id' => $task->id,
                        'user_id' => $emp->id,
                        'assigned_at' => $appearDate,
                    ]);
                }

                $this->generateTaskResponses($task, $assignedEmployees, $deadline);
                $tasksCreated++;
            }

            $currentDate->addDay();
        }

        $generator->update(['last_generated_at' => $today]);

        return $tasksCreated;
    }

    private function generateTaskResponses(Task $task, array $assignedEmployees, Carbon $deadline): void
    {
        $now = Carbon::now('UTC');
        $isPast = $deadline->lt($now);
        $isRecent = $deadline->diffInDays($now) < 3;

        if (!$isPast || ($isRecent && fake()->boolean(30))) {
            return;
        }

        $outcome = fake()->randomFloat(2, 0, 1);

        if ($outcome < 0.75) {
            $responseTime = fake()->dateTimeBetween($task->appear_date, $deadline);
            $this->completeTask($task, $assignedEmployees, Carbon::parse($responseTime));
        } elseif ($outcome < 0.85) {
            $hoursLate = fake()->numberBetween(1, 48);
            $responseTime = $deadline->copy()->addHours($hoursLate);
            if ($responseTime->gt($now)) {
                $responseTime = $now->copy()->subMinutes(fake()->numberBetween(1, 60));
            }
            $this->completeTask($task, $assignedEmployees, $responseTime);
        } else {
            $task->update([
                'archived_at' => $deadline->copy()->addHours(24),
                'archive_reason' => 'expired',
                'is_active' => false,
            ]);
        }
    }

    private function completeTask(Task $task, array $assignedEmployees, Carbon $responseTime): void
    {
        foreach ($assignedEmployees as $emp) {
            $empResponseTime = $responseTime->copy();
            if (count($assignedEmployees) > 1) {
                $empResponseTime->addMinutes(fake()->numberBetween(0, 120));
            }

            TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $emp->id,
                'status' => 'completed',
                'comment' => fake()->optional(0.3)->sentence(),
                'responded_at' => $empResponseTime,
            ]);
        }

        $task->update([
            'archived_at' => $responseTime,
            'archive_reason' => 'completed',
            'is_active' => false,
        ]);
    }

    private function createOneTimeTasks($dealership, $manager, array $employees): void
    {
        foreach ($employees as $emp) {
            $activeTasks = Task::factory(2)->create([
                'dealership_id' => $dealership->id,
                'creator_id' => $manager->id,
                'task_type' => 'individual',
                'title' => 'Активная задача для ' . $emp->full_name,
                'appear_date' => Carbon::now()->subHours(rand(1, 24)),
                'deadline' => Carbon::now()->addDays(rand(1, 7)),
            ]);

            foreach ($activeTasks as $task) {
                TaskAssignment::create([
                    'task_id' => $task->id,
                    'user_id' => $emp->id,
                    'assigned_at' => $task->appear_date,
                ]);
            }

            $completedTasks = Task::factory(3)->create([
                'dealership_id' => $dealership->id,
                'creator_id' => $manager->id,
                'task_type' => 'individual',
                'title' => 'Выполненная задача для ' . $emp->full_name,
                'appear_date' => Carbon::now()->subDays(rand(5, 30)),
                'deadline' => Carbon::now()->subDays(rand(1, 4)),
                'archived_at' => Carbon::now()->subDays(rand(1, 4)),
                'archive_reason' => 'completed',
                'is_active' => false,
            ]);

            foreach ($completedTasks as $task) {
                TaskAssignment::create([
                    'task_id' => $task->id,
                    'user_id' => $emp->id,
                    'assigned_at' => $task->appear_date,
                ]);

                TaskResponse::create([
                    'task_id' => $task->id,
                    'user_id' => $emp->id,
                    'status' => 'completed',
                    'responded_at' => $task->archived_at,
                ]);
            }
        }

        $groupTasks = Task::factory(2)->create([
            'dealership_id' => $dealership->id,
            'creator_id' => $manager->id,
            'task_type' => 'group',
            'title' => 'Групповое совещание',
            'appear_date' => Carbon::now()->subHours(2),
            'deadline' => Carbon::now()->addDays(1),
        ]);

        foreach ($groupTasks as $task) {
            foreach ($employees as $emp) {
                TaskAssignment::create([
                    'task_id' => $task->id,
                    'user_id' => $emp->id,
                    'assigned_at' => $task->appear_date,
                ]);
            }
        }
        $this->command->info(" - Created One-time Tasks for {$dealership->name}");
    }

    /**
     * Создать задачи с доказательствами и верификацией.
     */
    private function createTasksWithProofs($dealership, $manager, array $employees): void
    {
        $proofsCreated = 0;
        $verificationHistoryCreated = 0;

        // 1. Задачи pending_review (на проверке)
        $pendingReviewCount = $this->createPendingReviewTasks($dealership, $manager, $employees);

        // 2. Задачи rejected (отклонённые)
        $rejectedCount = $this->createRejectedTasks($dealership, $manager, $employees);

        // 3. Задачи completed с доказательствами
        $completedWithProofCount = $this->createCompletedWithProofTasks($dealership, $manager, $employees);

        // 4. Групповые задачи с shared proofs
        $groupWithProofCount = $this->createGroupTasksWithSharedProofs($dealership, $manager, $employees);

        // 5. Просроченные задачи (overdue)
        $overdueCount = $this->createOverdueTasks($dealership, $manager, $employees);

        $this->command->info(" - Задачи с доказательствами для {$dealership->name}:");
        $this->command->info("   - pending_review: {$pendingReviewCount}");
        $this->command->info("   - rejected: {$rejectedCount}");
        $this->command->info("   - completed с proof: {$completedWithProofCount}");
        $this->command->info("   - group с shared proof: {$groupWithProofCount}");
        $this->command->info("   - overdue: {$overdueCount}");
    }

    /**
     * Создать задачи в статусе pending_review.
     */
    private function createPendingReviewTasks($dealership, $manager, array $employees): int
    {
        $count = 0;

        foreach (array_slice($employees, 0, 3) as $employee) {
            $task = Task::create([
                'title' => fake()->randomElement(self::PROOF_TASK_TITLES),
                'description' => 'Загрузите фото как подтверждение выполнения задачи',
                'creator_id' => $manager->id,
                'dealership_id' => $dealership->id,
                'appear_date' => Carbon::now()->subHours(rand(4, 24)),
                'deadline' => Carbon::now()->addHours(rand(2, 48)),
                'task_type' => 'individual',
                'response_type' => 'completion_with_proof',
                'priority' => fake()->randomElement(['medium', 'high']),
                'tags' => ['требует_фото', 'на_проверке'],
                'is_active' => true,
            ]);

            TaskAssignment::create([
                'task_id' => $task->id,
                'user_id' => $employee->id,
                'assigned_at' => $task->appear_date,
            ]);

            // Создаём response в статусе pending_review
            $response = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $employee->id,
                'status' => 'pending_review',
                'comment' => 'Задача выполнена, прошу проверить',
                'responded_at' => Carbon::now()->subHours(rand(1, 3)),
            ]);

            // Добавляем proofs
            $proofCount = rand(1, 3);
            for ($i = 0; $i < $proofCount; $i++) {
                $this->createStubProof($response);
            }

            // Добавляем verification history
            TaskVerificationHistory::create([
                'task_response_id' => $response->id,
                'action' => TaskVerificationHistory::ACTION_SUBMITTED,
                'performed_by' => $employee->id,
                'previous_status' => 'pending',
                'new_status' => 'pending_review',
                'proof_count' => $proofCount,
                'created_at' => $response->responded_at,
            ]);

            $count++;
        }

        return $count;
    }

    /**
     * Создать задачи в статусе rejected.
     */
    private function createRejectedTasks($dealership, $manager, array $employees): int
    {
        $count = 0;

        foreach (array_slice($employees, 0, 2) as $employee) {
            $task = Task::create([
                'title' => fake()->randomElement(self::PROOF_TASK_TITLES),
                'description' => 'Задача была отклонена менеджером, требуется повторная загрузка',
                'creator_id' => $manager->id,
                'dealership_id' => $dealership->id,
                'appear_date' => Carbon::now()->subDays(rand(1, 3)),
                'deadline' => Carbon::now()->addDays(rand(1, 2)),
                'task_type' => 'individual',
                'response_type' => 'completion_with_proof',
                'priority' => 'high',
                'tags' => ['требует_фото', 'отклонено'],
                'is_active' => true,
            ]);

            TaskAssignment::create([
                'task_id' => $task->id,
                'user_id' => $employee->id,
                'assigned_at' => $task->appear_date,
            ]);

            $submittedAt = Carbon::now()->subDays(rand(1, 2));
            $rejectedAt = $submittedAt->copy()->addHours(rand(2, 8));

            $response = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $employee->id,
                'status' => 'rejected',
                'comment' => 'Попробую переснять',
                'responded_at' => $submittedAt,
                'rejection_reason' => fake()->randomElement(self::REJECTION_REASONS),
                'rejection_count' => 1,
            ]);

            // Добавляем proofs
            $proofCount = rand(1, 2);
            for ($i = 0; $i < $proofCount; $i++) {
                $this->createStubProof($response);
            }

            // История: submitted
            TaskVerificationHistory::create([
                'task_response_id' => $response->id,
                'action' => TaskVerificationHistory::ACTION_SUBMITTED,
                'performed_by' => $employee->id,
                'previous_status' => 'pending',
                'new_status' => 'pending_review',
                'proof_count' => $proofCount,
                'created_at' => $submittedAt,
            ]);

            // История: rejected
            TaskVerificationHistory::create([
                'task_response_id' => $response->id,
                'action' => TaskVerificationHistory::ACTION_REJECTED,
                'performed_by' => $manager->id,
                'reason' => $response->rejection_reason,
                'previous_status' => 'pending_review',
                'new_status' => 'rejected',
                'proof_count' => $proofCount,
                'created_at' => $rejectedAt,
            ]);

            $count++;
        }

        return $count;
    }

    /**
     * Создать завершённые задачи с доказательствами.
     */
    private function createCompletedWithProofTasks($dealership, $manager, array $employees): int
    {
        $count = 0;

        foreach (array_slice($employees, 0, 3) as $employee) {
            $task = Task::create([
                'title' => fake()->randomElement(self::PROOF_TASK_TITLES),
                'description' => 'Задача выполнена и подтверждена менеджером',
                'creator_id' => $manager->id,
                'dealership_id' => $dealership->id,
                'appear_date' => Carbon::now()->subDays(rand(3, 7)),
                'deadline' => Carbon::now()->subDays(rand(1, 2)),
                'task_type' => 'individual',
                'response_type' => 'completion_with_proof',
                'priority' => fake()->randomElement(['low', 'medium', 'high']),
                'tags' => ['требует_фото'],
                'is_active' => false,
                'archived_at' => Carbon::now()->subDays(rand(1, 2)),
                'archive_reason' => 'completed',
            ]);

            TaskAssignment::create([
                'task_id' => $task->id,
                'user_id' => $employee->id,
                'assigned_at' => $task->appear_date,
            ]);

            $submittedAt = $task->appear_date->copy()->addHours(rand(2, 24));
            $approvedAt = $submittedAt->copy()->addHours(rand(1, 4));

            $response = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $employee->id,
                'status' => 'completed',
                'comment' => 'Готово!',
                'responded_at' => $submittedAt,
                'verified_at' => $approvedAt,
                'verified_by' => $manager->id,
            ]);

            // Добавляем proofs
            $proofCount = rand(1, 3);
            for ($i = 0; $i < $proofCount; $i++) {
                $this->createStubProof($response);
            }

            // История: submitted
            TaskVerificationHistory::create([
                'task_response_id' => $response->id,
                'action' => TaskVerificationHistory::ACTION_SUBMITTED,
                'performed_by' => $employee->id,
                'previous_status' => 'pending',
                'new_status' => 'pending_review',
                'proof_count' => $proofCount,
                'created_at' => $submittedAt,
            ]);

            // История: approved
            TaskVerificationHistory::create([
                'task_response_id' => $response->id,
                'action' => TaskVerificationHistory::ACTION_APPROVED,
                'performed_by' => $manager->id,
                'previous_status' => 'pending_review',
                'new_status' => 'completed',
                'proof_count' => $proofCount,
                'created_at' => $approvedAt,
            ]);

            $count++;
        }

        return $count;
    }

    /**
     * Создать групповые задачи с shared proofs.
     */
    private function createGroupTasksWithSharedProofs($dealership, $manager, array $employees): int
    {
        if (count($employees) < 2) {
            return 0;
        }

        $count = 0;

        for ($i = 0; $i < 2; $i++) {
            $task = Task::create([
                'title' => 'Групповая задача: ' . fake()->randomElement(['Уборка территории', 'Подготовка к открытию', 'Инвентаризация']),
                'description' => 'Групповая задача с общими доказательствами',
                'creator_id' => $manager->id,
                'dealership_id' => $dealership->id,
                'appear_date' => Carbon::now()->subDays(rand(2, 5)),
                'deadline' => Carbon::now()->subDays(rand(0, 1)),
                'task_type' => 'group',
                'response_type' => 'completion_with_proof',
                'priority' => 'medium',
                'tags' => ['групповая', 'требует_фото'],
                'is_active' => false,
                'archived_at' => Carbon::now()->subHours(rand(12, 48)),
                'archive_reason' => 'completed',
            ]);

            // Назначаем всех сотрудников
            foreach ($employees as $emp) {
                TaskAssignment::create([
                    'task_id' => $task->id,
                    'user_id' => $emp->id,
                    'assigned_at' => $task->appear_date,
                ]);
            }

            // Создаём shared proofs (загружены менеджером)
            $sharedProofCount = rand(1, 2);
            for ($j = 0; $j < $sharedProofCount; $j++) {
                $this->createSharedProof($task);
            }

            // Создаём responses для всех сотрудников
            $completedAt = $task->archived_at;
            foreach ($employees as $emp) {
                TaskResponse::create([
                    'task_id' => $task->id,
                    'user_id' => $emp->id,
                    'status' => 'completed',
                    'responded_at' => $completedAt,
                    'verified_at' => $completedAt,
                    'verified_by' => $manager->id,
                ]);
            }

            $count++;
        }

        return $count;
    }

    /**
     * Создать просроченные задачи.
     */
    private function createOverdueTasks($dealership, $manager, array $employees): int
    {
        $count = 0;

        foreach (array_slice($employees, 0, 2) as $employee) {
            $task = Task::create([
                'title' => 'Просроченная задача: ' . fake()->sentence(3),
                'description' => 'Эта задача не была выполнена в срок',
                'creator_id' => $manager->id,
                'dealership_id' => $dealership->id,
                'appear_date' => Carbon::now()->subDays(rand(3, 7)),
                'deadline' => Carbon::now()->subDays(rand(1, 2)),
                'task_type' => 'individual',
                'response_type' => 'completion',
                'priority' => 'high',
                'tags' => ['просрочено'],
                'is_active' => true, // Активна, но просрочена
            ]);

            TaskAssignment::create([
                'task_id' => $task->id,
                'user_id' => $employee->id,
                'assigned_at' => $task->appear_date,
            ]);

            // Без response - задача просрочена и не выполнена

            $count++;
        }

        return $count;
    }

    /**
     * Создать stub файл доказательства.
     */
    private function createStubProof(TaskResponse $response): TaskProof
    {
        $mimeTypes = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'video/mp4' => 'mp4',
            'application/pdf' => 'pdf',
        ];

        $mimeType = fake()->randomElement(array_keys($mimeTypes));
        $extension = $mimeTypes[$mimeType];

        $fileSizes = [
            'image/jpeg' => [100000, 5000000],
            'image/png' => [50000, 3000000],
            'video/mp4' => [1000000, 50000000],
            'application/pdf' => [50000, 5000000],
        ];

        [$minSize, $maxSize] = $fileSizes[$mimeType];

        $prefixes = match ($mimeType) {
            'image/jpeg', 'image/png' => ['фото', 'снимок', 'IMG'],
            'video/mp4' => ['видео', 'VID'],
            'application/pdf' => ['документ', 'отчёт'],
            default => ['файл'],
        };

        return TaskProof::create([
            'task_response_id' => $response->id,
            'file_path' => 'task_proofs/demo/stub_' . Str::uuid() . '.' . $extension,
            'original_filename' => fake()->randomElement($prefixes) . '_' . fake()->dateTimeBetween('-7 days', 'now')->format('Ymd_His') . '.' . $extension,
            'mime_type' => $mimeType,
            'file_size' => fake()->numberBetween($minSize, $maxSize),
        ]);
    }

    /**
     * Создать shared proof для групповой задачи.
     */
    private function createSharedProof(Task $task): TaskSharedProof
    {
        $mimeTypes = [
            'image/jpeg' => 'jpg',
            'application/pdf' => 'pdf',
        ];

        $mimeType = fake()->randomElement(array_keys($mimeTypes));
        $extension = $mimeTypes[$mimeType];

        $fileSizes = [
            'image/jpeg' => [100000, 5000000],
            'application/pdf' => [100000, 10000000],
        ];

        [$minSize, $maxSize] = $fileSizes[$mimeType];

        $prefixes = match ($mimeType) {
            'image/jpeg' => ['групповое_фото', 'общий_снимок'],
            'application/pdf' => ['отчёт_группы', 'акт'],
            default => ['файл'],
        };

        return TaskSharedProof::create([
            'task_id' => $task->id,
            'file_path' => 'task_shared_proofs/demo/group_' . Str::uuid() . '.' . $extension,
            'original_filename' => fake()->randomElement($prefixes) . '_' . fake()->dateTimeBetween('-7 days', 'now')->format('Ymd') . '.' . $extension,
            'mime_type' => $mimeType,
            'file_size' => fake()->numberBetween($minSize, $maxSize),
        ]);
    }
}
