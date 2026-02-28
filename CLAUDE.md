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
├── Helpers/TimeHelper.php     # UTC: nowUtc(), toIsoZulu()
└── Validation/FileValidation/ # Magic bytes проверка
routes/api.php                 # 50+ endpoints, base: /api/v1
tests/Feature/                 # 968 Pest тестов
```

## Конвенции

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

### Form Requests (обязательно)

```php
// app/Http/Requests/Api/V1/StoreTaskRequest.php
// Валидация ТОЛЬКО здесь, не в контроллере через $request->validate()
public function rules(): array
{
    return ['title' => 'required|string|max:255'];
}
```

### Eager loading (обязательно)

```php
// ПРАВИЛЬНО: предзагрузка для N+1 prevention
$tasks = Task::with(['creator', 'assignments.user', 'responses.proofs'])->get();

// НЕПРАВИЛЬНО: ленивая загрузка вызовет N+1
$tasks = Task::all();
foreach ($tasks as $task) { $task->creator->name; }
```

### Форматирование ответов (API Resources)

```php
// Используй API Resources для сериализации (гарантируют UTC даты с Z суффиксом)
// Доступны: TaskResource, TaskResponseResource, TaskProofResource, TaskSharedProofResource,
//           TaskDelegationResource, TaskGeneratorResource, CalendarDayResource, UserResource, ShiftResource

// Одиночный ресурс (оборачивает в {"data": {...}})
return new TaskResource($task);

// С дополнительными полями (message и т.д.)
return (new TaskResource($task))->additional(['message' => 'Задача создана'])->response();

// Сырой массив БЕЗ обёртки data (для совместимости с существующими форматами)
return response()->json(TaskResource::make($task)->resolve());

// Для пагинации (сохраняет формат Laravel paginator)
$tasks->getCollection()->transform(fn ($t) => TaskResource::make($t)->resolve());
```

### Даты (TimeHelper)

```php
use App\Helpers\TimeHelper;

$now = TimeHelper::nowUtc();                          // Carbon UTC
$iso = TimeHelper::toIsoZulu($carbon);                // "2024-01-15T10:30:00Z"
$boundaries = TimeHelper::dayBoundariesForTimezone($dealership->timezone);  // День в timezone дилера
```

## Jobs (RabbitMQ)

| Job | Очередь | Ответственность |
|-----|---------|----------------|
| ProcessTaskGeneratorsJob | task_generators | Генерация задач из шаблонов (5 мин) |
| StoreTaskProofsJob | proof_upload | Асинхронное сохранение файлов доказательств |
| StoreTaskSharedProofsJob | shared_proof_upload | Общие файлы для group tasks |
| DeleteProofFileJob | file_cleanup | Удаление файлов из storage |

## Ключевые сервисы

| Сервис | Ответственность |
|--------|----------------|
| TaskService | CRUD задач, дубликаты, syncAssignments |
| TaskFilterService | Фильтрация + пагинация (date_range, status, priority, search) |
| TaskProofService | Загрузка/удаление файлов. Приватное хранилище + signed URLs (60 мин) |
| TaskVerificationService | approve/reject. При reject — удаляет файлы, пишет в VerificationHistory |
| FileValidation/ | Magic bytes проверка (не только расширение) |

## Хранилище файлов

- Disk: `task_proofs` → `storage/app/private/task_proofs/`
- Доступ: только signed URLs (60 мин), авторизация при генерации URL
- Лимиты: 5 файлов, 200MB total. Изображения 5MB, видео 100MB, документы 50MB

## Команды (специфичные для backend)

```bash
php artisan test                            # Все тесты
php artisan test --filter=TaskControllerTest # Конкретный
composer test:coverage                       # С покрытием (min 50%)
php vendor/bin/pint                          # Форматирование кода
php vendor/bin/pint --test                   # Проверка стиля
```

## Запрещено

- MySQL-совместимый SQL (GROUP BY без агрегации, IFNULL вместо COALESCE)
- Хранить даты не в UTC
- Обращаться к storage напрямую — только через `task_proofs` disk + signed URLs
- Логика в контроллерах — выносить в Services
- Модели без eager loading в контроллерах
- SoftDeletes без учёта при запросах (User, Task, TaskAssignment)
- N+1 queries — всегда проверяй через Laravel Debugbar
