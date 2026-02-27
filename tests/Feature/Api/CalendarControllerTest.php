<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\CalendarDay;
use App\Models\AutoDealership;
use App\Enums\Role;
use Carbon\Carbon;

describe('Calendar API', function () {
    beforeEach(function () {
        $this->dealership = AutoDealership::factory()->create();
        $this->owner = User::factory()->create([
            'role' => Role::OWNER->value,
            'dealership_id' => $this->dealership->id
        ]);
        $this->manager = User::factory()->create([
            'role' => Role::MANAGER->value,
            'dealership_id' => $this->dealership->id
        ]);
        $this->employee = User::factory()->create([
            'role' => Role::EMPLOYEE->value,
            'dealership_id' => $this->dealership->id
        ]);
    });

    describe('GET /api/v1/calendar/{year}', function () {
        it('returns calendar for a year', function () {
            // Arrange
            $year = 2025;
            CalendarDay::create([
                'date' => Carbon::create($year, 1, 1),
                'type' => 'holiday',
                'description' => 'Новый год',
                'dealership_id' => null,
            ]);
            CalendarDay::create([
                'date' => Carbon::create($year, 5, 1),
                'type' => 'holiday',
                'description' => 'Праздник труда',
                'dealership_id' => null,
            ]);

            // Act
            $response = $this->actingAs($this->employee, 'sanctum')
                ->getJson("/api/v1/calendar/{$year}");

            // Assert
            $response->assertStatus(200)
                ->assertJsonPath('success', true)
                ->assertJsonPath('data.year', $year)
                ->assertJsonPath('data.holidays_count', 2);
        });

        it('returns only dealership calendar when dealership has own calendar', function () {
            // Arrange
            $year = 2025;
            // Global holiday
            CalendarDay::create([
                'date' => Carbon::create($year, 1, 1),
                'type' => 'holiday',
                'dealership_id' => null,
            ]);
            // Dealership specific holiday - создаёт собственный календарь
            CalendarDay::create([
                'date' => Carbon::create($year, 3, 8),
                'type' => 'holiday',
                'dealership_id' => $this->dealership->id,
            ]);

            // Act
            $response = $this->actingAs($this->manager, 'sanctum')
                ->getJson("/api/v1/calendar/{$year}?dealership_id={$this->dealership->id}");

            // Assert - возвращается ТОЛЬКО запись dealership, без глобальных
            $response->assertStatus(200)
                ->assertJsonPath('success', true)
                ->assertJsonPath('data.dealership_id', $this->dealership->id)
                ->assertJsonPath('data.uses_global', false)
                ->assertJsonPath('data.holidays_count', 1);
        });

        it('returns global calendar when dealership has no own calendar', function () {
            // Arrange
            $year = 2025;
            // Только глобальные записи
            CalendarDay::create([
                'date' => Carbon::create($year, 1, 1),
                'type' => 'holiday',
                'dealership_id' => null,
            ]);
            CalendarDay::create([
                'date' => Carbon::create($year, 5, 1),
                'type' => 'holiday',
                'dealership_id' => null,
            ]);

            // Act
            $response = $this->actingAs($this->manager, 'sanctum')
                ->getJson("/api/v1/calendar/{$year}?dealership_id={$this->dealership->id}");

            // Assert - возвращаются глобальные записи (fallback)
            $response->assertStatus(200)
                ->assertJsonPath('success', true)
                ->assertJsonPath('data.uses_global', true)
                ->assertJsonPath('data.holidays_count', 2);
        });
    });

    describe('GET /api/v1/calendar/{year}/holidays', function () {
        it('returns only holiday dates', function () {
            // Arrange
            $year = 2025;
            CalendarDay::create([
                'date' => Carbon::create($year, 1, 1),
                'type' => 'holiday',
                'dealership_id' => null,
            ]);
            CalendarDay::create([
                'date' => Carbon::create($year, 1, 2),
                'type' => 'workday',
                'dealership_id' => null,
            ]);

            // Act
            $response = $this->actingAs($this->employee, 'sanctum')
                ->getJson("/api/v1/calendar/{$year}/holidays");

            // Assert
            $response->assertStatus(200)
                ->assertJsonPath('success', true)
                ->assertJsonPath('data.count', 1)
                ->assertJsonPath('data.dates.0', '2025-01-01');
        });
    });

    describe('GET /api/v1/calendar/check/{date}', function () {
        it('checks if date is holiday', function () {
            // Arrange
            CalendarDay::create([
                'date' => Carbon::create(2025, 1, 1),
                'type' => 'holiday',
                'dealership_id' => null,
            ]);

            // Act
            $response = $this->actingAs($this->employee, 'sanctum')
                ->getJson('/api/v1/calendar/check/2025-01-01');

            // Assert
            $response->assertStatus(200)
                ->assertJsonPath('success', true)
                ->assertJsonPath('data.is_holiday', true)
                ->assertJsonPath('data.is_workday', false);
        });

        it('checks if date is workday', function () {
            // Act
            $response = $this->actingAs($this->employee, 'sanctum')
                ->getJson('/api/v1/calendar/check/2025-01-15');

            // Assert
            $response->assertStatus(200)
                ->assertJsonPath('success', true)
                ->assertJsonPath('data.is_holiday', false)
                ->assertJsonPath('data.is_workday', true);
        });

        it('returns error for invalid date', function () {
            // Act
            $response = $this->actingAs($this->employee, 'sanctum')
                ->getJson('/api/v1/calendar/check/invalid-date');

            // Assert
            $response->assertStatus(422)
                ->assertJsonPath('success', false);
        });

        it('checks dealership specific holiday', function () {
            // Arrange - Date is workday globally but holiday for dealership
            CalendarDay::create([
                'date' => Carbon::create(2025, 6, 15),
                'type' => 'holiday',
                'dealership_id' => $this->dealership->id,
            ]);

            // Act
            $response = $this->actingAs($this->employee, 'sanctum')
                ->getJson("/api/v1/calendar/check/2025-06-15?dealership_id={$this->dealership->id}");

            // Assert
            $response->assertStatus(200)
                ->assertJsonPath('data.is_holiday', true);
        });
    });

    describe('PUT /api/v1/calendar/{date}', function () {
        it('allows manager to set a holiday', function () {
            // Act
            $response = $this->actingAs($this->manager, 'sanctum')
                ->putJson('/api/v1/calendar/2025-12-31', [
                    'type' => 'holiday',
                    'description' => 'Новогодний корпоратив',
                ]);

            // Assert
            $response->assertStatus(200)
                ->assertJsonPath('success', true)
                ->assertJsonPath('data.type', 'holiday');

            $this->assertDatabaseHas('calendar_days', [
                'date' => '2025-12-31',
                'type' => 'holiday',
                'description' => 'Новогодний корпоратив',
            ]);
        });

        it('allows owner to set a holiday', function () {
            // Act
            $response = $this->actingAs($this->owner, 'sanctum')
                ->putJson('/api/v1/calendar/2025-12-31', [
                    'type' => 'holiday',
                ]);

            // Assert
            $response->assertStatus(200)
                ->assertJsonPath('success', true);
        });

        it('denies employee to set a holiday', function () {
            // Act
            $response = $this->actingAs($this->employee, 'sanctum')
                ->putJson('/api/v1/calendar/2025-12-31', [
                    'type' => 'holiday',
                ]);

            // Assert
            $response->assertStatus(403);
        });

        it('allows setting dealership specific holiday', function () {
            // Act
            $response = $this->actingAs($this->manager, 'sanctum')
                ->putJson('/api/v1/calendar/2025-07-04', [
                    'type' => 'holiday',
                    'dealership_id' => $this->dealership->id,
                    'description' => 'День автосалона',
                ]);

            // Assert
            $response->assertStatus(200)
                ->assertJsonPath('success', true)
                ->assertJsonPath('data.dealership_id', $this->dealership->id);
        });

        it('validates required fields', function () {
            // Act - missing type
            $response = $this->actingAs($this->manager, 'sanctum')
                ->putJson('/api/v1/calendar/2025-12-31', []);

            // Assert
            $response->assertStatus(422)
                ->assertJsonPath('success', false);
        });

        it('validates type enum', function () {
            // Act
            $response = $this->actingAs($this->manager, 'sanctum')
                ->putJson('/api/v1/calendar/2025-12-31', [
                    'type' => 'invalid',
                ]);

            // Assert
            $response->assertStatus(422);
        });

        it('updates existing calendar day', function () {
            // Arrange
            CalendarDay::create([
                'date' => Carbon::create(2025, 12, 25),
                'type' => 'workday',
                'dealership_id' => null,
            ]);

            // Act
            $response = $this->actingAs($this->manager, 'sanctum')
                ->putJson('/api/v1/calendar/2025-12-25', [
                    'type' => 'holiday',
                    'description' => 'Рождество',
                ]);

            // Assert
            $response->assertStatus(200)
                ->assertJsonPath('data.type', 'holiday');

            $this->assertDatabaseHas('calendar_days', [
                'date' => '2025-12-25',
                'type' => 'holiday',
            ]);
        });
    });

    describe('DELETE /api/v1/calendar/{date}', function () {
        it('allows manager to delete calendar day', function () {
            // Arrange
            CalendarDay::create([
                'date' => Carbon::create(2025, 8, 1),
                'type' => 'holiday',
                'dealership_id' => null,
            ]);

            // Act
            $response = $this->actingAs($this->manager, 'sanctum')
                ->deleteJson('/api/v1/calendar/2025-08-01');

            // Assert
            $response->assertStatus(200)
                ->assertJsonPath('success', true)
                ->assertJsonPath('data.deleted', true);

            $this->assertDatabaseMissing('calendar_days', [
                'date' => '2025-08-01',
            ]);
        });

        it('returns deleted=false when no record exists', function () {
            // Act
            $response = $this->actingAs($this->manager, 'sanctum')
                ->deleteJson('/api/v1/calendar/2025-09-01');

            // Assert
            $response->assertStatus(200)
                ->assertJsonPath('data.deleted', false);
        });

        it('deletes only dealership specific record', function () {
            // Arrange
            CalendarDay::create([
                'date' => Carbon::create(2025, 10, 1),
                'type' => 'holiday',
                'dealership_id' => null,
            ]);
            CalendarDay::create([
                'date' => Carbon::create(2025, 10, 1),
                'type' => 'holiday',
                'dealership_id' => $this->dealership->id,
            ]);

            // Act - Delete only dealership specific
            $response = $this->actingAs($this->manager, 'sanctum')
                ->deleteJson("/api/v1/calendar/2025-10-01?dealership_id={$this->dealership->id}");

            // Assert
            $response->assertStatus(200)
                ->assertJsonPath('data.deleted', true);

            // Global record should still exist
            $this->assertDatabaseHas('calendar_days', [
                'date' => '2025-10-01',
                'dealership_id' => null,
            ]);
            $this->assertDatabaseMissing('calendar_days', [
                'date' => '2025-10-01',
                'dealership_id' => $this->dealership->id,
            ]);
        });

        it('denies employee to delete calendar day', function () {
            // Arrange
            CalendarDay::create([
                'date' => Carbon::create(2025, 8, 1),
                'type' => 'holiday',
                'dealership_id' => null,
            ]);

            // Act
            $response = $this->actingAs($this->employee, 'sanctum')
                ->deleteJson('/api/v1/calendar/2025-08-01');

            // Assert
            $response->assertStatus(403);
        });
    });

    describe('POST /api/v1/calendar/bulk', function () {
        it('sets weekdays as holidays for year', function () {
            // Act - Set all Saturdays and Sundays as holidays
            $response = $this->actingAs($this->manager, 'sanctum')
                ->postJson('/api/v1/calendar/bulk', [
                    'operation' => 'set_weekdays',
                    'year' => 2025,
                    'weekdays' => [6, 7], // Saturday, Sunday
                    'type' => 'holiday',
                ]);

            // Assert
            $response->assertStatus(200)
                ->assertJsonPath('success', true)
                ->assertJsonPath('data.operation', 'set_weekdays');

            expect($response->json('data.affected_count'))->toBeGreaterThan(100);
        });

        it('sets specific dates as holidays', function () {
            // Act
            $response = $this->actingAs($this->manager, 'sanctum')
                ->postJson('/api/v1/calendar/bulk', [
                    'operation' => 'set_dates',
                    'year' => 2025,
                    'dates' => ['2025-01-01', '2025-05-01', '2025-05-09'],
                    'type' => 'holiday',
                ]);

            // Assert
            $response->assertStatus(200)
                ->assertJsonPath('success', true)
                ->assertJsonPath('data.affected_count', 3);

            $this->assertDatabaseHas('calendar_days', ['date' => '2025-01-01', 'type' => 'holiday']);
            $this->assertDatabaseHas('calendar_days', ['date' => '2025-05-01', 'type' => 'holiday']);
            $this->assertDatabaseHas('calendar_days', ['date' => '2025-05-09', 'type' => 'holiday']);
        });

        it('clears all records for year', function () {
            // Arrange
            CalendarDay::create([
                'date' => Carbon::create(2024, 1, 1),
                'type' => 'holiday',
                'dealership_id' => null,
            ]);
            CalendarDay::create([
                'date' => Carbon::create(2024, 5, 1),
                'type' => 'holiday',
                'dealership_id' => null,
            ]);

            // Act
            $response = $this->actingAs($this->manager, 'sanctum')
                ->postJson('/api/v1/calendar/bulk', [
                    'operation' => 'clear_year',
                    'year' => 2024,
                ]);

            // Assert
            $response->assertStatus(200)
                ->assertJsonPath('success', true)
                ->assertJsonPath('data.operation', 'clear_year')
                ->assertJsonPath('data.affected_count', 2);

            $this->assertDatabaseMissing('calendar_days', ['date' => '2024-01-01']);
        });

        it('validates operation type', function () {
            // Act
            $response = $this->actingAs($this->manager, 'sanctum')
                ->postJson('/api/v1/calendar/bulk', [
                    'operation' => 'invalid_operation',
                    'year' => 2025,
                ]);

            // Assert
            $response->assertStatus(422);
        });

        it('validates year range', function () {
            // Act
            $response = $this->actingAs($this->manager, 'sanctum')
                ->postJson('/api/v1/calendar/bulk', [
                    'operation' => 'clear_year',
                    'year' => 1900, // Too old
                ]);

            // Assert
            $response->assertStatus(422);
        });

        it('denies employee bulk operations', function () {
            // Act
            $response = $this->actingAs($this->employee, 'sanctum')
                ->postJson('/api/v1/calendar/bulk', [
                    'operation' => 'clear_year',
                    'year' => 2025,
                ]);

            // Assert
            $response->assertStatus(403);
        });

        it('supports dealership specific bulk operations', function () {
            // Act
            $response = $this->actingAs($this->manager, 'sanctum')
                ->postJson('/api/v1/calendar/bulk', [
                    'operation' => 'set_dates',
                    'year' => 2025,
                    'dates' => ['2025-06-01'],
                    'type' => 'holiday',
                    'dealership_id' => $this->dealership->id,
                ]);

            // Assert
            $response->assertStatus(200)
                ->assertJsonPath('data.dealership_id', $this->dealership->id);

            $this->assertDatabaseHas('calendar_days', [
                'date' => '2025-06-01',
                'dealership_id' => $this->dealership->id,
            ]);
        });
    });

    describe('DELETE /api/v1/calendar/{year}/reset', function () {
        it('resets dealership calendar to global', function () {
            // Arrange
            CalendarDay::create([
                'date' => Carbon::create(2025, 1, 1),
                'type' => 'holiday',
                'dealership_id' => $this->dealership->id,
            ]);
            CalendarDay::create([
                'date' => Carbon::create(2025, 5, 1),
                'type' => 'holiday',
                'dealership_id' => $this->dealership->id,
            ]);

            // Act
            $response = $this->actingAs($this->manager, 'sanctum')
                ->deleteJson("/api/v1/calendar/2025/reset?dealership_id={$this->dealership->id}");

            // Assert
            $response->assertStatus(200)
                ->assertJsonPath('success', true)
                ->assertJsonPath('data.deleted_count', 2);

            $this->assertDatabaseMissing('calendar_days', [
                'dealership_id' => $this->dealership->id,
            ]);
        });

        it('returns success when already using global', function () {
            // Act
            $response = $this->actingAs($this->manager, 'sanctum')
                ->deleteJson("/api/v1/calendar/2025/reset?dealership_id={$this->dealership->id}");

            // Assert
            $response->assertStatus(200)
                ->assertJsonPath('success', true)
                ->assertJsonPath('data.deleted_count', 0);
        });

        it('requires dealership_id', function () {
            // Act
            $response = $this->actingAs($this->manager, 'sanctum')
                ->deleteJson('/api/v1/calendar/2025/reset');

            // Assert
            $response->assertStatus(422);
        });

        it('denies employee to reset calendar', function () {
            // Act
            $response = $this->actingAs($this->employee, 'sanctum')
                ->deleteJson("/api/v1/calendar/2025/reset?dealership_id={$this->dealership->id}");

            // Assert
            $response->assertStatus(403);
        });
    });

    describe('uses_global flag', function () {
        it('returns uses_global=true when no dealership records in index', function () {
            // Arrange - только глобальные записи
            CalendarDay::create([
                'date' => Carbon::create(2025, 1, 1),
                'type' => 'holiday',
                'dealership_id' => null,
            ]);

            // Act
            $response = $this->actingAs($this->employee, 'sanctum')
                ->getJson("/api/v1/calendar/2025?dealership_id={$this->dealership->id}");

            // Assert
            $response->assertJsonPath('data.uses_global', true);
        });

        it('returns uses_global=false when dealership has own records in index', function () {
            // Arrange
            CalendarDay::create([
                'date' => Carbon::create(2025, 1, 1),
                'type' => 'holiday',
                'dealership_id' => $this->dealership->id,
            ]);

            // Act
            $response = $this->actingAs($this->employee, 'sanctum')
                ->getJson("/api/v1/calendar/2025?dealership_id={$this->dealership->id}");

            // Assert
            $response->assertJsonPath('data.uses_global', false);
        });

        it('returns uses_global in holidays endpoint', function () {
            // Act
            $response = $this->actingAs($this->employee, 'sanctum')
                ->getJson("/api/v1/calendar/2025/holidays?dealership_id={$this->dealership->id}");

            // Assert
            $response->assertJsonPath('data.uses_global', true);
        });

        it('returns uses_global in bulk response', function () {
            // Act
            $response = $this->actingAs($this->manager, 'sanctum')
                ->postJson('/api/v1/calendar/bulk', [
                    'operation' => 'set_dates',
                    'year' => 2025,
                    'dates' => ['2025-06-01'],
                    'type' => 'holiday',
                    'dealership_id' => $this->dealership->id,
                ]);

            // Assert - after adding record, uses_global should be false
            $response->assertJsonPath('data.uses_global', false);
        });
    });

    describe('auto-copy global on first change', function () {
        it('copies global records when first modifying dealership calendar via update', function () {
            // Arrange - глобальные записи
            CalendarDay::create([
                'date' => Carbon::create(2025, 1, 1),
                'type' => 'holiday',
                'dealership_id' => null,
            ]);
            CalendarDay::create([
                'date' => Carbon::create(2025, 5, 1),
                'type' => 'holiday',
                'dealership_id' => null,
            ]);

            // Act - первое изменение для dealership
            $response = $this->actingAs($this->manager, 'sanctum')
                ->putJson('/api/v1/calendar/2025-03-08', [
                    'type' => 'holiday',
                    'dealership_id' => $this->dealership->id,
                ]);

            // Assert
            $response->assertStatus(200)
                ->assertJsonPath('meta.copied_from_global', true);

            // Должны быть скопированы глобальные + новая запись
            expect(CalendarDay::where('dealership_id', $this->dealership->id)->count())->toBe(3);
        });

        it('does not copy when dealership already has own calendar', function () {
            // Arrange - у dealership уже есть своя запись
            CalendarDay::create([
                'date' => Carbon::create(2025, 1, 1),
                'type' => 'holiday',
                'dealership_id' => $this->dealership->id,
            ]);
            CalendarDay::create([
                'date' => Carbon::create(2025, 5, 1),
                'type' => 'holiday',
                'dealership_id' => null,
            ]);

            // Act
            $response = $this->actingAs($this->manager, 'sanctum')
                ->putJson('/api/v1/calendar/2025-03-08', [
                    'type' => 'holiday',
                    'dealership_id' => $this->dealership->id,
                ]);

            // Assert
            $response->assertStatus(200)
                ->assertJsonPath('meta.copied_from_global', false);

            // Только старая + новая запись, без копирования глобальных
            expect(CalendarDay::where('dealership_id', $this->dealership->id)->count())->toBe(2);
        });

        it('copies global records when first bulk update for dealership', function () {
            // Arrange - глобальные записи
            CalendarDay::create([
                'date' => Carbon::create(2025, 1, 1),
                'type' => 'holiday',
                'dealership_id' => null,
            ]);

            // Act
            $response = $this->actingAs($this->manager, 'sanctum')
                ->postJson('/api/v1/calendar/bulk', [
                    'operation' => 'set_dates',
                    'year' => 2025,
                    'dates' => ['2025-06-01'],
                    'type' => 'holiday',
                    'dealership_id' => $this->dealership->id,
                ]);

            // Assert
            $response->assertStatus(200)
                ->assertJsonPath('meta.copied_from_global', true);

            // Глобальная скопирована + новая запись
            expect(CalendarDay::where('dealership_id', $this->dealership->id)->count())->toBe(2);
        });

        it('does not copy on clear_year operation', function () {
            // Arrange
            CalendarDay::create([
                'date' => Carbon::create(2025, 1, 1),
                'type' => 'holiday',
                'dealership_id' => null,
            ]);

            // Act
            $response = $this->actingAs($this->manager, 'sanctum')
                ->postJson('/api/v1/calendar/bulk', [
                    'operation' => 'clear_year',
                    'year' => 2025,
                    'dealership_id' => $this->dealership->id,
                ]);

            // Assert - clear_year не должен копировать
            $response->assertStatus(200)
                ->assertJsonPath('meta.copied_from_global', false);
        });
    });
});
