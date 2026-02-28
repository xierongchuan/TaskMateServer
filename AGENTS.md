# AGENTS.md

This file provides guidance to agents when working with TaskMateServer backend.

## Stack

Laravel 12 + PHP 8.4 + PostgreSQL 18 + Sanctum 4.2 + RabbitMQ + Valkey + Pest 4.

## Commands

```bash
# Tests
podman compose exec api php artisan test
podman compose exec api php artisan test --filter=TaskControllerTest

# Coverage
podman compose exec api composer test:coverage

# Linting
podman compose exec api vendor/bin/pint

# Migrations
podman compose exec api php artisan migrate --force
podman compose exec api php artisan db:seed-demo
```

## Non-Obvious Rules

### Controller → Service → Model
- Business logic in Services, thin Controllers
- Models use `toApiArray()` for responses — NOT API Resources (except User, Shift)

```php
// CORRECT
public function store(StoreTaskRequest $request): JsonResponse
{
    $task = $this->taskService->createTask($request->validated(), $request->user());
    return response()->json(['data' => $task->toApiArray()], 201);
}
```

### Eager Loading — Mandatory
```php
// CORRECT: prevents N+1
$tasks = Task::with(['creator', 'assignments.user', 'responses.proofs'])->get();

// WRONG: lazy loading causes N+1
$tasks = Task::all();
```

### Validation
- ALWAYS use Form Requests in `app/Http/Requests/Api/V1/`
- NEVER validate in controller via `$request->validate()`

### Dates
- All dates UTC. Use `TimeHelper`:
```php
use App\Helpers\TimeHelper;
$now = TimeHelper::nowUtc();
$iso = TimeHelper::toIsoZulu($carbon); // "2024-01-15T10:30:00Z"
```

### Security

- **SQL Injection**: Use parameter bindings, never concatenate SQL
- **XSS**: For API JSON responses, `response()->json()` automatically escapes strings. Use `e()` only if manual escaping is needed.
- **Command Injection**: NEVER use `exec()`, `shell_exec()`, `system()` with user input
- **File Upload**: Validate MIME type, extension, use random filenames
- **Memory**: Use chunking for large datasets, limit query results

## Structure

```
app/
├── Http/Controllers/Api/V1/   # 18 controllers
├── Http/Requests/Api/V1/     # Form Requests
├── Models/                    # 19 Eloquent models
├── Services/                  # 11 services
├── Jobs/                     # RabbitMQ jobs
├── Console/Commands/         # Artisan commands
├── Enums/                    # Role, TaskStatus, TaskType
├── Traits/                   # Auditable, HasDealershipAccess
└── Helpers/TimeHelper.php    # UTC utilities
```

## Jobs (RabbitMQ)

| Job | Queue |
|-----|-------|
| ProcessTaskGeneratorsJob | task_generators |
| StoreTaskProofsJob | proof_upload |
| StoreTaskSharedProofsJob | shared_proof_upload |
| DeleteProofFileJob | file_cleanup |

## Forbidden

- MySQL-compatible SQL (use COALESCE not IFNULL)
- Dates not in UTC
- Storage access directly — use `task_proofs` disk + signed URLs
- Logic in controllers — move to Services
- Models without eager loading
- SoftDeletes without scope handling
