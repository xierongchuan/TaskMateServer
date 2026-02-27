# TaskMateServer — CLAUDE.md

Laravel REST API для TaskMate. Общие правила — в [../CLAUDE.md](../CLAUDE.md).

## Стек

Laravel 12 + PHP 8.4 + PostgreSQL 18 + Sanctum 4.2 + RabbitMQ (laravel-queue-rabbitmq 14) + Valkey (Predis 3.1) + Pest 4.

## Структура

```
app/
├── Http/Controllers/Api/V1/   # 18 контроллеров
├── Http/Requests/Api/V1/      # Form Requests (валидация)
├── Models/                    # 19 Eloquent моделей
├── Services/                  # 11 сервисов (бизнес-логика)
├── Jobs/                      # 4 Jobs (RabbitMQ)
├── Console/Commands/          # Artisan-команды (архивация, очистка)
├── Enums/                     # Role, TaskStatus, TaskType, Priority...
├── Traits/                    # Auditable, HasDealershipAccess, ApiResponses
└── Helpers/TimeHelper.php     # UTC: nowUtc(), toIsoZulu()
routes/api.php                 # 50+ endpoints, base: /api/v1
tests/Feature/                 # 30+ тестов
```

## Conventions

### Controller → Service → Model

```php
// ПРАВИЛЬНО: бизнес-логика в сервисе, контроллер тонкий
public function store(StoreTaskRequest $request): JsonResponse
{
    $task = $this->taskService->createTask($request->validated(), $request->user());
    return response()->json(['data' => $task->toApiArray()], 201);
}

// НЕПРАВИЛЬНО: логика в контроллере
public function store(Request $request): JsonResponse
{
    $task = Task::create($request->all()); // Нет валидации, нет сервиса
}
```

### Форматирование ответов

```php
// Модели используют toApiArray() — НЕ API Resources (кроме User, Shift)
// toApiArray() гарантирует UTC даты с Z суффиксом
$task->toApiArray(); // appear_date: "2024-01-15T10:30:00Z"
```

### Eager loading — обязательно

```php
// ПРАВИЛЬНО: предзагрузка для N+1 prevention
$tasks = Task::with(['creator', 'assignments.user', 'responses.proofs'])->get();

// НЕПРАВИЛЬНО: ленивая загрузка вызовет N+1
$tasks = Task::all();
foreach ($tasks as $task) { $task->creator->name; } // N+1!
```

### Валидация

```php
// Всегда через Form Requests в app/Http/Requests/Api/V1/
// НЕ валидировать в контроллере через $request->validate()
```

### Даты

```php
// Все даты — UTC. Используй TimeHelper.
use App\Helpers\TimeHelper;
$now = TimeHelper::nowUtc();
$iso = TimeHelper::toIsoZulu($carbon); // "2024-01-15T10:30:00Z"
```

## Ключевые сервисы

| Сервис | Ответственность |
|--------|----------------|
| TaskService | CRUD задач, проверка дубликатов, syncAssignments |
| TaskFilterService | Фильтрация + пагинация (date_range, status, priority, search) |
| TaskProofService | Загрузка/удаление файлов. Приватное хранилище + signed URLs (60 мин) |
| TaskVerificationService | approve/reject. При reject — удаляет файлы, пишет в VerificationHistory |
| FileValidation/ | Magic bytes проверка (не только расширение) |

## Jobs (RabbitMQ)

| Job | Очередь | Что делает |
|-----|---------|-----------|
| ProcessTaskGeneratorsJob | task_generators | Генерация задач из шаблонов (каждые 5 мин) |
| StoreTaskProofsJob | proof_upload | Асинхронное сохранение файлов доказательств |
| StoreTaskSharedProofsJob | shared_proof_upload | Общие файлы для group tasks |
| DeleteProofFileJob | file_cleanup | Удаление файлов из storage |

## Запрещено

- MySQL-совместимый SQL (GROUP BY без агрегации, IFNULL вместо COALESCE)
- Хранить даты не в UTC
- Обращаться к storage напрямую — только через `task_proofs` disk + signed URLs
- Логика в контроллерах — выносить в Services
- Модели без eager loading в контроллерах
- SoftDeletes без учёта при запросах (User, Task, TaskAssignment)

## Тестирование

```bash
php artisan test                            # Все тесты
php artisan test --filter=TaskControllerTest # Конкретный
composer test:coverage                       # С покрытием (min 50%)
vendor/bin/pint                              # Форматирование
vendor/bin/pint --test                       # Проверка стиля
```

## Хранилище файлов

- Disk: `task_proofs` → `storage/app/private/task_proofs/`
- Доступ: только signed URLs (60 мин), авторизация при генерации URL
- Лимиты: 5 файлов, 200MB total. Изображения 5MB, видео 100MB, документы 50MB
