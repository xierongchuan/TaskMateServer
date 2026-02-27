<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\AutoDealership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\getJson;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Создаем owner пользователя для тестов
    $this->owner = User::factory()->create(['role' => 'owner']);
    Sanctum::actingAs($this->owner);
});

describe('GET /api/v1/audit-logs', function () {

    it('returns paginated audit logs', function () {
        AuditLog::factory()->count(30)->create();

        $response = getJson('/api/v1/audit-logs?per_page=10');

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(10);
        expect($response->json('current_page'))->toBe(1);
        expect($response->json('per_page'))->toBe(10);
        expect($response->json('total'))->toBeGreaterThanOrEqual(30); // Может быть больше из-за beforeEach
    });

    it('filters audit logs by dealership_id', function () {
        $dealership = AutoDealership::factory()->create();

        // Создаем логи для разных автосалонов
        AuditLog::factory()->count(3)->create(['dealership_id' => $dealership->id]);
        AuditLog::factory()->count(2)->withoutDealership()->create();

        $response = getJson("/api/v1/audit-logs?dealership_id={$dealership->id}");

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(3);

        // Проверяем что все записи от нужного автосалона
        foreach ($response->json('data') as $log) {
            expect($log['dealership']['id'])->toBe($dealership->id);
        }
    });

    it('filters audit logs by actor_id', function () {
        $actor = User::factory()->create();

        AuditLog::factory()->count(5)->create(['actor_id' => $actor->id]);
        AuditLog::factory()->count(3)->create(['actor_id' => $this->owner->id]);

        $response = getJson("/api/v1/audit-logs?actor_id={$actor->id}");

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(5);

        // Проверяем что все записи от нужного пользователя
        foreach ($response->json('data') as $log) {
            expect($log['actor']['id'])->toBe($actor->id);
        }
    });

    it('filters audit logs by table_name', function () {
        AuditLog::factory()->count(4)->forTask()->create();
        AuditLog::factory()->count(3)->forUser()->create();
        AuditLog::factory()->count(2)->forShift()->create();

        $response = getJson('/api/v1/audit-logs?table_name=tasks');

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(4);

        foreach ($response->json('data') as $log) {
            expect($log['table_name'])->toBe('tasks');
        }
    });

    it('filters audit logs by action', function () {
        AuditLog::factory()->count(5)->created()->create();
        AuditLog::factory()->count(3)->updated()->create();
        AuditLog::factory()->count(2)->deleted()->create();

        $response = getJson('/api/v1/audit-logs?action=updated');

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(3);

        foreach ($response->json('data') as $log) {
            expect($log['action'])->toBe('updated');
        }
    });

    it('filters audit logs by date range', function () {
        AuditLog::factory()->create(['created_at' => '2025-01-15 10:00:00']);
        AuditLog::factory()->create(['created_at' => '2025-01-20 10:00:00']);
        AuditLog::factory()->create(['created_at' => '2025-01-25 10:00:00']);

        $response = getJson('/api/v1/audit-logs?from_date=2025-01-18&to_date=2025-01-22');

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
    });

    it('filters audit logs by record_id', function () {
        AuditLog::factory()->create(['record_id' => 123]);
        AuditLog::factory()->create(['record_id' => 456]);
        AuditLog::factory()->count(3)->create(['record_id' => 789]);

        $response = getJson('/api/v1/audit-logs?record_id=789');

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(3);

        foreach ($response->json('data') as $log) {
            expect($log['record_id'])->toBe(789);
        }
    });

    it('validates table_name parameter', function () {
        $response = getJson('/api/v1/audit-logs?table_name=invalid_table');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['table_name']);
    });

    it('validates action parameter', function () {
        $response = getJson('/api/v1/audit-logs?action=invalid_action');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['action']);
    });

    it('validates per_page limit', function () {
        $response = getJson('/api/v1/audit-logs?per_page=999');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['per_page']);
    });

    it('validates date range (from_date > to_date)', function () {
        $response = getJson('/api/v1/audit-logs?from_date=2025-12-31&to_date=2025-01-01');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['to_date']);
    });

    it('validates invalid date format', function () {
        $response = getJson('/api/v1/audit-logs?from_date=31-12-2025');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['from_date']);
    });

    it('validates non-existent actor_id', function () {
        $response = getJson('/api/v1/audit-logs?actor_id=99999');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['actor_id']);
    });

    it('validates non-existent dealership_id', function () {
        $response = getJson('/api/v1/audit-logs?dealership_id=99999');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['dealership_id']);
    });

    it('paginates results correctly', function () {
        AuditLog::factory()->count(50)->create();

        $response = getJson('/api/v1/audit-logs?per_page=10&page=2');

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(10);
        expect($response->json('current_page'))->toBe(2);
        expect($response->json('last_page'))->toBeGreaterThanOrEqual(5); // Может быть больше
    });

    it('enriches logs with actor and dealership data', function () {
        $dealership = AutoDealership::factory()->create(['name' => 'Test Салон']);
        $actor = User::factory()->create([
            'full_name' => 'Test User',
            'login' => 'testuser',
        ]);

        $testLog = AuditLog::factory()->create([
            'record_id' => 99999, // Уникальный ID
            'dealership_id' => $dealership->id,
            'actor_id' => $actor->id,
        ]);

        $response = getJson('/api/v1/audit-logs?record_id=99999');

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        $log = $response->json('data.0');

        // Проверяем обогащение данными dealership
        expect($log['dealership'])->not->toBeNull();
        expect($log['dealership']['id'])->toBe($dealership->id);
        expect($log['dealership']['name'])->toBe('Test Салон');

        // Проверяем обогащение данными actor
        expect($log['actor'])->not->toBeNull();
        expect($log['actor']['id'])->toBe($actor->id);
        expect($log['actor']['full_name'])->toBe('Test User');
        expect($log['actor']['login'])->toBe('testuser');
    });

    it('includes table_label and action_label', function () {
        AuditLog::factory()->forTask()->created()->create(['record_id' => 88888]);

        $response = getJson('/api/v1/audit-logs?record_id=88888');

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        $log = $response->json('data.0');

        expect($log['table_label'])->toBe('Задачи');
        expect($log['action_label'])->toBe('Создание');
    });

    it('handles logs without dealership', function () {
        AuditLog::factory()->withoutDealership()->create();

        $response = getJson('/api/v1/audit-logs');

        $response->assertOk();
        $log = $response->json('data.0');

        expect($log['dealership'])->toBeNull();
    });

    it('handles logs without actor (system actions)', function () {
        AuditLog::factory()->withoutActor()->create();

        $response = getJson('/api/v1/audit-logs');

        $response->assertOk();
        $log = $response->json('data.0');

        expect($log['actor'])->toBeNull();
    });

    it('requires owner role', function () {
        $manager = User::factory()->create(['role' => 'manager']);
        Sanctum::actingAs($manager);

        $response = getJson('/api/v1/audit-logs');

        $response->assertStatus(403);
    });

    it('combines multiple filters', function () {
        $dealership = AutoDealership::factory()->create();
        $actor = User::factory()->create();

        // Создаем логи с разными комбинациями
        AuditLog::factory()->create([
            'dealership_id' => $dealership->id,
            'actor_id' => $actor->id,
            'table_name' => 'tasks',
            'action' => 'created',
            'created_at' => '2025-01-20 10:00:00',
        ]);

        AuditLog::factory()->create([
            'dealership_id' => $dealership->id,
            'actor_id' => $actor->id,
            'table_name' => 'users',
            'action' => 'updated',
            'created_at' => '2025-01-20 15:00:00',
        ]);

        AuditLog::factory()->count(5)->create(); // Другие логи

        $response = getJson("/api/v1/audit-logs?dealership_id={$dealership->id}&actor_id={$actor->id}&table_name=tasks&from_date=2025-01-19&to_date=2025-01-21");

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);

        $log = $response->json('data.0');
        expect($log['dealership']['id'])->toBe($dealership->id);
        expect($log['actor']['id'])->toBe($actor->id);
        expect($log['table_name'])->toBe('tasks');
    });
});

describe('GET /api/v1/audit-logs/actors', function () {

    it('returns actors list with all required fields', function () {
        $user1 = User::factory()->create([
            'full_name' => 'Actor 1',
            'login' => 'actor1',
            'dealership_id' => null,
        ]);

        $response = getJson('/api/v1/audit-logs/actors');

        $response->assertOk();

        // Ищем нашего пользователя в списке
        $actors = collect($response->json('data'));
        $actor = $actors->firstWhere('login', 'actor1');

        expect($actor)->not->toBeNull();
        expect($actor)->toHaveKeys(['id', 'full_name', 'login', 'role']);
        expect($actor['id'])->toBe($user1->id);
        expect($actor['full_name'])->toBe('Actor 1');
        expect($actor['login'])->toBe('actor1');
    });

    it('returns only distinct actors', function () {
        $user = User::factory()->create([
            'login' => 'unique_test_actor',
            'dealership_id' => null,
        ]);

        $response = getJson('/api/v1/audit-logs/actors');

        $response->assertOk();

        // Проверяем что user в списке и уникален
        $actors = collect($response->json('data'));
        $testActor = $actors->firstWhere('login', 'unique_test_actor');

        expect($testActor)->not->toBeNull();
        expect($testActor['id'])->toBe($user->id);

        // Проверяем что нет дубликатов
        $duplicates = $actors->where('id', $user->id);
        expect($duplicates)->toHaveCount(1);
    });

    it('sorts actors by full_name within same role', function () {
        // Создаем пользователей с одинаковой ролью и без dealership
        $userC = User::factory()->create([
            'full_name' => 'Charlie User',
            'login' => 'charlie',
            'role' => 'employee',
            'dealership_id' => null,
        ]);
        $userA = User::factory()->create([
            'full_name' => 'Alice User',
            'login' => 'alice',
            'role' => 'employee',
            'dealership_id' => null,
        ]);
        $userB = User::factory()->create([
            'full_name' => 'Bob User',
            'login' => 'bob',
            'role' => 'employee',
            'dealership_id' => null,
        ]);

        $response = getJson('/api/v1/audit-logs/actors');

        $response->assertOk();

        // Найдем наших пользователей в ответе
        $actors = collect($response->json('data'));
        $ourActors = $actors->whereIn('login', ['alice', 'bob', 'charlie'])->values();

        expect($ourActors[0]['full_name'])->toBe('Alice User');
        expect($ourActors[1]['full_name'])->toBe('Bob User');
        expect($ourActors[2]['full_name'])->toBe('Charlie User');
    });

    it('returns all users regardless of audit logs', function () {
        $user = User::factory()->create([
            'login' => 'actor_with_logs',
            'dealership_id' => null,
        ]);

        $response = getJson('/api/v1/audit-logs/actors');

        $response->assertOk();

        // Проверяем что в списке есть наш user
        $actors = collect($response->json('data'));
        $testActor = $actors->firstWhere('login', 'actor_with_logs');

        expect($testActor)->not->toBeNull();

        // Проверяем что все actors имеют валидный ID (не null)
        foreach ($actors as $actor) {
            expect($actor['id'])->not->toBeNull();
        }
    });

    it('requires owner role', function () {
        $employee = User::factory()->create(['role' => 'employee']);
        Sanctum::actingAs($employee);

        $response = getJson('/api/v1/audit-logs/actors');

        $response->assertStatus(403);
    });
});

describe('GET /api/v1/audit-logs/actors with dealership filter', function () {

    it('returns actors from specific dealership', function () {
        $dealership = AutoDealership::factory()->create();

        // Пользователь с primary dealership_id
        $user1 = User::factory()->create([
            'dealership_id' => $dealership->id,
            'full_name' => 'User From Dealership 1',
            'login' => 'user_from_deal1',
        ]);

        // Пользователь прикрепленный через pivot
        $user2 = User::factory()->create([
            'dealership_id' => null,
            'full_name' => 'User From Dealership 2',
            'login' => 'user_from_deal2',
        ]);
        $user2->dealerships()->attach($dealership->id);

        // Пользователь из другого автосалона
        $otherDealership = AutoDealership::factory()->create();
        $user3 = User::factory()->create([
            'dealership_id' => $otherDealership->id,
            'full_name' => 'User From Other Dealership',
            'login' => 'user_from_other',
        ]);

        $response = getJson("/api/v1/audit-logs/actors?dealership_id={$dealership->id}");

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(2);

        $logins = collect($response->json('data'))->pluck('login')->toArray();
        expect($logins)->toContain('user_from_deal1');
        expect($logins)->toContain('user_from_deal2');
        expect($logins)->not->toContain('user_from_other');
    });

    it('returns only orphan actors when dealership_id not provided', function () {
        $dealership = AutoDealership::factory()->create();

        // Пользователь без автосалона (orphan)
        $orphan = User::factory()->create([
            'dealership_id' => null,
            'full_name' => 'Orphan User',
            'login' => 'orphan_user',
        ]);

        // Пользователь с primary dealership
        $withDealership = User::factory()->create([
            'dealership_id' => $dealership->id,
            'full_name' => 'User With Dealership',
            'login' => 'user_with_deal',
        ]);

        // Пользователь прикрепленный через pivot
        $attached = User::factory()->create([
            'dealership_id' => null,
            'full_name' => 'Attached User',
            'login' => 'attached_user',
        ]);
        $attached->dealerships()->attach($dealership->id);

        $response = getJson('/api/v1/audit-logs/actors');

        $response->assertOk();

        // Проверяем что orphan_user присутствует
        $logins = collect($response->json('data'))->pluck('login')->toArray();
        expect($logins)->toContain('orphan_user');

        // Проверяем что пользователи с автосалонами НЕ присутствуют
        expect($logins)->not->toContain('user_with_deal');
        expect($logins)->not->toContain('attached_user');
    });

    it('validates dealership_id exists', function () {
        $response = getJson('/api/v1/audit-logs/actors?dealership_id=99999');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['dealership_id']);
    });

    it('validates dealership_id is integer', function () {
        $response = getJson('/api/v1/audit-logs/actors?dealership_id=abc');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['dealership_id']);
    });

    it('includes role field in response', function () {
        $dealership = AutoDealership::factory()->create();

        $user = User::factory()->create([
            'dealership_id' => $dealership->id,
            'full_name' => 'Test User',
            'login' => 'test_user',
            'role' => 'manager',
        ]);

        $response = getJson("/api/v1/audit-logs/actors?dealership_id={$dealership->id}");

        $response->assertOk();
        expect($response->json('data.0'))->toHaveKeys(['id', 'full_name', 'login', 'role']);
        expect($response->json('data.0.role'))->toBe('manager');
    });

    it('sorts actors by role then by name', function () {
        $dealership = AutoDealership::factory()->create();

        // Создаем пользователей в неупорядоченном виде
        $employee1 = User::factory()->create([
            'dealership_id' => $dealership->id,
            'full_name' => 'Zara Employee',
            'login' => 'zara_emp',
            'role' => 'employee',
        ]);

        $owner = User::factory()->create([
            'dealership_id' => $dealership->id,
            'full_name' => 'Bob Owner',
            'login' => 'bob_owner',
            'role' => 'owner',
        ]);

        $manager1 = User::factory()->create([
            'dealership_id' => $dealership->id,
            'full_name' => 'Charlie Manager',
            'login' => 'charlie_mgr',
            'role' => 'manager',
        ]);

        $employee2 = User::factory()->create([
            'dealership_id' => $dealership->id,
            'full_name' => 'Alice Employee',
            'login' => 'alice_emp',
            'role' => 'employee',
        ]);

        $observer = User::factory()->create([
            'dealership_id' => $dealership->id,
            'full_name' => 'Dave Observer',
            'login' => 'dave_obs',
            'role' => 'observer',
        ]);

        $response = getJson("/api/v1/audit-logs/actors?dealership_id={$dealership->id}");

        $response->assertOk();
        $data = $response->json('data');

        // Проверяем порядок: owner, manager, employees (по имени), observer
        expect($data[0]['role'])->toBe('owner');
        expect($data[0]['login'])->toBe('bob_owner');

        expect($data[1]['role'])->toBe('manager');
        expect($data[1]['login'])->toBe('charlie_mgr');

        expect($data[2]['role'])->toBe('employee');
        expect($data[2]['login'])->toBe('alice_emp'); // Alice перед Zara

        expect($data[3]['role'])->toBe('employee');
        expect($data[3]['login'])->toBe('zara_emp');

        expect($data[4]['role'])->toBe('observer');
        expect($data[4]['login'])->toBe('dave_obs');
    });
});

describe('GET /api/v1/audit-logs/{tableName}/{recordId}', function () {

    it('returns history for specific record', function () {
        AuditLog::factory()->count(3)->create([
            'table_name' => 'tasks',
            'record_id' => 42,
        ]);

        AuditLog::factory()->count(5)->create(); // Other logs

        $response = getJson('/api/v1/audit-logs/tasks/42');

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(3);

        foreach ($response->json('data') as $log) {
            expect($log['table_name'])->toBe('tasks');
            expect($log['record_id'])->toBe(42);
        }
    });

    it('validates table_name against allowed tables', function () {
        $response = getJson('/api/v1/audit-logs/invalid_table/123');

        $response->assertStatus(400);
        expect($response->json('message'))->toBe('Таблица не поддерживается');
    });

    it('returns empty array if no logs found', function () {
        $response = getJson('/api/v1/audit-logs/tasks/99999');

        $response->assertOk();
        expect($response->json('data'))->toBeArray();
        expect($response->json('data'))->toHaveCount(0);
    });

    it('enriches record history with actor and dealership data', function () {
        $dealership = AutoDealership::factory()->create();
        $actor = User::factory()->create();

        AuditLog::factory()->create([
            'table_name' => 'tasks',
            'record_id' => 100,
            'dealership_id' => $dealership->id,
            'actor_id' => $actor->id,
        ]);

        $response = getJson('/api/v1/audit-logs/tasks/100');

        $response->assertOk();
        $log = $response->json('data.0');

        expect($log['dealership'])->not->toBeNull();
        expect($log['actor'])->not->toBeNull();
        expect($log['actor']['login'])->not->toBeNull();
    });

    it('allows managers to access record history', function () {
        $manager = User::factory()->create(['role' => 'manager']);
        Sanctum::actingAs($manager);

        AuditLog::factory()->create([
            'table_name' => 'tasks',
            'record_id' => 1,
        ]);

        $response = getJson('/api/v1/audit-logs/tasks/1');

        $response->assertOk();
    });

    it('allows owners to access record history', function () {
        AuditLog::factory()->create([
            'table_name' => 'tasks',
            'record_id' => 1,
        ]);

        $response = getJson('/api/v1/audit-logs/tasks/1');

        $response->assertOk();
    });
});
