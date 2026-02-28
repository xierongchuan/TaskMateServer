# AGENTS.md — TaskMateServer

Laravel REST API for TaskMate. General rules in [../AGENTS.md](../AGENTS.md).

## Stack

Laravel 12 · PHP 8.4 · PostgreSQL 18 · Sanctum 4.2 · RabbitMQ · Valkey · Pest 4.

## Commands

```bash
php artisan test                            # All tests
php artisan test --filter=TaskControllerTest # Specific test
composer test:coverage                       # Coverage (min 50%)
php vendor/bin/pint                          # Code formatting
php vendor/bin/pint --test                   # Style check
```

## Key Conventions

- **Controller → Service → Model** — Business logic in Services, thin Controllers
- **Form Requests** — Validation ONLY in `app/Http/Requests/Api/V1/`, NEVER in controllers
- **Eager loading** — Mandatory: `Task::with(['creator', 'assignments.user'])->get()`
- **toApiArray()** — Use for responses (NOT API Resources, except User/Shift). Guarantees UTC dates with Z suffix
- **TimeHelper** — `TimeHelper::nowUtc()`, `TimeHelper::toIsoZulu($carbon)`, `TimeHelper::dayBoundariesForTimezone()`

## Structure

```
app/
├── Http/Controllers/Api/V1/   # Controllers
├── Http/Requests/Api/V1/      # Form Requests
├── Models/                    # Eloquent models
├── Services/                  # Business logic
├── Jobs/                      # RabbitMQ jobs
├── Enums/                     # Role, TaskStatus, TaskType
├── Traits/                    # Auditable, HasDealershipAccess
├── Helpers/TimeHelper.php     # UTC utilities
└── Validation/FileValidation/ # Magic bytes validation
```

## Forbidden

- MySQL-compatible SQL (use COALESCE not IFNULL)
- Dates not in UTC
- Direct storage access — use `task_proofs` disk + signed URLs
- Logic in controllers — move to Services
- Models without eager loading
- SoftDeletes without scope handling
