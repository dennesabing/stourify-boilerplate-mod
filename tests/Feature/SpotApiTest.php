<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Stourify\Enums\SpotStatus;
use Modules\Stourify\Models\City;
use Modules\Stourify\Models\Spot;
use Tests\Traits\InteractsWithTestSetup;

uses(RefreshDatabase::class, InteractsWithTestSetup::class);

/**
 * The permissions an ordinary explorer holds — everything except `manage`,
 * which is what separates a contributor from a moderator.
 *
 * @var list<string>
 */
const EXPLORER_PERMISSIONS = [
    'stourify.spots.view',
    'stourify.spots.create',
    'stourify.spots.update',
    'stourify.spots.delete',
];

beforeEach(function (): void {
    $this->organization = $this->setUpTestOrganization();
    $this->seedPermissions([...EXPLORER_PERMISSIONS, 'stourify.spots.manage']);

    $this->explorer = $this->createUserWithPermissions($this->organization, EXPLORER_PERMISSIONS);
});

/**
 * Authenticate as $user against the Sanctum guard the API routes use.
 */
function actingAsExplorer(User $user): void
{
    Sanctum::actingAs($user);
}

// ---------------------------------------------------------------------------
// Happy path
// ---------------------------------------------------------------------------

test('an explorer creates a spot', function (): void {
    actingAsExplorer($this->explorer);

    $response = $this->postJson('/api/v1/spots', [
        'title' => 'Sunset Point',
        'description' => 'Best light in the city about an hour before dusk.',
        'latitude' => 6.1164,
        'longitude' => 125.1716,
        'categories' => ['coast', 'nature'],
        'status' => SpotStatus::Published->value,
    ], orgHeader($this->organization));

    $response->assertCreated()
        ->assertJsonPath('data.title', 'Sunset Point')
        ->assertJsonPath('data.slug', 'sunset-point')
        ->assertJsonPath('data.status', SpotStatus::Published->value)
        ->assertJsonPath('data.can.update', true);

    $this->assertDatabaseHas('sto_spots', [
        'title' => 'Sunset Point',
        'user_id' => $this->explorer->id,
        'organization_id' => $this->organization->id,
    ]);
});

test('a spot defaults to draft when no status is given', function (): void {
    actingAsExplorer($this->explorer);

    $this->postJson('/api/v1/spots', [
        'title' => 'Unfinished Cove',
        'latitude' => 6.1,
        'longitude' => 125.1,
    ], orgHeader($this->organization))
        ->assertCreated()
        ->assertJsonPath('data.status', SpotStatus::Draft->value);
});

test('a spot is shown, updated and deleted by its author', function (): void {
    actingAsExplorer($this->explorer);
    $spot = Spot::factory()->for($this->organization)->create([
        'user_id' => $this->explorer->id,
        'status' => SpotStatus::Published,
    ]);

    $this->getJson("/api/v1/spots/{$spot->uuid}", orgHeader($this->organization))
        ->assertOk()
        ->assertJsonPath('data.uuid', $spot->uuid);

    $this->patchJson("/api/v1/spots/{$spot->uuid}", [
        'title' => 'Renamed Spot',
    ], orgHeader($this->organization))
        ->assertOk()
        ->assertJsonPath('data.title', 'Renamed Spot');

    $this->deleteJson("/api/v1/spots/{$spot->uuid}", [], orgHeader($this->organization))
        ->assertOk();

    $this->assertSoftDeleted('sto_spots', ['id' => $spot->id]);
});

test('a spot links to a city by uuid, never by id', function (): void {
    actingAsExplorer($this->explorer);
    $city = City::factory()->for($this->organization)->create(['name' => 'General Santos']);

    $this->postJson('/api/v1/spots', [
        'title' => 'City Anchored Spot',
        'latitude' => 6.1,
        'longitude' => 125.1,
        'city_uuid' => $city->uuid,
    ], orgHeader($this->organization))
        ->assertCreated()
        ->assertJsonPath('data.city.uuid', $city->uuid);

    $this->assertDatabaseHas('sto_spots', [
        'title' => 'City Anchored Spot',
        'city_id' => $city->id,
    ]);
});

// ---------------------------------------------------------------------------
// Authentication and permission gating
// ---------------------------------------------------------------------------

test('every spot endpoint rejects an unauthenticated caller', function (string $method, string $uri): void {
    $this->json($method, $uri, [], orgHeader($this->organization))->assertUnauthorized();
})->with([
    ['get', '/api/v1/spots'],
    ['get', '/api/v1/spots/nearby?lat=6.1&lng=125.1'],
    ['post', '/api/v1/spots'],
]);

test('listing is denied without the view permission', function (): void {
    $outsider = $this->createUserWithPermissions($this->organization, []);
    actingAsExplorer($outsider);

    $this->getJson('/api/v1/spots', orgHeader($this->organization))->assertForbidden();
});

test('creating is denied without the create permission', function (): void {
    $viewer = $this->createUserWithPermissions($this->organization, ['stourify.spots.view']);
    actingAsExplorer($viewer);

    $this->postJson('/api/v1/spots', [
        'title' => 'Not Allowed',
        'latitude' => 6.1,
        'longitude' => 125.1,
    ], orgHeader($this->organization))->assertForbidden();
});

test('one explorer cannot edit or delete another explorer\'s spot', function (): void {
    $other = $this->createUserWithPermissions($this->organization, EXPLORER_PERMISSIONS);
    $spot = Spot::factory()->for($this->organization)->create([
        'user_id' => $other->id,
        'status' => SpotStatus::Published,
    ]);

    actingAsExplorer($this->explorer);

    $this->patchJson("/api/v1/spots/{$spot->uuid}", ['title' => 'Hijacked'], orgHeader($this->organization))
        ->assertForbidden();

    $this->deleteJson("/api/v1/spots/{$spot->uuid}", [], orgHeader($this->organization))
        ->assertForbidden();
});

test('a moderator edits and deletes any spot', function (): void {
    $moderator = $this->createUserWithPermissions(
        $this->organization,
        [...EXPLORER_PERMISSIONS, 'stourify.spots.manage'],
    );
    $spot = Spot::factory()->for($this->organization)->create([
        'user_id' => $this->explorer->id,
        'status' => SpotStatus::Published,
    ]);

    actingAsExplorer($moderator);

    $this->patchJson("/api/v1/spots/{$spot->uuid}", ['title' => 'Moderated'], orgHeader($this->organization))
        ->assertOk()
        ->assertJsonPath('data.title', 'Moderated');
});

// ---------------------------------------------------------------------------
// Draft visibility — the rule a policy alone cannot enforce
// ---------------------------------------------------------------------------

test('a draft is invisible to other explorers but visible to its author', function (): void {
    $other = $this->createUserWithPermissions($this->organization, EXPLORER_PERMISSIONS);
    $draft = Spot::factory()->for($this->organization)->create([
        'user_id' => $this->explorer->id,
        'status' => SpotStatus::Draft,
    ]);

    actingAsExplorer($other);
    $this->getJson("/api/v1/spots/{$draft->uuid}", orgHeader($this->organization))->assertForbidden();

    actingAsExplorer($this->explorer);
    $this->getJson("/api/v1/spots/{$draft->uuid}", orgHeader($this->organization))->assertOk();
});

test('the list never leaks another explorer\'s draft', function (): void {
    $other = $this->createUserWithPermissions($this->organization, EXPLORER_PERMISSIONS);

    $mineDraft = Spot::factory()->for($this->organization)
        ->create(['user_id' => $this->explorer->id, 'status' => SpotStatus::Draft]);
    $theirDraft = Spot::factory()->for($this->organization)
        ->create(['user_id' => $other->id, 'status' => SpotStatus::Draft]);
    $published = Spot::factory()->for($this->organization)
        ->create(['user_id' => $other->id, 'status' => SpotStatus::Published]);

    actingAsExplorer($this->explorer);
    $uuids = collect($this->getJson('/api/v1/spots', orgHeader($this->organization))
        ->assertOk()
        ->json('data'))
        ->pluck('uuid');

    expect($uuids)->toContain($mineDraft->uuid)
        ->and($uuids)->toContain($published->uuid)
        ->and($uuids)->not->toContain($theirDraft->uuid);
});

test('a moderator sees every draft in the list', function (): void {
    $moderator = $this->createUserWithPermissions(
        $this->organization,
        [...EXPLORER_PERMISSIONS, 'stourify.spots.manage'],
    );
    $draft = Spot::factory()->for($this->organization)
        ->create(['user_id' => $this->explorer->id, 'status' => SpotStatus::Draft]);

    actingAsExplorer($moderator);

    expect(collect($this->getJson('/api/v1/spots', orgHeader($this->organization))->json('data'))->pluck('uuid'))
        ->toContain($draft->uuid);
});

test('nearby excludes drafts even from their own author', function (): void {
    Spot::factory()->for($this->organization)->create([
        'user_id' => $this->explorer->id,
        'status' => SpotStatus::Draft,
        'latitude' => 6.1164,
        'longitude' => 125.1716,
    ]);

    actingAsExplorer($this->explorer);

    $this->getJson('/api/v1/spots/nearby?lat=6.1164&lng=125.1716&radius=5', orgHeader($this->organization))
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

// ---------------------------------------------------------------------------
// Discovery
// ---------------------------------------------------------------------------

test('nearby returns spots inside the radius, closest first, with a distance', function (): void {
    $near = Spot::factory()->for($this->organization)->create([
        'user_id' => $this->explorer->id, 'status' => SpotStatus::Published,
        'latitude' => 6.1164, 'longitude' => 125.1716,
    ]);
    $mid = Spot::factory()->for($this->organization)->create([
        'user_id' => $this->explorer->id, 'status' => SpotStatus::Published,
        'latitude' => 6.1400, 'longitude' => 125.1716,
    ]);
    // ~110 km north — comfortably outside any permitted radius.
    Spot::factory()->for($this->organization)->create([
        'user_id' => $this->explorer->id, 'status' => SpotStatus::Published,
        'latitude' => 7.1164, 'longitude' => 125.1716,
    ]);

    actingAsExplorer($this->explorer);

    $data = $this->getJson('/api/v1/spots/nearby?lat=6.1164&lng=125.1716&radius=10', orgHeader($this->organization))
        ->assertOk()
        ->json('data');

    expect(collect($data)->pluck('uuid')->all())->toBe([$near->uuid, $mid->uuid])
        ->and($data[0]['distance_km'])->toBeLessThan($data[1]['distance_km'])
        ->and($data[0]['distance_km'])->toBeLessThan(0.01);
});

test('distance_km is absent outside the nearby endpoint', function (): void {
    Spot::factory()->for($this->organization)
        ->create(['user_id' => $this->explorer->id, 'status' => SpotStatus::Published]);

    actingAsExplorer($this->explorer);

    $this->getJson('/api/v1/spots', orgHeader($this->organization))
        ->assertOk()
        ->assertJsonMissingPath('data.0.distance_km');
});

test('the list filters to the callers own spots', function (): void {
    $other = $this->createUserWithPermissions($this->organization, EXPLORER_PERMISSIONS);
    $mine = Spot::factory()->for($this->organization)
        ->create(['user_id' => $this->explorer->id, 'status' => SpotStatus::Published]);
    Spot::factory()->for($this->organization)
        ->create(['user_id' => $other->id, 'status' => SpotStatus::Published]);

    actingAsExplorer($this->explorer);

    $data = $this->getJson('/api/v1/spots?mine=1', orgHeader($this->organization))->assertOk()->json('data');

    expect($data)->toHaveCount(1)
        ->and($data[0]['uuid'])->toBe($mine->uuid);
});

// ---------------------------------------------------------------------------
// Validation
// ---------------------------------------------------------------------------

test('creating a spot validates its required and bounded fields', function (): void {
    actingAsExplorer($this->explorer);

    $this->postJson('/api/v1/spots', [], orgHeader($this->organization))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['title', 'latitude', 'longitude']);

    $this->postJson('/api/v1/spots', [
        'title' => 'ok',
        'latitude' => 91,
        'longitude' => 181,
    ], orgHeader($this->organization))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['title', 'latitude', 'longitude']);
});

test('a client cannot create a spot directly into a moderation state', function (): void {
    actingAsExplorer($this->explorer);

    $this->postJson('/api/v1/spots', [
        'title' => 'Sneaky Spot',
        'latitude' => 6.1,
        'longitude' => 125.1,
        'status' => SpotStatus::Removed->value,
    ], orgHeader($this->organization))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status']);
});

test('is_verified cannot be set by the author', function (): void {
    actingAsExplorer($this->explorer);
    $spot = Spot::factory()->for($this->organization)
        ->create(['user_id' => $this->explorer->id, 'status' => SpotStatus::Published, 'is_verified' => false]);

    $this->patchJson("/api/v1/spots/{$spot->uuid}", [
        'is_verified' => true,
    ], orgHeader($this->organization))->assertOk();

    expect($spot->fresh()->is_verified)->toBeFalse();
});

test('nearby requires coordinates and caps the radius', function (): void {
    actingAsExplorer($this->explorer);

    $this->getJson('/api/v1/spots/nearby', orgHeader($this->organization))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['lat', 'lng']);

    $this->getJson('/api/v1/spots/nearby?lat=6.1&lng=125.1&radius=9999', orgHeader($this->organization))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['radius']);
});

test('the list rejects an unsortable column and an oversized page', function (): void {
    actingAsExplorer($this->explorer);

    $this->getJson('/api/v1/spots?sort=password', orgHeader($this->organization))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['sort']);

    $this->getJson('/api/v1/spots?per_page=5000', orgHeader($this->organization))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['per_page']);
});

// ---------------------------------------------------------------------------
// Pagination and slugs
// ---------------------------------------------------------------------------

test('the list paginates', function (): void {
    Spot::factory()->for($this->organization)->count(7)
        ->create(['user_id' => $this->explorer->id, 'status' => SpotStatus::Published]);

    actingAsExplorer($this->explorer);

    $this->getJson('/api/v1/spots?per_page=3', orgHeader($this->organization))
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('meta.total', 7)
        ->assertJsonPath('meta.per_page', 3);
});

test('two spots sharing a title get distinct slugs', function (): void {
    actingAsExplorer($this->explorer);

    $payload = ['title' => 'Sunset Point', 'latitude' => 6.1, 'longitude' => 125.1];

    $first = $this->postJson('/api/v1/spots', $payload, orgHeader($this->organization))->json('data.slug');
    $second = $this->postJson('/api/v1/spots', $payload, orgHeader($this->organization))->json('data.slug');

    expect($first)->toBe('sunset-point')
        ->and($second)->toBe('sunset-point-2');
});
