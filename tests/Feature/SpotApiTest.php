<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Stourify\Enums\SpotStatus;
use Modules\Stourify\Models\City;
use Modules\Stourify\Models\Spot;
use Spatie\MediaLibrary\Conversions\ConversionCollection;
use Spatie\MediaLibrary\Conversions\FileManipulator;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
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

/**
 * media-library's default conversion pipeline dispatches the conversion job
 * `->afterCommit()` (config `queue_conversions_after_database_commit`).
 * `RefreshDatabase` wraps every test in a transaction that is rolled back,
 * never committed, so that callback never fires and conversions never run —
 * not a timing issue, a structural one. This runs the library's own
 * `FileManipulator::performConversions()` directly, synchronously, bypassing
 * the queue dispatch entirely rather than asserting on an artifact that
 * would never exist under this trait.
 */
function generateSpotConversionsSynchronously(Media $media): void
{
    $conversions = ConversionCollection::createForMedia($media)->getConversions($media->collection_name);

    app(FileManipulator::class)->performConversions($conversions, $media);
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

// ---------------------------------------------------------------------------
// Media — the photo gallery's data source
// ---------------------------------------------------------------------------

test('a spot with attached photos returns a media array with a uuid and a url per photo', function (): void {
    Storage::fake('media');

    $spot = Spot::factory()->for($this->organization)->create([
        'user_id' => $this->explorer->id, 'status' => SpotStatus::Published,
    ]);
    $spot->addMedia(UploadedFile::fake()->image('one.jpg', 200, 200))->toMediaCollection('attachments');
    $spot->addMedia(UploadedFile::fake()->image('two.jpg', 200, 200))->toMediaCollection('attachments');

    actingAsExplorer($this->explorer);

    $media = $this->getJson("/api/v1/spots/{$spot->uuid}", orgHeader($this->organization))
        ->assertOk()
        ->json('data.media');

    expect($media)->toHaveCount(2);
    foreach ($media as $item) {
        expect($item['uuid'])->not->toBeNull()
            ->and($item['url'])->not->toBeNull();
    }
});

test('a spot photo carries a thumb_url distinct from the original url', function (): void {
    Storage::fake('media');

    $spot = Spot::factory()->for($this->organization)->create([
        'user_id' => $this->explorer->id, 'status' => SpotStatus::Published,
    ]);
    $media = $spot->addMedia(UploadedFile::fake()->image('one.jpg', 800, 800))
        ->toMediaCollection('attachments');

    generateSpotConversionsSynchronously($media);

    // Assert via the library's own API (hasGeneratedConversion) rather than
    // the HTTP response, so a false pass can't hide behind an unrelated
    // resource bug.
    expect($media->hasGeneratedConversion('thumb'))->toBeTrue()
        ->and($media->hasGeneratedConversion('medium'))->toBeTrue();

    actingAsExplorer($this->explorer);

    $item = $this->getJson("/api/v1/spots/{$spot->uuid}", orgHeader($this->organization))
        ->assertOk()
        ->json('data.media.0');

    expect($item['thumb_url'])->not->toBeNull()
        ->and($item['thumb_url'])->not->toBe($item['url']);
});

test('a spot with no media returns an empty array, never null', function (): void {
    $spot = Spot::factory()->for($this->organization)->create([
        'user_id' => $this->explorer->id, 'status' => SpotStatus::Published,
    ]);

    actingAsExplorer($this->explorer);

    $this->getJson("/api/v1/spots/{$spot->uuid}", orgHeader($this->organization))
        ->assertOk()
        ->assertJsonPath('data.media', []);
});

test('the spot list page query count does not grow with the number of spots, only with the number of spots carrying media (no N+1)', function (): void {
    Storage::fake('media');

    // Five spots, each with one photo — the media-row count this measurement
    // holds fixed across both rounds. Growing it would also grow the
    // pre-existing, out-of-scope SpacesPathGenerator N+1 (one lazy
    // `$media->model` load per distinct media row), contaminating the
    // measurement of *this* module's invariant: that `media` is eager-loaded
    // once per page, not once per spot.
    collect(range(1, 5))->each(function (int $i): void {
        $spot = Spot::factory()->for($this->organization)->create([
            'user_id' => $this->explorer->id, 'status' => SpotStatus::Published,
        ]);
        $spot->addMedia(UploadedFile::fake()->image("photo{$i}.jpg", 200, 200))->toMediaCollection('attachments');
    });

    actingAsExplorer($this->explorer);

    DB::enableQueryLog();
    $this->getJson('/api/v1/spots?per_page=25', orgHeader($this->organization))
        ->assertOk()->assertJsonCount(5, 'data');
    $queriesFiveSpots = count(DB::getQueryLog());
    DB::flushQueryLog();
    DB::disableQueryLog();

    // The array cache store used in tests does not support tag-based
    // invalidation (see Cacheable::clearCache — falls back to a single-key
    // forget when the store lacks `tags()`), so the round-1 response for this
    // exact query string would otherwise still be cached here. Flushing
    // isolates the query count this test measures from that test-only
    // caching artifact, not from anything the fix changes in production
    // (Redis, the real store, supports tags and busts the list on every
    // spot save via `Cacheable::bootCacheable()`).
    Cache::flush();

    // Fifteen more spots, none with media — same five media-bearing spots,
    // same five media rows. Only the *host* (spot) count on the page grows.
    Spot::factory()->for($this->organization)->count(15)->create([
        'user_id' => $this->explorer->id, 'status' => SpotStatus::Published,
    ]);

    DB::enableQueryLog();
    $this->getJson('/api/v1/spots?per_page=25', orgHeader($this->organization))
        ->assertOk()->assertJsonCount(20, 'data');
    $queriesTwentySpots = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queriesTwentySpots)->toBeLessThanOrEqual($queriesFiveSpots + 2,
        "Expected no N+1: {$queriesFiveSpots} queries for 5 spots (5 with media), ".
        "{$queriesTwentySpots} for 20 spots (still only 5 with media)."
    );
});

test('two spots sharing a title get distinct slugs', function (): void {
    actingAsExplorer($this->explorer);

    $payload = ['title' => 'Sunset Point', 'latitude' => 6.1, 'longitude' => 125.1];

    $first = $this->postJson('/api/v1/spots', $payload, orgHeader($this->organization))->json('data.slug');
    $second = $this->postJson('/api/v1/spots', $payload, orgHeader($this->organization))->json('data.slug');

    expect($first)->toBe('sunset-point')
        ->and($second)->toBe('sunset-point-2');
});

/**
 * STOURIFY-23. `CrudService` has always gated this create, but only after the
 * rule set above had already run — so an unpermitted caller was answered with
 * a 422 describing the payload the server wanted. `SpotStoreRequest::authorize()`
 * now runs first, which is why an invalid body still comes back 403.
 */
test('creating a spot is denied without the create permission, whatever the payload', function (): void {
    actingAsExplorer($this->createUserWithPermissions($this->organization, ['stourify.spots.view']));

    $before = Spot::query()->count();

    $this->postJson('/api/v1/spots', [
        'title' => 'Hidden Cove',
        'latitude' => 10.3,
        'longitude' => 123.9,
    ], orgHeader($this->organization))->assertForbidden();

    $this->postJson('/api/v1/spots', ['title' => 'x'], orgHeader($this->organization))
        ->assertForbidden();

    expect(Spot::query()->count())->toBe($before);
});
