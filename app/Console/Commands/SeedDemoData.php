<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Database\Seeders\AdminSeeder;
use Database\Seeders\AuditLogSeeder;
use Database\Seeders\DealershipSeeder;
use Database\Seeders\ShiftSeeder;
use Database\Seeders\TaskSeeder;
use Illuminate\Console\Command;

class SeedDemoData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:seed-demo
        {--full : Запустить полный сид без интерактивности}
        {--days=30 : Количество дней истории}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Создание демо-данных для TaskMate (интерактивно или с флагом --full)';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $this->info('╔══════════════════════════════════════════╗');
        $this->info('║       TaskMate Demo Data Seeder          ║');
        $this->info('╚══════════════════════════════════════════╝');
        $this->newLine();

        $fullMode = $this->option('full');
        $days = (int) $this->option('days');

        if ($days < 0) {
            $this->error('Количество дней не может быть отрицательным.');
            return;
        }

        // Устанавливаем дни для всех сидеров
        TaskSeeder::$historyDays = $days;
        ShiftSeeder::$historyDays = $days;
        AuditLogSeeder::$historyDays = $days;

        if ($fullMode) {
            $this->runFullSeed();
        } else {
            $this->runInteractiveSeed();
        }

        $this->newLine();
        $this->info('╔══════════════════════════════════════════╗');
        $this->info('║     Демо-данные успешно созданы!         ║');
        $this->info('╚══════════════════════════════════════════╝');
        $this->newLine();
        $this->info('Учётные записи для входа:');
        $this->table(
            ['Роль', 'Логин', 'Пароль'],
            [
                ['Owner (Админ)', 'admin', 'password'],
                ['Manager', 'manager1, manager2, manager3', 'password'],
                ['Employee', 'emp1_1, emp1_2, emp1_3...', 'password'],
                ['Observer', 'obs1, obs2, obs3', 'password'],
            ]
        );
    }

    /**
     * Запустить полный сид без интерактивности.
     */
    private function runFullSeed(): void
    {
        $this->info('Режим: полный сид (--full)');
        $this->info('Дней истории: ' . TaskSeeder::$historyDays);
        $this->newLine();

        $this->runSeeder('Создание администратора...', AdminSeeder::class);
        $this->runSeeder('Создание автосалонов и пользователей...', DealershipSeeder::class);
        $this->runSeeder('Создание истории смен...', ShiftSeeder::class);
        $this->runSeeder('Создание задач и истории...', TaskSeeder::class);
        $this->runSeeder('Создание записей аудита...', AuditLogSeeder::class);
    }

    /**
     * Запустить интерактивный сид.
     */
    private function runInteractiveSeed(): void
    {
        $this->info('Режим: интерактивный');
        $this->newLine();

        // 1. Admin User
        if ($this->confirm('Создать администратора?', true)) {
            $this->runSeeder('Создание администратора...', AdminSeeder::class);
        }

        // 2. Dealerships & Users (включая settings и notification settings)
        if ($this->confirm('Создать автосалоны, менеджеров, сотрудников и наблюдателей?', true)) {
            $this->runSeeder('Создание автосалонов и пользователей...', DealershipSeeder::class);
        }

        // 3. Shifts
        if ($this->confirm('Создать историю смен?', true)) {
            $days = $this->ask('Сколько дней истории смен создать?', (string) ShiftSeeder::$historyDays);
            ShiftSeeder::$historyDays = (int) $days;
            $this->runSeeder('Создание истории смен...', ShiftSeeder::class);
        }

        // 4. Tasks
        if ($this->confirm('Создать задачи и историю?', true)) {
            $days = $this->ask('Сколько дней истории задач создать?', (string) TaskSeeder::$historyDays);
            TaskSeeder::$historyDays = (int) $days;
            $this->runSeeder('Создание задач и истории...', TaskSeeder::class);
        }

        // 5. Audit Logs
        if ($this->confirm('Создать записи аудита?', true)) {
            AuditLogSeeder::$historyDays = TaskSeeder::$historyDays;
            $this->runSeeder('Создание записей аудита...', AuditLogSeeder::class);
        }
    }

    /**
     * Запустить сидер с сообщением.
     */
    private function runSeeder(string $message, string $seederClass): void
    {
        $this->info($message);
        $this->call('db:seed', [
            '--class' => $seederClass,
        ]);
        $this->newLine();
    }
}
