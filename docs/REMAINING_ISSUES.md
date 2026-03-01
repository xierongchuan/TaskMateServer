# Нерешённые проблемы TaskMateServer

> Дата аудита: 2026-03-01
> Аудит выполнен: 4 агента (Security, Performance, Architecture, Code Reuse)
> Исправлено в коммите: ~35 проблем (security, performance quick wins, architecture quick wins)
> **Остаётся: ~35 проблем**, описанных ниже

---

## 1. CRITICAL — Производительность

### 1.1 ReportController N+1: 4 запроса на каждого сотрудника

**Файлы:** `app/Http/Controllers/Api/V1/ReportController.php` (строки 108-116), `app/Services/EmployeeStatsService.php`

**Проблема:** Метод `index()` загружает всех сотрудников через `->get()`, затем для каждого вызывает `EmployeeStatsService::getStats()`, который выполняет **4 отдельных DB-запроса** (задачи, общие смены, опоздания, пропуски). При 50 сотрудниках = 201 запрос. При 200 = 801 запрос.

```php
// ReportController.php:108-116
$employees = $employeesQuery->get();
$employeesPerformance = $employees->map(
    fn ($employee) => $this->employeeStatsService->getStats($employee, $from, $to)
    // ^ 4 запроса на каждого сотрудника
);
```

**Решение:** Переписать `EmployeeStatsService` на batch-подход:
1. Получить все задачи для всех сотрудников одним запросом, сгруппировать по `user_id`
2. Получить статистику смен одним aggregate запросом с `GROUP BY user_id`:
```php
$shiftStats = Shift::whereIn('user_id', $employeeIds)
    ->whereBetween('shift_start', [$from, $to])
    ->selectRaw('user_id, COUNT(*) as total,
        SUM(CASE WHEN late_minutes > 0 THEN 1 ELSE 0 END) as late,
        AVG(CASE WHEN late_minutes > 0 THEN late_minutes END) as avg_late')
    ->groupBy('user_id')
    ->get()->keyBy('user_id');
```
3. Собрать результат в PHP из уже загруженных данных

**Трудоёмкость:** L (переписка EmployeeStatsService + адаптация ReportController + тесты)

---

### 1.2 ReportController: 3 запроса на каждый день в цикле

**Файл:** `app/Http/Controllers/Api/V1/ReportController.php` (строки 119-156)

**Проблема:** Секция daily stats итерирует день за днём от `$from` до `$to` и выполняет **3 отдельных DB-запроса на каждый день** (completed, overdue, late shifts). Отчёт за 30 дней = 90 запросов. За 90 дней = 270 запросов.

```php
$current = $from->copy();
while ($current <= $to) {
    $dayCompleted = $dayCompletedQuery->count();   // запрос 1
    $dayOverdue   = $dayOverdueQuery->count();     // запрос 2
    $dayLateShifts = $dayLateShiftsQuery->count(); // запрос 3
    $current->addDay();
}
```

**Решение:** Заменить цикл на aggregate GROUP BY:
```php
$completedByDay = DB::table('task_responses')
    ->join('tasks', 'tasks.id', '=', 'task_responses.task_id')
    ->where('task_responses.status', 'completed')
    ->whereBetween('task_responses.responded_at', [$from, $to])
    ->selectRaw("DATE(responded_at) as day, COUNT(DISTINCT tasks.id) as cnt")
    ->groupBy('day')->pluck('cnt', 'day');

$lateShiftsByDay = Shift::whereBetween('shift_start', [$from, $to])
    ->where('late_minutes', '>', 0)
    ->selectRaw("DATE(shift_start) as day, COUNT(*) as cnt")
    ->groupBy('day')->pluck('cnt', 'day');
```

**Трудоёмкость:** M (рефакторинг ~40 строк, нужны тесты на корректность агрегации)

---

### 1.3 CalendarDay::isHoliday: 3-4 запроса на каждый дилерский центр в Dashboard

**Файлы:** `app/Services/DashboardService.php` (строка 260), `app/Models/CalendarDay.php` (строки 138-166)

**Проблема:** `getDealershipShiftStats()` итерирует по всем дилерствам и вызывает `CalendarDay::isHoliday()` внутри цикла. Каждый вызов: `getTimezone()` → `AutoDealership::find()` (1 запрос) + `hasOwnCalendarForYear()` (1 запрос) + lookup записи (1-2 запроса). 5 дилерств = 15-20 дополнительных запросов.

**Решение:** Предзагрузить все данные о праздниках одним запросом ДО цикла:
```php
$today = TimeHelper::nowUtc()->toDateString();
$dealershipIds = $dealerships->pluck('id');

// Один запрос вместо 3*N
$holidayMap = CalendarDay::whereDate('date', $today)
    ->where(fn($q) => $q->whereIn('dealership_id', $dealershipIds)->orWhereNull('dealership_id'))
    ->get()
    ->groupBy('dealership_id');

// В цикле:
$isHoliday = isset($holidayMap[$dealership->id])
    ? $holidayMap[$dealership->id]->first()?->type === 'holiday'
    : ($holidayMap[null] ?? collect())->first()?->type === 'holiday';
```

**Трудоёмкость:** M (рефакторинг DashboardService + адаптация CalendarDay интерфейса)

---

## 2. CRITICAL — Архитектура

### 2.1 God Method: TaskController::updateStatus() — 280 строк, 8 ответственностей

**Файл:** `app/Http/Controllers/Api/V1/TaskController.php` (строки 225-516)

**Проблема:** Один метод совмещает:
1. Проверку существования задачи
2. Авторизацию через Policy
3. Валидацию state machine переходов
4. Проверку файлов доказательств
5. Проверку требования открытой смены (hybrid mode)
6. Транзакционное обновление (switch по 4 веткам)
7. Авто-отмену делегаций
8. Dispatch событий и асинхронных Jobs

**Решение:** Извлечь `TaskStatusService`:
```php
// TaskController — станет тонким
public function updateStatus(UpdateTaskStatusRequest $request, $id): JsonResponse
{
    $task = Task::with(['assignments'])->findOrFail($id);
    $this->authorize('updateStatus', $task);

    $result = $this->taskStatusService->transition(
        $task, $request->user(), $request->validated(), $request
    );

    return response()->json(TaskResource::make($result)->resolve());
}

// TaskStatusService — вся логика здесь
class TaskStatusService
{
    public function transition(Task $task, User $user, array $data, Request $request): Task
    {
        $this->validateTransition($task, $user, $data['status']);
        $this->validateProofs($task, $user, $data, $request);
        $shiftContext = $this->resolveShiftContext($task, $user, $data['status']);

        DB::transaction(fn() => $this->executeTransition(...));

        $this->postTransitionActions($task, $user, $data, $request);

        return $task->refresh()->load([...]);
    }
}
```

**Трудоёмкость:** L (280 строк перенос, 22+ теста затрагиваются, нужна тщательная верификация каждого edge case)

**Риск:** ВЫСОКИЙ — метод содержит множество неочевидных взаимосвязей (resubmission flow, completeForAll, shared proofs async). Рекомендуется пошаговый подход: сначала выделить отдельные private-методы внутри контроллера, убедиться что тесты проходят, затем переносить в сервис.

---

## 3. HIGH — Архитектура: бизнес-логика в контроллерах

### 3.1 ReportController::index() — 250 строк бизнес-логики

**Файл:** `app/Http/Controllers/Api/V1/ReportController.php` (строки 25-250)

**Проблема:** Метод содержит inline-запросы к БД, ручную агрегацию, итерацию по дням. Вся эта логика — бизнес-логика, не ответственность контроллера.

**Решение:** Создать `ReportService` с методами:
- `getSummaryStatistics(int $dealershipId, Carbon $from, Carbon $to): array`
- `getEmployeesPerformance(int $dealershipId, Carbon $from, Carbon $to): Collection`
- `getDailyStats(int $dealershipId, Carbon $from, Carbon $to): array`
- `getTopIssues(int $dealershipId, Carbon $from, Carbon $to): array`

**Трудоёмкость:** M (извлечение в сервис, адаптация тестов)

---

### 3.2 TaskGeneratorController: store/update/statistics содержат бизнес-логику

**Файл:** `app/Http/Controllers/Api/V1/TaskGeneratorController.php`

**Проблема:**
- `store()` (строки 92-156): прямые `TaskGenerator::create()` и `TaskGeneratorAssignment::create()` вместо сервиса
- `update()` (строки 161-282): 120 строк ручного `if (isset($validated['field']))` маппинга
- `statistics()` (строки 469-564): приватные методы `computeStatsForTasks()` и `computeAverageCompletionTime()` — чистая бизнес-логика

**Решение:** Создать `TaskGeneratorService`:
- `createGenerator(array $data, User $creator): TaskGenerator`
- `updateGenerator(TaskGenerator $generator, array $data): TaskGenerator`
- `getStatistics(TaskGenerator $generator): array`

**Трудоёмкость:** M

---

### 3.3 UserApiController: 100+ строк inline авторизации + отсутствие UserService/UserPolicy

**Файл:** `app/Http/Controllers/Api/V1/UserApiController.php`

**Проблема:**
- `update()` (строки 235-378): 7 отдельных security check блоков, проверка пароля, ручная сборка `$updateData`
- `store()` (строки 386-446): 3 inline security checks + прямой `User::create()`
- `destroy()` (строки 453-547): 5 inline security checks, проверка связанных данных
- `stats()` (строка 224): Service Locator `app(EmployeeStatsService::class)` — **уже исправлен**

**Решение:**
1. Создать `UserPolicy` с методами `create()`, `update()`, `delete()`
2. Создать `UserService` с методами `createUser()`, `updateUser()`, `deleteUser()`
3. Контроллер: `$this->authorize('update', $targetUser)` + `$this->userService->updateUser(...)`

**Трудоёмкость:** L

---

### 3.4 Switch-based state machine в TaskController

**Файл:** `app/Http/Controllers/Api/V1/TaskController.php` (строки 331-431, 585-609)

**Проблема:** Большой `switch ($status)` с ветками для каждого статуса. `isValidStatusTransition()` — hardcoded матрица переходов. При добавлении нового статуса нужно модифицировать оба switch'а.

**Решение (после извлечения TaskStatusService):** Реализовать `TaskStatusMachine`:
```php
class TaskStatusMachine
{
    private const TRANSITIONS = [
        null => ['pending', 'acknowledged', 'pending_review', 'completed'],
        'pending' => ['acknowledged', 'pending_review', 'completed'],
        'acknowledged' => ['pending_review', 'completed'],
        'rejected' => ['pending_review', 'completed'],
        // ...
    ];

    public function canTransition(?string $from, string $to, User $user): bool { ... }
    public function execute(Task $task, string $to, array $context): void { ... }
}
```

**Трудоёмкость:** M (после 2.1)

---

## 4. HIGH — Унификация API ответов

### 4.1 Четыре разных формата ответов API

**Проблема:** Контроллеры используют 4 разных формата:
1. `{'success': true, 'data': ...}` — ShiftController, SettingsController, TaskGeneratorController
2. `TaskResource::make($task)->resolve()` — TaskController (плоский объект)
3. `TaskResource + ->additional(['message' => ...])` — TaskVerificationController
4. `response()->json($model)` — DealershipController, ImportantLinkController (**частично исправлен**: создан DealershipResource, ImportantLinkResource)

**Трейт `ApiResponses`** (147 строк, 8 методов) существует, но **ни один контроллер** его не использует.

**Решение:** Выбрать один формат и применить ко всем:

**Вариант A** — через API Resources (рекомендуется):
```php
// Одиночный ресурс
return new TaskResource($task); // → {"data": {...}}

// С сообщением
return (new TaskResource($task))
    ->additional(['message' => 'Задача создана'])
    ->response()->setStatusCode(201);

// Список с пагинацией
return TaskResource::collection($tasks); // → {"data": [...], "meta": {...}}
```

**Вариант B** — через ApiResponses trait:
```php
return $this->successResponse(TaskResource::make($task)->resolve(), 'Задача создана', 201);
return $this->errorResponse('Задача не найдена', 404);
```

**Трудоёмкость:** L (затрагивает ВСЕ 18 контроллеров + фронтенд, нужна координация)

---

## 5. HIGH — Безопасность (ops)

### 5.1 `.env` с APP_DEBUG=true и credentials закоммичен в Git

**Файл:** `.env`

**Проблема:** Файл `.env` с `APP_DEBUG=true`, `DB_PASSWORD=secret`, `RABBITMQ_PASSWORD=taskmate_secret`, `APP_KEY` находится в Git. Любой с доступом к репозиторию видит все пароли.

**Решение:**
1. Добавить `.env` в `.gitignore`
2. `git rm --cached .env`
3. Ротировать ВСЕ секреты: `DB_PASSWORD`, `RABBITMQ_PASSWORD`, `APP_KEY`
4. Создать `.env.example` без реальных значений
5. В production: `APP_DEBUG=false`

**Трудоёмкость:** S (но требует координации с ops)

---

## 6. MEDIUM — Переиспользование кода

### 6.1 Дублирование валидации файлов в TaskProofService

**Файл:** `app/Services/TaskProofService.php`

**Проблема:** Методы `storeProofs()` и `storeProofsAsync()` содержат идентичные 30 строк валидации лимитов файлов (количество, размер, типы). Copy-paste.

**Решение:** Извлечь `private function validateFileLimits(TaskResponse $response, array $files): void`

**Трудоёмкость:** S

---

### 6.2 Генерация путей файлов дублируется в 3 местах

**Файлы:** `TaskProofService`, `StoreTaskProofsJob`, `StoreTaskSharedProofsJob`

**Проблема:** Паттерн `"dealership_{$dealershipId}/task_{$taskId}/..."` собирается заново в каждом файле.

**Решение:** Извлечь `TaskProofPathGenerator::generatePath(int $dealershipId, int $taskId, string $filename): string`

**Трудоёмкость:** S

---

### 6.3 Find-or-404 паттерн дублируется 15+ раз

**Проблема:** По всем контроллерам:
```php
$task = Task::find($id);
if (!$task) {
    return response()->json(['message' => '... не найдена'], 404);
}
```

**Решение:** Использовать `findOrFail($id)` — Laravel автоматически возвращает 404. Или создать трейт `FindsModelsOrFails` с методом `findOrNotFound(string $modelClass, int $id)`.

**Трудоёмкость:** S (механическая замена)

---

### 6.4 Паттерн парсинга dealership_id из query дублируется 8+ раз

**Проблема:**
```php
$dealershipId = $request->query('dealership_id') !== null && $request->query('dealership_id') !== ''
    ? (int) $request->query('dealership_id')
    : null;
```

Этот блок повторяется в SettingsController (5 раз), DashboardController, ReportController и др.

**Решение:** Добавить метод в `HasDealershipAccess`:
```php
protected function parseDealershipId(Request $request): ?int
{
    return $request->filled('dealership_id') ? $request->integer('dealership_id') : null;
}
```

**Трудоёмкость:** S

---

### 6.5 Дублирование "completed" и "overdue" SQL-паттернов

**Файлы:** `DashboardService` (строки 117-149, 306-335), `TaskFilterService::applyStatusFilter()`, `ArchiveCompletedTasks`

**Проблема:** Один и тот же сложный SQL-запрос для определения "completed" задач (с учётом individual/group) дублируется 3 раза. Аналогично "overdue" — тоже 3 раза.

**Решение:** Создать scope'ы на модели Task:
```php
// app/Models/Task.php
public function scopeCompleted(Builder $query): Builder { ... }
public function scopeOverdue(Builder $query): Builder { ... }
```

**Трудоёмкость:** M (сложная SQL-логика с учётом group/individual)

---

### 6.6 Четыре Form Requests не наследуют BaseApiRequest

**Файлы:** `UpdateArchiveConfigRequest`, `UpdateNotificationConfigRequest`, `UpdateShiftConfigRequest`, `UpdateTaskConfigRequest`

**Проблема:** Наследуют `FormRequest` напрямую, а не `BaseApiRequest` (который включает стандартную обработку ошибок валидации).

**Решение:** Заменить `extends FormRequest` на `extends BaseApiRequest`.

**Трудоёмкость:** S

---

### 6.7 Дублирование task_type/assignments валидации в 4 местах

**Файлы:** `StoreTaskRequest`, `UpdateTaskRequest`, `TaskGeneratorController::store()`, `TaskGeneratorController::update()`

**Проблема:** Правило "для individual задач — ровно 1 assignee, для group — минимум 2" реализовано в 4 местах с минимальными вариациями.

**Решение:** Кастомное Rule:
```php
class ValidAssignmentsForTaskType implements ValidationRule
{
    public function __construct(private string $taskType) {}
    public function validate(string $attribute, mixed $value, Closure $fail): void { ... }
}
```

**Трудоёмкость:** S

---

## 7. MEDIUM — Архитектура (прочее)

### 7.1 HasDealershipAccess: trait возвращает JsonResponse из сервисного слоя

**Файл:** `app/Traits/HasDealershipAccess.php`

**Проблема:** Trait используется и в контроллерах, и в сервисах (`TaskService`, `TaskFilterService`). Метод `validateDealershipAccess()` возвращает `JsonResponse` — это concern контроллера, не сервиса. Когда сервис использует этот trait, он получает возможность генерировать HTTP responses из сервисного слоя.

**Решение:** Разделить:
- `DealershipAccessService` (возвращает `bool` / throws exceptions)
- `HasDealershipAccess` trait (для контроллеров, использует сервис и генерирует responses)

**Трудоёмкость:** M

---

### 7.2 Events диспатчатся из контроллера

**Файл:** `app/Http/Controllers/Api/V1/TaskController.php` (строка 463)

**Проблема:** `event(new TaskAssigned(...))` вызывается и из `TaskService::createTask()` (при создании) и из `TaskController::updateStatus()` (при сбросе в pending). Логика публикации событий распределена между слоями.

**Решение:** Все события должны публиковаться из сервисного слоя. После извлечения TaskStatusService (п. 2.1) — перенести dispatch в сервис.

**Трудоёмкость:** S (после 2.1)

---

### 7.3 TaskGeneratorController использует 'view' policy для всех операций

**Файл:** `app/Http/Controllers/Api/V1/TaskGeneratorController.php`

**Проблема:** `$this->authorize('view', $generator)` используется для update, destroy, pause, resume, statistics. Просмотр и удаление — разные действия.

**Решение:** Расширить `TaskGeneratorPolicy` методами `update()`, `delete()`, `pause()`, `viewStatistics()`.

**Трудоёмкость:** S

---

### 7.4 PublishTaskEventSubscriber: статическое AMQP-соединение

**Файл:** `app/Listeners/PublishTaskEventSubscriber.php` (строки 30-31)

**Проблема:** Статические `$connection` и `$channel` — singleton на уровне процесса. Untestable, проблемы с reconnect в queue workers.

**Решение:** Инжектировать connection через DI как singleton в контейнере Laravel.

**Трудоёмкость:** M

---

### 7.5 Отсутствующие API Resources

**Проблема:** Для `AuditLog`, `NotificationSetting`, `CalendarDay` нет API Resources. Используется ручная сериализация.

**Решение:** Создать `AuditLogResource`, `NotificationSettingResource`, `CalendarDayResource`.

**Трудоёмкость:** S (для каждого)

---

### 7.6 Смешение русского и английского в API messages

**Проблема:** Одни контроллеры возвращают русские сообщения (`'Задача не найдена'`), другие — английские (`'Shift opened successfully'`, `'Failed to open shift'`). Согласно CLAUDE.md, UI должен быть на русском.

**Решение:** Перевести все user-facing сообщения. Рассмотреть использование Laravel localization.

**Трудоёмкость:** M (механическая работа, но много файлов)

---

## 8. MEDIUM — Безопасность (прочее)

### 8.1 Observer может получить доступ к фото смены через signed URL

**Файл:** `app/Http/Controllers/Api/V1/ShiftPhotoController.php`

**Проблема:** Endpoint `download` проверяет только подпись URL, не роль пользователя. Если observer получит signed URL (скопирован из адресной строки), он получит доступ к фото.

**Решение:** Документировать как known limitation. Рассмотреть сокращение TTL с 60 до 15 минут.

**Трудоёмкость:** S

---

### 8.2 CORS supports_credentials с Bearer auth

**Файл:** `config/cors.php` (строка 35)

**Проблема:** `'supports_credentials' => true` при использовании Bearer tokens (не cookies). Теоретический CSRF-риск.

**Решение:** Если используется только Bearer token auth — установить `supports_credentials: false`.

**Трудоёмкость:** S (но нужно проверить фронтенд)

---

### 8.3 Hardcoded token name без идентификации устройства

**Файл:** `app/Http/Controllers/Api/V1/SessionController.php` (строка 82)

**Проблема:** Все токены — `'user-token'`. Нельзя различить устройства, нет лимита на количество токенов.

**Решение:**
```php
$token = $user->createToken($request->userAgent() ?? 'unknown-device')->plainTextToken;
```

**Трудоёмкость:** S

---

## 9. LOW — Производительность

### 9.1 CalendarDay::isHoliday в ProcessTaskGeneratorsJob

**Файл:** `app/Models/TaskGenerator.php` (строка 98)

**Проблема:** `shouldGenerateToday()` вызывает `CalendarDay::isHoliday()` для каждого генератора. 50 генераторов = 150 запросов только на проверку праздников.

**Решение:** Предвычислить holiday-статус по dealership_id ДО цикла в `ProcessTaskGeneratorsJob::handle()`.

**Трудоёмкость:** M

---

### 9.2 ShiftService::getUserShifts без пагинации

**Файл:** `app/Services/ShiftService.php` (строка 483)

**Проблема:** `->get()` без лимита. Сотрудник за 2 года работы — 700+ записей в одном ответе.

**Решение:** Добавить пагинацию в `myShifts()`.

**Трудоёмкость:** S

---

### 9.3 Auditable trait создаёт INSERT на каждый update при архивации

**Файл:** `app/Traits/Auditable.php`

**Проблема:** При архивации 500 задач через `chunk(500)` каждый `$task->update()` триггерит Auditable → 500 дополнительных INSERT в audit_logs.

**Решение:** `Task::withoutEvents(fn() => $task->update(...))` для bulk-операций, или одна запись в аудит на всю операцию.

**Трудоёмкость:** S

---

### 9.4 HasDealershipAccess::getUserDealershipIds — lazy load dealerships

**Файл:** `app/Traits/HasDealershipAccess.php` (строка 125)

**Проблема:** `$user->dealerships` может триггерить lazy load если не подгружено через `with()`.

**Решение:** В контроллерах, которые вызывают `hasAccessToUser()`, загружать `User::with('dealerships')`.

**Трудоёмкость:** S

---

## Рекомендуемый порядок реализации

### Фаза 1 — Quick wins (1-2 дня, всё S effort)
1. `.env` из Git + .gitignore (п. 5.1)
2. File validation dedup (п. 6.1)
3. File path generation dedup (п. 6.2)
4. Find-or-404 → findOrFail (п. 6.3)
5. parseDealershipId helper (п. 6.4)
6. BaseApiRequest наследование (п. 6.6)
7. TaskGeneratorPolicy расширение (п. 7.3)
8. Token device name (п. 8.3)
9. supports_credentials (п. 8.2)

### Фаза 2 — Performance critical (3-5 дней)
10. ReportController N+1 + daily loop (п. 1.1 + 1.2) → создать ReportService (п. 3.1)
11. CalendarDay batch в Dashboard (п. 1.3)
12. CalendarDay batch в ProcessTaskGeneratorsJob (п. 9.1)
13. Task::scopeCompleted/scopeOverdue (п. 6.5)

### Фаза 3 — Архитектурные рефакторинги (5-7 дней)
14. Извлечь TaskStatusService (п. 2.1) → State Machine (п. 3.4)
15. Создать TaskGeneratorService (п. 3.2)
16. Создать UserPolicy + UserService (п. 3.3)
17. Разделить HasDealershipAccess (п. 7.1)
18. Перенести events в сервисы (п. 7.2)

### Фаза 4 — Унификация (3-5 дней)
19. Единый формат API ответов (п. 4.1)
20. Оставшиеся API Resources (п. 7.5)
21. Перевод сообщений на русский (п. 7.6)
22. ValidAssignmentsForTaskType rule (п. 6.7)
