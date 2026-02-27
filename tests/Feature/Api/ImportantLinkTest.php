<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\AutoDealership;
use App\Models\ImportantLink;
use App\Models\User;

describe('Important Links API Endpoints', function () {
    beforeEach(function () {
        // Create dealership first
        $this->dealership = AutoDealership::factory()->create();

        // Create a manager user with access to the dealership
        $this->manager = User::factory()->create([
            'role' => Role::MANAGER->value,
            'dealership_id' => $this->dealership->id,
        ]);
        $this->employee = User::factory()->create([
            'role' => Role::EMPLOYEE->value,
            'dealership_id' => $this->dealership->id,
        ]);
    });

    describe('GET /api/v1/links', function () {
        it('returns paginated list of links', function () {
            // Arrange
            ImportantLink::factory()->count(5)->create(['creator_id' => $this->manager->id]);

            // Act
            $response = $this->actingAs($this->manager, 'sanctum')
                ->getJson('/api/v1/links');

            // Assert
            $response->assertStatus(200)
                ->assertJsonStructure([
                    'data' => [
                        '*' => ['id', 'title', 'url', 'description', 'sort_order', 'is_active', 'creator', 'dealership'],
                    ],
                    'current_page',
                    'per_page',
                    'total',
                ]);
        });

        it('filters links by dealership_id', function () {
            // Arrange - use manager's dealership and create another one for comparison
            $dealership2 = AutoDealership::factory()->create();
            // Give manager access to dealership2 as well
            $this->manager->dealerships()->attach($dealership2->id);

            ImportantLink::factory()->count(3)->create([
                'creator_id' => $this->manager->id,
                'dealership_id' => $this->dealership->id,
            ]);
            ImportantLink::factory()->count(2)->create([
                'creator_id' => $this->manager->id,
                'dealership_id' => $dealership2->id,
            ]);

            // Act
            $response = $this->actingAs($this->manager, 'sanctum')
                ->getJson('/api/v1/links?dealership_id=' . $this->dealership->id);

            // Assert
            $response->assertStatus(200);
            expect($response->json('data'))->toHaveCount(3);
        });

        it('filters links by is_active status', function () {
            // Arrange
            ImportantLink::factory()->count(3)->create([
                'creator_id' => $this->manager->id,
                'dealership_id' => $this->dealership->id,
                'is_active' => true,
            ]);
            ImportantLink::factory()->count(2)->create([
                'creator_id' => $this->manager->id,
                'dealership_id' => $this->dealership->id,
                'is_active' => false,
            ]);

            // Act
            $response = $this->actingAs($this->manager, 'sanctum')
                ->getJson('/api/v1/links?is_active=1');

            // Assert
            $response->assertStatus(200);
            expect($response->json('data'))->toHaveCount(3);
        });

        it('requires authentication', function () {
            // Act
            $response = $this->getJson('/api/v1/links');

            // Assert
            $response->assertStatus(401);
        });

        it('searches links by title, url, and description', function () {
            // Arrange
            ImportantLink::factory()->create([
                'creator_id' => $this->manager->id,
                'dealership_id' => $this->dealership->id,
                'title' => 'Internal Portal',
                'url' => 'https://portal.example.com',
                'description' => 'Access internal resources',
            ]);
            ImportantLink::factory()->create([
                'creator_id' => $this->manager->id,
                'dealership_id' => $this->dealership->id,
                'title' => 'CRM System',
                'url' => 'https://crm.example.com',
                'description' => 'Customer management',
            ]);
            ImportantLink::factory()->create([
                'creator_id' => $this->manager->id,
                'dealership_id' => $this->dealership->id,
                'title' => 'Dashboard',
                'url' => 'https://dashboard.example.com',
                'description' => 'Analytics dashboard',
            ]);

            // Act - Search by title
            $response = $this->actingAs($this->manager, 'sanctum')
                ->getJson('/api/v1/links?search=Portal');
            $response->assertStatus(200);
            expect($response->json('data'))->toHaveCount(1);
            expect($response->json('data.0.title'))->toBe('Internal Portal');

            // Act - Search by url
            $response = $this->actingAs($this->manager, 'sanctum')
                ->getJson('/api/v1/links?search=crm.example');
            $response->assertStatus(200);
            expect($response->json('data'))->toHaveCount(1);
            expect($response->json('data.0.title'))->toBe('CRM System');

            // Act - Search by description
            $response = $this->actingAs($this->manager, 'sanctum')
                ->getJson('/api/v1/links?search=analytics');
            $response->assertStatus(200);
            expect($response->json('data'))->toHaveCount(1);
            expect($response->json('data.0.title'))->toBe('Dashboard');
        });
    });

    describe('GET /api/v1/links/{id}', function () {
        it('returns a single link with relations', function () {
            // Arrange - create link in manager's dealership
            $link = ImportantLink::factory()->create([
                'creator_id' => $this->manager->id,
                'dealership_id' => $this->dealership->id,
            ]);

            // Act
            $response = $this->actingAs($this->manager, 'sanctum')
                ->getJson('/api/v1/links/' . $link->id);

            // Assert
            $response->assertStatus(200)
                ->assertJsonStructure([
                    'id',
                    'title',
                    'url',
                    'description',
                    'sort_order',
                    'is_active',
                    'creator',
                    'dealership',
                ])
                ->assertJson([
                    'id' => $link->id,
                    'title' => $link->title,
                    'url' => $link->url,
                ]);
        });

        it('returns 404 for non-existent link', function () {
            // Act
            $response = $this->actingAs($this->manager, 'sanctum')
                ->getJson('/api/v1/links/99999');

            // Assert
            $response->assertStatus(404)
                ->assertJson(['message' => 'Ссылка не найдена']);
        });

        it('requires authentication', function () {
            // Arrange
            $link = ImportantLink::factory()->create([
                'creator_id' => $this->manager->id,
                'dealership_id' => $this->dealership->id,
            ]);

            // Act
            $response = $this->getJson('/api/v1/links/' . $link->id);

            // Assert
            $response->assertStatus(401);
        });
    });

    describe('POST /api/v1/links', function () {
        it('creates a new link with valid data', function () {
            // Arrange
            $linkData = [
                'title' => 'Internal Portal',
                'url' => 'https://portal.example.com',
                'description' => 'Company internal portal',
                'dealership_id' => $this->dealership->id,
                'sort_order' => 10,
                'is_active' => true,
            ];

            // Act
            $response = $this->actingAs($this->manager, 'sanctum')
                ->postJson('/api/v1/links', $linkData);

            // Assert
            $response->assertStatus(201)
                ->assertJsonStructure(['id', 'title', 'url', 'description', 'creator', 'dealership'])
                ->assertJson([
                    'title' => 'Internal Portal',
                    'url' => 'https://portal.example.com',
                    'description' => 'Company internal portal',
                ]);

            // Verify in database
            $link = ImportantLink::where('title', 'Internal Portal')->first();
            expect($link)->not->toBeNull()
                ->and($link->creator_id)->toBe($this->manager->id);
        });

        it('creates global link when dealership_id is null', function () {
            // Arrange
            $linkData = [
                'title' => 'Global Resource',
                'url' => 'https://global.example.com',
                'sort_order' => 0,
            ];

            // Act
            $response = $this->actingAs($this->manager, 'sanctum')
                ->postJson('/api/v1/links', $linkData);

            // Assert
            $response->assertStatus(201)
                ->assertJson(['title' => 'Global Resource']);

            $link = ImportantLink::where('title', 'Global Resource')->first();
            expect($link->dealership_id)->toBeNull();
        });

        it('validates required fields', function () {
            // Arrange
            $linkData = [
                'description' => 'Missing required fields',
            ];

            // Act
            $response = $this->actingAs($this->manager, 'sanctum')
                ->postJson('/api/v1/links', $linkData);

            // Assert
            $response->assertStatus(422)
                ->assertJsonValidationErrors(['title', 'url']);
        });

        it('validates url format', function () {
            // Arrange
            $linkData = [
                'title' => 'Invalid URL',
                'url' => 'not-a-valid-url',
            ];

            // Act
            $response = $this->actingAs($this->manager, 'sanctum')
                ->postJson('/api/v1/links', $linkData);

            // Assert
            $response->assertStatus(422)
                ->assertJsonValidationErrors(['url']);
        });

        it('validates dealership exists', function () {
            // Arrange
            $linkData = [
                'title' => 'Test Link',
                'url' => 'https://example.com',
                'dealership_id' => 99999, // Non-existent
            ];

            // Act
            $response = $this->actingAs($this->manager, 'sanctum')
                ->postJson('/api/v1/links', $linkData);

            // Assert
            $response->assertStatus(422)
                ->assertJsonValidationErrors(['dealership_id']);
        });

        it('requires manager or owner role', function () {
            // Arrange
            $linkData = [
                'title' => 'Test Link',
                'url' => 'https://example.com',
            ];

            // Act - Try as employee
            $response = $this->actingAs($this->employee, 'sanctum')
                ->postJson('/api/v1/links', $linkData);

            // Assert
            $response->assertStatus(403);
        });

        it('requires authentication', function () {
            // Arrange
            $linkData = [
                'title' => 'Test Link',
                'url' => 'https://example.com',
            ];

            // Act
            $response = $this->postJson('/api/v1/links', $linkData);

            // Assert
            $response->assertStatus(401);
        });
    });

    describe('PUT /api/v1/links/{id}', function () {
        it('updates link with valid data', function () {
            // Arrange
            $link = ImportantLink::factory()->create([
                'creator_id' => $this->manager->id,
                'dealership_id' => $this->dealership->id,
                'title' => 'Original Title',
            ]);

            $updateData = [
                'title' => 'Updated Title',
                'url' => 'https://updated.example.com',
                'is_active' => false,
            ];

            // Act
            $response = $this->actingAs($this->manager, 'sanctum')
                ->putJson('/api/v1/links/' . $link->id, $updateData);

            // Assert
            $response->assertStatus(200)
                ->assertJson([
                    'id' => $link->id,
                    'title' => 'Updated Title',
                    'url' => 'https://updated.example.com',
                    'is_active' => false,
                ]);

            // Verify in database
            $link->refresh();
            expect($link->title)->toBe('Updated Title')
                ->and($link->is_active)->toBeFalse();
        });

        it('returns 404 for non-existent link', function () {
            // Act
            $response = $this->actingAs($this->manager, 'sanctum')
                ->putJson('/api/v1/links/99999', ['title' => 'Test']);

            // Assert
            $response->assertStatus(404);
        });

        it('validates url format on update', function () {
            // Arrange
            $link = ImportantLink::factory()->create([
                'creator_id' => $this->manager->id,
                'dealership_id' => $this->dealership->id,
            ]);

            // Act
            $response = $this->actingAs($this->manager, 'sanctum')
                ->putJson('/api/v1/links/' . $link->id, ['url' => 'invalid-url']);

            // Assert
            $response->assertStatus(422)
                ->assertJsonValidationErrors(['url']);
        });

        it('requires manager or owner role', function () {
            // Arrange
            $link = ImportantLink::factory()->create([
                'creator_id' => $this->manager->id,
                'dealership_id' => $this->dealership->id,
            ]);

            // Act
            $response = $this->actingAs($this->employee, 'sanctum')
                ->putJson('/api/v1/links/' . $link->id, ['title' => 'Updated']);

            // Assert
            $response->assertStatus(403);
        });
    });

    describe('DELETE /api/v1/links/{id}', function () {
        it('deletes link successfully', function () {
            // Arrange
            $link = ImportantLink::factory()->create([
                'creator_id' => $this->manager->id,
                'dealership_id' => $this->dealership->id,
            ]);

            // Act
            $response = $this->actingAs($this->manager, 'sanctum')
                ->deleteJson('/api/v1/links/' . $link->id);

            // Assert
            $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'Ссылка успешно удалена',
                ]);

            // Verify deleted from database
            expect(ImportantLink::find($link->id))->toBeNull();
        });

        it('returns 404 for non-existent link', function () {
            // Act
            $response = $this->actingAs($this->manager, 'sanctum')
                ->deleteJson('/api/v1/links/99999');

            // Assert
            $response->assertStatus(404);
        });

        it('requires manager or owner role', function () {
            // Arrange
            $link = ImportantLink::factory()->create([
                'creator_id' => $this->manager->id,
                'dealership_id' => $this->dealership->id,
            ]);

            // Act
            $response = $this->actingAs($this->employee, 'sanctum')
                ->deleteJson('/api/v1/links/' . $link->id);

            // Assert
            $response->assertStatus(403);
        });
    });

    describe('Link Ordering', function () {
        it('returns links ordered by sort_order', function () {
            // Arrange
            ImportantLink::factory()->create(['creator_id' => $this->manager->id, 'dealership_id' => $this->dealership->id, 'sort_order' => 30, 'title' => 'Third']);
            ImportantLink::factory()->create(['creator_id' => $this->manager->id, 'dealership_id' => $this->dealership->id, 'sort_order' => 10, 'title' => 'First']);
            ImportantLink::factory()->create(['creator_id' => $this->manager->id, 'dealership_id' => $this->dealership->id, 'sort_order' => 20, 'title' => 'Second']);

            // Act
            $response = $this->actingAs($this->manager, 'sanctum')
                ->getJson('/api/v1/links');

            // Assert
            $response->assertStatus(200);
            $titles = collect($response->json('data'))->pluck('title')->toArray();
            expect($titles[0])->toBe('First')
                ->and($titles[1])->toBe('Second')
                ->and($titles[2])->toBe('Third');
        });
    });
});
