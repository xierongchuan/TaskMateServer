<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\AutoDealership;
use App\Models\ImportantLink;
use App\Models\NotificationSetting;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DealershipSeeder extends Seeder
{
    /**
     * Настройки автосалона по умолчанию.
     */
    private const DEFAULT_SETTINGS = [
        ['key' => 'shift_start_time', 'value' => '09:00', 'type' => 'time', 'description' => 'Время начала смены'],
        ['key' => 'shift_end_time', 'value' => '20:00', 'type' => 'time', 'description' => 'Время окончания смены'],
        ['key' => 'auto_archive_hours', 'value' => '24', 'type' => 'integer', 'description' => 'Часы до автоархивации завершённых задач'],
        ['key' => 'task_postpone_limit', 'value' => '3', 'type' => 'integer', 'description' => 'Максимальное количество переносов задачи'],
        ['key' => 'require_proof_for_completion', 'value' => '1', 'type' => 'boolean', 'description' => 'Требовать доказательства для завершения задач'],
        ['key' => 'allow_late_completion', 'value' => '1', 'type' => 'boolean', 'description' => 'Разрешить позднее выполнение задач'],
        ['key' => 'max_proof_files', 'value' => '5', 'type' => 'integer', 'description' => 'Максимальное количество файлов доказательств'],
        ['key' => 'proof_file_max_size_mb', 'value' => '200', 'type' => 'integer', 'description' => 'Максимальный размер файла доказательства (МБ)'],
    ];

    /**
     * Настройки уведомлений по умолчанию.
     */
    private const DEFAULT_NOTIFICATION_SETTINGS = [
        ['channel_type' => NotificationSetting::CHANNEL_TASK_ASSIGNED, 'is_enabled' => true, 'notification_offset' => null],
        ['channel_type' => NotificationSetting::CHANNEL_TASK_DEADLINE_30MIN, 'is_enabled' => true, 'notification_offset' => 30],
        ['channel_type' => NotificationSetting::CHANNEL_TASK_OVERDUE, 'is_enabled' => true, 'notification_offset' => null],
        ['channel_type' => NotificationSetting::CHANNEL_TASK_HOUR_LATE, 'is_enabled' => true, 'notification_offset' => 60],
        ['channel_type' => NotificationSetting::CHANNEL_SHIFT_LATE, 'is_enabled' => true, 'notification_offset' => 15],
        ['channel_type' => NotificationSetting::CHANNEL_TASK_POSTPONED, 'is_enabled' => true, 'notification_offset' => null],
        ['channel_type' => NotificationSetting::CHANNEL_SHIFT_REPLACEMENT, 'is_enabled' => true, 'notification_offset' => null],
        ['channel_type' => NotificationSetting::CHANNEL_DAILY_SUMMARY, 'is_enabled' => true, 'notification_time' => '20:00', 'notification_day' => null],
        ['channel_type' => NotificationSetting::CHANNEL_WEEKLY_REPORT, 'is_enabled' => true, 'notification_time' => '09:00', 'notification_day' => 'monday'],
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('Создание автосалонов и пользователей...');

        $dealerships = [
            [
                'name' => 'Автосалон Центр',
                'address' => 'Екатеринбург, ул. Малышева 1',
                'timezone' => '+05:00',
            ],
            [
                'name' => 'Автосалон Север',
                'address' => 'Екатеринбург, ул. Юнусабад 19',
                'timezone' => '+05:00',
            ],
            [
                'name' => 'Автосалон Люкс',
                'address' => 'Екатеринбург, ул. Чиланзар 5',
                'timezone' => '+05:00',
            ],
        ];

        foreach ($dealerships as $index => $data) {
            $dealership = AutoDealership::factory()->create($data);
            $this->command->info("Создан автосалон: {$dealership->name}");

            // Create Manager
            $managerLogin = 'manager' . ($index + 1);
            $manager = User::updateOrCreate(
                ['login' => $managerLogin],
                [
                    'full_name' => "Менеджер {$dealership->name}",
                    'password' => Hash::make('password'),
                    'role' => Role::MANAGER,
                    'dealership_id' => $dealership->id,
                    'phone' => '+7' . fake()->numerify('##########'),
                ]
            );
            $this->command->info(" - Менеджер: {$manager->login} / password");

            // Create Employees
            for ($i = 1; $i <= 3; $i++) {
                $empLogin = 'emp' . ($index + 1) . '_' . $i;
                $employee = User::updateOrCreate(
                    ['login' => $empLogin],
                    [
                        'full_name' => "Сотрудник {$i} ({$dealership->name})",
                        'password' => Hash::make('password'),
                        'role' => Role::EMPLOYEE,
                        'dealership_id' => $dealership->id,
                        'phone' => '+7' . fake()->numerify('##########'),
                    ]
                );
                $this->command->info(" - Сотрудник: {$employee->login} / password");
            }

            // Create Observer
            $observerLogin = 'obs' . ($index + 1);
            $observer = User::updateOrCreate(
                ['login' => $observerLogin],
                [
                    'full_name' => "Наблюдатель ({$dealership->name})",
                    'password' => Hash::make('password'),
                    'role' => Role::OBSERVER,
                    'dealership_id' => $dealership->id,
                    'phone' => '+7' . fake()->numerify('##########'),
                ]
            );
            $this->command->info(" - Наблюдатель: {$observer->login} / password");

            // Create Important Links
            ImportantLink::factory(5)->create([
                'dealership_id' => $dealership->id,
                'creator_id' => $manager->id,
            ]);
            $this->command->info(' - 5 важных ссылок');

            // Create Settings
            $this->createSettings($dealership);
            $this->command->info(' - ' . count(self::DEFAULT_SETTINGS) . ' настроек');

            // Create Notification Settings
            $this->createNotificationSettings($dealership);
            $this->command->info(' - ' . count(self::DEFAULT_NOTIFICATION_SETTINGS) . ' настроек уведомлений');
        }
    }

    /**
     * Создать настройки автосалона.
     */
    private function createSettings(AutoDealership $dealership): void
    {
        foreach (self::DEFAULT_SETTINGS as $settingData) {
            Setting::updateOrCreate(
                [
                    'dealership_id' => $dealership->id,
                    'key' => $settingData['key'],
                ],
                [
                    'value' => $settingData['value'],
                    'type' => $settingData['type'],
                    'description' => $settingData['description'],
                ]
            );
        }
    }

    /**
     * Создать настройки уведомлений автосалона.
     */
    private function createNotificationSettings(AutoDealership $dealership): void
    {
        foreach (self::DEFAULT_NOTIFICATION_SETTINGS as $notificationData) {
            NotificationSetting::updateOrCreate(
                [
                    'dealership_id' => $dealership->id,
                    'channel_type' => $notificationData['channel_type'],
                ],
                [
                    'is_enabled' => $notificationData['is_enabled'],
                    'notification_time' => $notificationData['notification_time'] ?? null,
                    'notification_day' => $notificationData['notification_day'] ?? null,
                    'notification_offset' => $notificationData['notification_offset'] ?? null,
                    'recipient_roles' => ['employee', 'manager'],
                ]
            );
        }
    }
}
