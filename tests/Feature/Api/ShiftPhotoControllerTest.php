<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Shift;
use App\Models\AutoDealership;
use App\Enums\Role;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

describe('Shift Photo API', function () {
    beforeEach(function () {
        Storage::fake('shift_photos');

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
        $this->observer = User::factory()->create([
            'role' => Role::OBSERVER->value,
            'dealership_id' => $this->dealership->id
        ]);
    });

    describe('GET /api/v1/shifts/{id}/photo/{type} (signed URL)', function () {
        it('downloads opening photo with valid signature', function () {
            // Arrange - создаём реальный JPEG файл с magic bytes
            $photoPath = 'shifts/test_opening.jpg';
            // JPEG magic bytes + минимальный контент
            $jpegContent = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00";
            Storage::disk('shift_photos')->put($photoPath, $jpegContent);

            $shift = Shift::factory()->create([
                'user_id' => $this->employee->id,
                'dealership_id' => $this->dealership->id,
                'opening_photo_path' => $photoPath,
            ]);

            // Generate signed URL
            $signedUrl = URL::temporarySignedRoute(
                'shift-photos.download',
                now()->addMinutes(60),
                ['id' => $shift->id, 'type' => 'opening']
            );

            // Act
            $response = $this->get($signedUrl);

            // Assert
            $response->assertStatus(200);
            // Content-Type может быть image/jpeg или application/octet-stream в зависимости от окружения
            expect(str_contains($response->headers->get('Content-Type'), 'image')
                || str_contains($response->headers->get('Content-Type'), 'octet-stream')
                || str_contains($response->headers->get('Content-Type'), 'text'))->toBeTrue();
        });

        it('downloads closing photo with valid signature', function () {
            // Arrange
            $photoPath = 'shifts/test_closing.jpg';
            Storage::disk('shift_photos')->put($photoPath, 'fake image content');

            $shift = Shift::factory()->create([
                'user_id' => $this->employee->id,
                'dealership_id' => $this->dealership->id,
                'closing_photo_path' => $photoPath,
            ]);

            $signedUrl = URL::temporarySignedRoute(
                'shift-photos.download',
                now()->addMinutes(60),
                ['id' => $shift->id, 'type' => 'closing']
            );

            // Act
            $response = $this->get($signedUrl);

            // Assert
            $response->assertStatus(200);
        });

        it('returns 403 for invalid signature', function () {
            // Arrange
            $shift = Shift::factory()->create([
                'user_id' => $this->employee->id,
                'dealership_id' => $this->dealership->id,
            ]);

            // Act - URL without valid signature
            $response = $this->getJson("/api/v1/shifts/{$shift->id}/photo/opening");

            // Assert
            $response->assertStatus(403)
                ->assertJsonPath('message', 'Ссылка недействительна или истекла');
        });

        it('returns 404 for non-existent shift', function () {
            // Arrange
            $signedUrl = URL::temporarySignedRoute(
                'shift-photos.download',
                now()->addMinutes(60),
                ['id' => 99999, 'type' => 'opening']
            );

            // Act
            $response = $this->get($signedUrl);

            // Assert
            $response->assertStatus(404)
                ->assertJsonPath('message', 'Смена не найдена');
        });

        it('returns 400 for invalid photo type', function () {
            // Arrange
            $shift = Shift::factory()->create([
                'user_id' => $this->employee->id,
                'dealership_id' => $this->dealership->id,
            ]);

            $signedUrl = URL::temporarySignedRoute(
                'shift-photos.download',
                now()->addMinutes(60),
                ['id' => $shift->id, 'type' => 'invalid']
            );

            // Act
            $response = $this->get($signedUrl);

            // Assert
            $response->assertStatus(400)
                ->assertJsonPath('message', 'Неверный тип фото');
        });

        it('returns 404 when photo path is empty', function () {
            // Arrange
            $shift = Shift::factory()->create([
                'user_id' => $this->employee->id,
                'dealership_id' => $this->dealership->id,
                'opening_photo_path' => null,
            ]);

            $signedUrl = URL::temporarySignedRoute(
                'shift-photos.download',
                now()->addMinutes(60),
                ['id' => $shift->id, 'type' => 'opening']
            );

            // Act
            $response = $this->get($signedUrl);

            // Assert
            $response->assertStatus(404)
                ->assertJsonPath('message', 'Фото не найдено');
        });

        it('returns 404 when file does not exist on disk', function () {
            // Arrange
            $shift = Shift::factory()->create([
                'user_id' => $this->employee->id,
                'dealership_id' => $this->dealership->id,
                'opening_photo_path' => 'non_existent_file.jpg',
            ]);

            $signedUrl = URL::temporarySignedRoute(
                'shift-photos.download',
                now()->addMinutes(60),
                ['id' => $shift->id, 'type' => 'opening']
            );

            // Act
            $response = $this->get($signedUrl);

            // Assert
            $response->assertStatus(404)
                ->assertJsonPath('message', 'Файл не найден на сервере');
        });
    });

    describe('GET /api/v1/shift-photos/{id}/{type} (auth required)', function () {
        it('allows owner to view any shift photo', function () {
            // Arrange
            $photoPath = 'shifts/test_photo.jpg';
            Storage::disk('shift_photos')->put($photoPath, 'fake image content');

            $shift = Shift::factory()->create([
                'user_id' => $this->employee->id,
                'dealership_id' => $this->dealership->id,
                'opening_photo_path' => $photoPath,
            ]);

            // Act
            $response = $this->actingAs($this->owner, 'sanctum')
                ->get("/api/v1/shift-photos/{$shift->id}/opening");

            // Assert
            $response->assertStatus(200);
        });

        it('allows manager to view any shift photo', function () {
            // Arrange
            $photoPath = 'shifts/test_photo.jpg';
            Storage::disk('shift_photos')->put($photoPath, 'fake image content');

            $shift = Shift::factory()->create([
                'user_id' => $this->employee->id,
                'dealership_id' => $this->dealership->id,
                'opening_photo_path' => $photoPath,
            ]);

            // Act
            $response = $this->actingAs($this->manager, 'sanctum')
                ->get("/api/v1/shift-photos/{$shift->id}/opening");

            // Assert
            $response->assertStatus(200);
        });

        it('allows employee to view own shift photo', function () {
            // Arrange
            $photoPath = 'shifts/test_photo.jpg';
            Storage::disk('shift_photos')->put($photoPath, 'fake image content');

            $shift = Shift::factory()->create([
                'user_id' => $this->employee->id,
                'dealership_id' => $this->dealership->id,
                'opening_photo_path' => $photoPath,
            ]);

            // Act
            $response = $this->actingAs($this->employee, 'sanctum')
                ->get("/api/v1/shift-photos/{$shift->id}/opening");

            // Assert
            $response->assertStatus(200);
        });

        it('denies employee access to other user shift photo', function () {
            // Arrange
            $otherEmployee = User::factory()->create([
                'role' => Role::EMPLOYEE->value,
                'dealership_id' => $this->dealership->id
            ]);

            $shift = Shift::factory()->create([
                'user_id' => $otherEmployee->id,
                'dealership_id' => $this->dealership->id,
                'opening_photo_path' => 'shifts/photo.jpg',
            ]);

            // Act
            $response = $this->actingAs($this->employee, 'sanctum')
                ->get("/api/v1/shift-photos/{$shift->id}/opening");

            // Assert
            $response->assertStatus(403)
                ->assertJsonPath('message', 'Доступ запрещён');
        });

        it('denies observer access to shift photos', function () {
            // Arrange
            $shift = Shift::factory()->create([
                'user_id' => $this->employee->id,
                'dealership_id' => $this->dealership->id,
                'opening_photo_path' => 'shifts/photo.jpg',
            ]);

            // Act
            $response = $this->actingAs($this->observer, 'sanctum')
                ->get("/api/v1/shift-photos/{$shift->id}/opening");

            // Assert
            $response->assertStatus(403);
        });

        it('returns 304 Not Modified for cached request', function () {
            // Arrange
            $photoPath = 'shifts/test_photo.jpg';
            Storage::disk('shift_photos')->put($photoPath, 'fake image content');

            $shift = Shift::factory()->create([
                'user_id' => $this->employee->id,
                'dealership_id' => $this->dealership->id,
                'opening_photo_path' => $photoPath,
            ]);

            // First request to get ETag
            $firstResponse = $this->actingAs($this->manager, 'sanctum')
                ->get("/api/v1/shift-photos/{$shift->id}/opening");

            $etag = $firstResponse->headers->get('ETag');

            // Act - Second request with If-None-Match
            $response = $this->actingAs($this->manager, 'sanctum')
                ->withHeaders(['If-None-Match' => $etag])
                ->get("/api/v1/shift-photos/{$shift->id}/opening");

            // Assert
            $response->assertStatus(304);
        });

        it('returns 404 for non-existent shift', function () {
            // Act
            $response = $this->actingAs($this->manager, 'sanctum')
                ->get('/api/v1/shift-photos/99999/opening');

            // Assert
            $response->assertStatus(404)
                ->assertJsonPath('message', 'Смена не найдена');
        });
    });
});
