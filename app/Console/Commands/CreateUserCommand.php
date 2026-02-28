<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\AutoDealership;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class CreateUserCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'user:create';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Создать нового пользователя с указанной ролью';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Создание нового пользователя');
        $this->line('=========================');

        // Получаем данные пользователя
        $userData = $this->collectUserData();

        if (! $userData) {
            $this->error('Создание пользователя отменено');

            return self::FAILURE;
        }

        // Показываем подтверждение
        if (! $this->confirmCreation($userData)) {
            $this->error('Создание пользователя отменено');

            return self::FAILURE;
        }

        // Создаем пользователя
        try {
            $user = $this->createUser($userData);
            $this->displaySuccessMessage($user);

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Ошибка при создании пользователя: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Собирает данные пользователя через интерактивный ввод
     */
    private function collectUserData(): ?array
    {
        $userData = [];

        // Логин
        $userData['login'] = $this->askValidated(
            'Введите логин пользователя (мин 4 символа)',
            'login',
            ['required', 'string', 'min:4', 'max:100', 'unique:users,login']
        );

        // Полное имя
        $userData['full_name'] = $this->askValidated(
            'Введите полное имя пользователя (мин 2 символа)',
            'full_name',
            ['required', 'string', 'min:2', 'max:255']
        );

        // Телефон
        $userData['phone'] = $this->askValidated(
            'Введите номер телефона (начинается с +, любой формат)',
            'phone',
            ['required', 'string', 'regex:/^\+\d+$/', 'min:8', 'unique:users,phone'],
            function ($value) {
                // Очищаем телефон, сохраняя формат ввода
                return $this->normalizePhoneNumber($value);
            }
        );

        // Роль
        $userData['role'] = $this->selectRole();

        // Автосалон (опционально)
        $userData['dealership_id'] = $this->selectDealership();

        // Пароль
        $userData['password'] = $this->handlePassword();

        return $userData;
    }

    /**
     * Запрашивает ввод с валидацией
     */
    private function askValidated(string $question, string $field, array $rules, ?callable $transformer = null): mixed
    {
        do {
            $value = $this->ask($question);

            if ($transformer) {
                $value = $transformer($value);
            }

            $validator = Validator::make([$field => $value], [$field => $rules]);

            if ($validator->fails()) {
                foreach ($validator->errors()->get($field) as $error) {
                    $this->error("Ошибка: {$error}");
                }

                continue;
            }

            return $value;
        } while (true);
    }

    /**
     * Очищает номер телефона, сохраняя формат ввода пользователя
     */
    private function normalizePhoneNumber(string $phone): string
    {
        // Удаляем пробелы, дефисы, скобки, но сохраняем + и цифры
        return preg_replace('/[\s\-\(\)]/', '', trim($phone));
    }

    /**
     * Выбор роли пользователя
     */
    private function selectRole(): string
    {
        $this->info('\nДоступные роли:');
        $roles = [
            Role::OWNER->value => 'Владелец - полные права доступа',
            Role::MANAGER->value => 'Управляющий - управление пользователями и задачами',
            Role::OBSERVER->value => 'Смотрящий - доступ только для чтения',
            Role::EMPLOYEE->value => 'Сотрудник - базовые права (по умолчанию)',
        ];

        foreach ($roles as $value => $description) {
            $this->line("  {$value} - {$description}");
        }

        $roleChoices = array_keys($roles);
        $defaultRole = Role::EMPLOYEE->value;

        do {
            $role = $this->ask(
                "\nВыберите роль",
                $defaultRole
            );

            if (! in_array($role, $roleChoices)) {
                $this->error('Неверная роль. Выберите из: '.implode(', ', $roleChoices));

                continue;
            }

            return $role;
        } while (true);
    }

    /**
     * Выбор автосалона
     */
    private function selectDealership(): ?int
    {
        $dealerships = AutoDealership::orderBy('name')->get();

        if ($dealerships->isEmpty()) {
            $this->info('\nВ системе нет созданных автосалонов.');

            return null;
        }

        $this->info('\nДоступные автосалоны:');
        $this->table(['ID', 'Название', 'Адрес'], $dealerships->map(fn ($d) => [$d->id, $d->name, $d->address]));

        if (! $this->confirm('\nПривязать пользователя к автосалону?', false)) {
            return null;
        }

        do {
            $dealershipId = $this->ask('Введите ID автосалона');

            if (! is_numeric($dealershipId)) {
                $this->error('ID должен быть числом');

                continue;
            }

            $dealershipId = (int) $dealershipId;

            if (! $dealerships->contains('id', $dealershipId)) {
                $this->error('Автосалон с таким ID не найден');

                continue;
            }

            return $dealershipId;
        } while (true);
    }

    /**
     * Обработка пароля
     */
    private function handlePassword(): string
    {
        if ($this->confirm('Сгенерировать пароль автоматически?', true)) {
            $password = $this->generatePassword();
            $this->info("Сгенерированный пароль: {$password}");

            return $password;
        }

        do {
            $password = $this->secret('Введите пароль (мин 8 символов, содержит заглавную, строчную буквы и цифры)');

            $validator = Validator::make(['password' => $password], [
                'password' => [
                    'required',
                    'string',
                    'min:8',
                    'regex:/[A-Z]/',      // Заглавная буква
                    'regex:/[a-z]/',      // Строчная буква
                    'regex:/[0-9]/',      // Цифра
                ],
            ], [
                'password.regex' => 'Пароль должен содержать как минимум одну заглавную букву, одну строчную букву и одну цифру',
            ]);

            if ($validator->fails()) {
                foreach ($validator->errors()->get('password') as $error) {
                    $this->error("Ошибка: {$error}");
                }

                continue;
            }

            $confirmPassword = $this->secret('Подтвердите пароль');

            if ($password !== $confirmPassword) {
                $this->error('Пароли не совпадают');

                continue;
            }

            return $password;
        } while (true);
    }

    /**
     * Генерирует надежный пароль
     */
    private function generatePassword(): string
    {
        $uppercase = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $lowercase = 'abcdefghijklmnopqrstuvwxyz';
        $digits = '0123456789';
        $special = '!@#$%^&*';

        $all = $uppercase.$lowercase.$digits.$special;
        $password = '';

        // Гарантируем наличие каждого типа символов
        $password .= $uppercase[rand(0, strlen($uppercase) - 1)];
        $password .= $lowercase[rand(0, strlen($lowercase) - 1)];
        $password .= $digits[rand(0, strlen($digits) - 1)];
        $password .= $special[rand(0, strlen($special) - 1)];

        // Добавляем еще символы до длины 12
        for ($i = 4; $i < 12; $i++) {
            $password .= $all[rand(0, strlen($all) - 1)];
        }

        return str_shuffle($password);
    }

    /**
     * Показывает подтверждение создания пользователя
     */
    private function confirmCreation(array $userData): bool
    {
        $this->info('\nПроверьте данные пользователя:');
        $this->line("Логин: {$userData['login']}");
        $this->line("Имя: {$userData['full_name']}");
        $this->line("Телефон: {$userData['phone']}");
        $this->line("Роль: {$userData['role']}");

        if ($userData['dealership_id']) {
            $dealership = AutoDealership::find($userData['dealership_id']);
            $this->line("Автосалон: {$dealership->name} (ID: {$dealership->id})");
        } else {
            $this->line('Автосалон: Не указан');
        }

        return $this->confirm('\nСоздать пользователя?', true);
    }

    /**
     * Создает пользователя в базе данных
     */
    private function createUser(array $userData): User
    {
        return User::create([
            'login' => $userData['login'],
            'full_name' => $userData['full_name'],
            'phone' => $userData['phone'],
            'role' => $userData['role'],
            'dealership_id' => $userData['dealership_id'],
            'password' => Hash::make($userData['password']),
        ]);
    }

    /**
     * Показывает сообщение об успешном создании
     */
    private function displaySuccessMessage(User $user): void
    {
        $this->info('\n✅ Пользователь успешно создан!');
        $this->info('ID пользователя: '.$user->id);
        $this->info('Логин: '.$user->login);
        $this->info('Роль: '.\App\Enums\Role::tryFromString($user->role)?->label() ?? $user->role);

        if ($user->dealership) {
            $this->info('Автосалон: '.$user->dealership->name);
        }

        $this->info('\n📱 Следующий шаг:');
        $this->line('1. Пользователь должен найти бота в Telegram');
        $this->line('2. Нажать /start и поделиться номером телефона');
        $this->line('3. Система автоматически свяжет Telegram аккаунт с профилем');
        $this->line('4. Пользователь получит доступ к функциям бота согласно своей роли');
    }
}
