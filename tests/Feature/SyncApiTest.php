<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Stourify\Enums\SpotStatus;
use Modules\Stourify\Models\City;
use Modules\Stourify\Models\ExplorerProfile;
use Modules\Stourify\Models\Follow;
use Modules\Stourify\Models\Review;
use Modules\Stourify\Models\Spot;
use Modules\Stourify\Models\WishlistItem;
use Tests\Traits\InteractsWithTestSetup;

uses(RefreshDatabase::class, InteractsWithTestSetup::class);

/**
 * The full explorer permission set the sync push path exercises — every
 * pushable table's create/update/delete ability.
 *
 * @var list<string>
 */
const SYNC_PERMISSIONS = [
    'stourify.spots.view',
    'stourify.spots.create',
    'stourify.spots.update',
    'stourify.spots.delete',
    'stourify.reviews.view',
    'stourify.reviews.create',
    'stourify.reviews.update',
    'stourify.reviews.delete',
    'stourify.wishlist.manage',
    'stourify.follows.manage',
];

beforeEach(function (): void {
    $this->organization = $this->setUpTestOrganization();
    $this->seedPermissions(SYNC_PERMISSIONS);

    $this->explorer = $this->createUserWithPermissions($this->organization, SYNC_PERMISSIONS);
    $this->other = $this->createUserWithPermissions($this->organization, SYNC_PERMISSIONS);
});

function actingAsSyncer(User $user): void
{
    Sanctum::actingAs($user);
}

function deltaUrl(?string $since = null): string
{
    return $since === null ? '/api/v1/stourify/sync/delta' : '/api/v1/stourify/sync/delta?since='.urlencode($since);
}

// ---------------------------------------------------------------------------
// Delta scope
// ---------------------------------------------------------------------------

test('the delta returns the callers own rows across every table, plus all cities, and excludes another explorers rows', function (): void {
    $city = City::factory()->for($this->organization)->create();

    $mySpot = Spot::factory()->for($this->organization)->create(['user_id' => $this->explorer->id, 'status' => SpotStatus::Published]);
    $theirSpot = Spot::factory()->for($this->organization)->create(['user_id' => $this->other->id, 'status' => SpotStatus::Published]);

    $myReview = Review::factory()->for($this->organization)->create(['user_id' => $this->explorer->id, 'spot_id' => $theirSpot->id]);
    Review::factory()->for($this->organization)->create(['user_id' => $this->other->id, 'spot_id' => $mySpot->id]);

    $myWish = WishlistItem::factory()->for($this->organization)->create(['user_id' => $this->explorer->id, 'spot_id' => $theirSpot->id]);
    WishlistItem::factory()->for($this->organization)->create(['user_id' => $this->other->id, 'spot_id' => $mySpot->id]);

    $myFollow = Follow::factory()->for($this->organization)->create(['follower_id' => $this->explorer->id, 'followee_id' => $this->other->id]);

    $myProfile = ExplorerProfile::factory()->for($this->organization)->create(['user_id' => $this->explorer->id]);
    ExplorerProfile::factory()->for($this->organization)->create(['user_id' => $this->other->id]);

    actingAsSyncer($this->explorer);

    $response = $this->getJson(deltaUrl(), orgHeader($this->organization))->assertOk();

    $spotUuids = collect($response->json('sto_spots.created'))->pluck('uuid');
    expect($spotUuids)->toContain($mySpot->uuid)->and($spotUuids)->not->toContain($theirSpot->uuid);

    $reviewUuids = collect($response->json('sto_reviews.created'))->pluck('uuid');
    expect($reviewUuids)->toContain($myReview->uuid)->and($reviewUuids)->toHaveCount(1);

    $wishUuids = collect($response->json('sto_wishlist_items.created'))->pluck('uuid');
    expect($wishUuids)->toContain($myWish->uuid)->and($wishUuids)->toHaveCount(1);

    $followUuids = collect($response->json('sto_follows.created'))->pluck('uuid');
    expect($followUuids)->toContain($myFollow->uuid)->and($followUuids)->toHaveCount(1);

    $profileUuids = collect($response->json('sto_explorer_profiles.created'))->pluck('uuid');
    expect($profileUuids)->toContain($myProfile->uuid)->and($profileUuids)->toHaveCount(1);

    // Cities are global reference data — every caller sees all of them.
    expect(collect($response->json('sto_cities.created'))->pluck('uuid'))->toContain($city->uuid);

    expect($response->json('server_time'))->not->toBeNull();
    expect(Carbon::parse($response->json('server_time')))->toBeInstanceOf(Carbon::class);
});

// ---------------------------------------------------------------------------
// Delta cursor and the created/updated split
// ---------------------------------------------------------------------------

test('without a since cursor the full scoped set comes back in created, with updated and deleted empty', function (): void {
    Spot::factory()->for($this->organization)->create(['user_id' => $this->explorer->id, 'status' => SpotStatus::Published]);

    actingAsSyncer($this->explorer);

    $response = $this->getJson(deltaUrl(), orgHeader($this->organization))->assertOk();

    expect($response->json('sto_spots.created'))->toHaveCount(1)
        ->and($response->json('sto_spots.updated'))->toBe([])
        ->and($response->json('sto_spots.deleted'))->toBe([]);
});

test('a row created after the cursor appears in created; one created before does not', function (): void {
    $this->travelTo(now()->subMinutes(10));
    $before = Spot::factory()->for($this->organization)->create(['user_id' => $this->explorer->id, 'status' => SpotStatus::Published]);
    $this->travelBack();

    $cursor = now()->toIso8601String();

    $this->travelTo(now()->addMinute());
    $after = Spot::factory()->for($this->organization)->create(['user_id' => $this->explorer->id, 'status' => SpotStatus::Published]);
    $this->travelBack();

    actingAsSyncer($this->explorer);

    $uuids = collect($this->getJson(deltaUrl($cursor), orgHeader($this->organization))->assertOk()->json('sto_spots.created'))->pluck('uuid');

    expect($uuids)->toContain($after->uuid)->and($uuids)->not->toContain($before->uuid);
});

test('a pre-existing row touched after the cursor lands in updated, not created', function (): void {
    $this->travelTo(now()->subMinutes(10));
    $spot = Spot::factory()->for($this->organization)->create(['user_id' => $this->explorer->id, 'status' => SpotStatus::Published]);
    $this->travelBack();

    $cursor = now()->toIso8601String();

    $this->travelTo(now()->addMinute());
    $spot->update(['title' => 'Renamed after cursor']);
    $this->travelBack();

    actingAsSyncer($this->explorer);

    $response = $this->getJson(deltaUrl($cursor), orgHeader($this->organization))->assertOk();

    expect(collect($response->json('sto_spots.created'))->pluck('uuid'))->not->toContain($spot->uuid);
    expect(collect($response->json('sto_spots.updated'))->pluck('uuid'))->toContain($spot->uuid);
});

// ---------------------------------------------------------------------------
// Tombstones
// ---------------------------------------------------------------------------

test('soft-deleting a spot and hard-deleting a follow each yield a deleted uuid in a later delta', function (): void {
    $spot = Spot::factory()->for($this->organization)->create(['user_id' => $this->explorer->id, 'status' => SpotStatus::Published]);
    $follow = Follow::factory()->for($this->organization)->create(['follower_id' => $this->explorer->id, 'followee_id' => $this->other->id]);

    $cursor = now()->toIso8601String();

    $this->travelTo(now()->addMinute());
    $spot->delete();
    $follow->delete();
    $this->travelBack();

    actingAsSyncer($this->explorer);
    $response = $this->getJson(deltaUrl($cursor), orgHeader($this->organization))->assertOk();

    expect($response->json('sto_spots.deleted'))->toContain($spot->uuid);
    expect($response->json('sto_follows.deleted'))->toContain($follow->uuid);
});

test('a follow tombstone reaches both parties deltas', function (): void {
    $follow = Follow::factory()->for($this->organization)->create(['follower_id' => $this->explorer->id, 'followee_id' => $this->other->id]);

    $cursor = now()->toIso8601String();

    $this->travelTo(now()->addMinute());
    $follow->delete();
    $this->travelBack();

    actingAsSyncer($this->explorer);
    expect($this->getJson(deltaUrl($cursor), orgHeader($this->organization))->assertOk()->json('sto_follows.deleted'))
        ->toContain($follow->uuid);

    actingAsSyncer($this->other);
    expect($this->getJson(deltaUrl($cursor), orgHeader($this->organization))->assertOk()->json('sto_follows.deleted'))
        ->toContain($follow->uuid);
});

// ---------------------------------------------------------------------------
// Row shape
// ---------------------------------------------------------------------------

test('a serialized row carries an integer id and FKs, a uuid, JSON columns as arrays, and no can key or undeclared column', function (): void {
    $city = City::factory()->for($this->organization)->create();
    $spot = Spot::factory()->for($this->organization)->create([
        'user_id' => $this->explorer->id,
        'city_id' => $city->id,
        'status' => SpotStatus::Published,
        'categories' => ['coast', 'nature'],
    ]);

    actingAsSyncer($this->explorer);
    $row = collect($this->getJson(deltaUrl(), orgHeader($this->organization))->assertOk()->json('sto_spots.created'))
        ->firstWhere('uuid', $spot->uuid);

    expect($row['id'])->toBeInt()->and($row['id'])->toBe($spot->id)
        ->and($row['user_id'])->toBeInt()->and($row['user_id'])->toBe($this->explorer->id)
        ->and($row['city_id'])->toBeInt()->and($row['city_id'])->toBe($city->id)
        ->and($row['uuid'])->toBe($spot->uuid)
        ->and($row['categories'])->toBe(['coast', 'nature'])
        ->and($row)->not->toHaveKey('can')
        ->and($row)->not->toHaveKey('reactions')
        ->and(array_keys($row))->toEqualCanonicalizing([
            'id', 'uuid', 'organization_id', 'user_id', 'city_id', 'owner_user_id',
            'title', 'slug', 'description', 'latitude', 'longitude', 'address',
            'categories', 'hours', 'status', 'is_verified',
            'rating_average', 'reviews_count', 'saves_count',
            // Not a stored column -- an accessor -- but on the wire like any
            // other, which is the only thing this assertion is about (STOURIFY-192).
            'cover_photo_url',
            'created_at', 'updated_at', 'deleted_at',
        ]);
});

/**
 * The spot's photo has to reach the device, or the app's own list of its spots
 * can only draw grey rectangles (STOURIFY-192).
 *
 * The delta speaks in flat rows of columns and a spot's photos live in a
 * separate table, so this column is an accessor rather than something stored.
 * These cases pin what it resolves to, because "there is no photo" and "the
 * photo did not come through" look identical on a phone and only one is a bug.
 */
test('the delta carries a spot photo, preferring the thumbnail over the full image', function (): void {
    $spot = Spot::factory()->for($this->organization)->create([
        'user_id' => $this->explorer->id,
        'status' => SpotStatus::Published,
    ]);

    $spot->addMedia(UploadedFile::fake()->image('photo.jpg', 800, 800))
        ->toMediaCollection('attachments');

    actingAsSyncer($this->explorer);
    $row = collect($this->getJson(deltaUrl(), orgHeader($this->organization))->assertOk()->json('sto_spots.created'))
        ->firstWhere('uuid', $spot->uuid);

    expect($row['cover_photo_url'])->toBeString()
        ->and($row['cover_photo_url'])->not->toBe('');

    // A list draws a 96-pixel square. The originals here run to megabytes, so a
    // list of twenty would pull tens of megabytes over a phone connection to
    // show a column of thumbnails.
    expect($row['cover_photo_url'])->toContain('thumb');
});

test('a spot with no photo carries null rather than an empty string or a missing key', function (): void {
    $spot = Spot::factory()->for($this->organization)->create([
        'user_id' => $this->explorer->id,
        'status' => SpotStatus::Published,
    ]);

    actingAsSyncer($this->explorer);
    $row = collect($this->getJson(deltaUrl(), orgHeader($this->organization))->assertOk()->json('sto_spots.created'))
        ->firstWhere('uuid', $spot->uuid);

    // The key is always present -- the device's schema declares the column, and
    // a row that simply omitted it would leave whatever was there before.
    expect($row)->toHaveKey('cover_photo_url')
        ->and($row['cover_photo_url'])->toBeNull();
});

/**
 * The reason `SyncRegistry::eagerLoad()` exists.
 *
 * A delta fetches every changed row at once and then serialises them one by
 * one, so a column that reads a RELATION costs a query per row unless it was
 * loaded up front. Without the eager load, a hundred spots is a hundred and one
 * queries -- and the cost lands on the sync path, which is the least observed
 * part of the product and the last place anyone would look.
 */
test('the delta does not run one query per spot to find its photo', function (): void {
    $makeSpotWithPhoto = function (int $i): void {
        $spot = Spot::factory()->for($this->organization)->create([
            'user_id' => $this->explorer->id,
            'status' => SpotStatus::Published,
        ]);

        $spot->addMedia(UploadedFile::fake()->image("photo{$i}.jpg", 200, 200))
            ->toMediaCollection('attachments');
    };

    foreach (range(1, 5) as $i) {
        $makeSpotWithPhoto($i);
    }

    actingAsSyncer($this->explorer);

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $this->getJson(deltaUrl(), orgHeader($this->organization))->assertOk();
    $withFive = $queries;

    // Five more spots, each with its own photo. Fetched per spot, this second
    // delta would cost roughly five more queries than the first. Eager-loaded,
    // it costs the same handful either way.
    foreach (range(6, 10) as $i) {
        $makeSpotWithPhoto($i);
    }

    $queries = 0;
    $this->getJson(deltaUrl(), orgHeader($this->organization))->assertOk();

    // Measured while writing this: 33 queries for ten spots before the fix,
    // 13 after, and flat as spots are added. The assertion is the SHAPE rather
    // than the number -- a count pinned to 13 would fail on any unrelated
    // change and teach the next person to raise the ceiling.
    expect($queries)->toBeLessThanOrEqual($withFive);
});

/**
 * A photo has to make the spot look CHANGED, or the sync never delivers it
 * (STOURIFY-208).
 *
 * This is the half STOURIFY-192 was missing, and it is invisible from the
 * server's side: `cover_photo_url` was computed perfectly and simply never
 * travelled. The delta only resends a row whose `updated_at` moved, and a spot
 * gains its photos a second or two AFTER it is created — so without this the
 * row is never sent again. Not late. Never.
 *
 * These assert on the timestamp rather than on the URL, deliberately. Asserting
 * that a freshly-fetched spot carries a cover URL passes with or without the
 * fix, because a full fetch reads the media table directly. The delta is the
 * thing that was broken, and `updated_at` is what the delta reads.
 */
test('attaching a photo marks the spot as changed', function (): void {
    $spot = Spot::factory()->for($this->organization)->create([
        'user_id' => $this->explorer->id,
        'status' => SpotStatus::Published,
    ]);

    $before = $spot->updated_at;

    // A second of daylight between the two, so the comparison is about the
    // touch and not about clock resolution.
    $this->travel(2)->seconds();

    $spot->addMedia(UploadedFile::fake()->image('photo.jpg', 200, 200))
        ->toMediaCollection('attachments');

    expect($spot->fresh()->updated_at->gt($before))->toBeTrue();
});

test('a spot whose photo arrives after the cursor still reaches the device', function (): void {
    // The exact sequence every spot in this app goes through, and the one that
    // was broken: create, sync, THEN upload the photo.
    $spot = Spot::factory()->for($this->organization)->create([
        'user_id' => $this->explorer->id,
        'status' => SpotStatus::Published,
    ]);

    actingAsSyncer($this->explorer);

    // The device pulls, and correctly learns the spot has no photo yet.
    $cursor = now();
    $this->travel(2)->seconds();

    $spot->addMedia(UploadedFile::fake()->image('photo.jpg', 200, 200))
        ->toMediaCollection('attachments');

    $this->travel(2)->seconds();

    // A later delta, asked from that cursor, must carry the spot again.
    $delta = $this->getJson(deltaUrl($cursor->toIso8601String()), orgHeader($this->organization))
        ->assertOk()
        ->json('sto_spots');

    $rows = collect([...($delta['created'] ?? []), ...($delta['updated'] ?? [])]);
    $row = $rows->firstWhere('uuid', $spot->uuid);

    expect($row)->not->toBeNull('the spot never came back down, so its photo never reaches the device');
    expect($row['cover_photo_url'])->toBeString();
});

test('removing a spot photo marks the spot as changed too', function (): void {
    // Otherwise a device goes on showing a picture of something that is gone.
    $spot = Spot::factory()->for($this->organization)->create([
        'user_id' => $this->explorer->id,
        'status' => SpotStatus::Published,
    ]);

    $spot->addMedia(UploadedFile::fake()->image('photo.jpg', 200, 200))
        ->toMediaCollection('attachments');

    $before = $spot->fresh()->updated_at;
    $this->travel(2)->seconds();

    $spot->getMedia('attachments')->first()->delete();

    expect($spot->fresh()->updated_at->gt($before))->toBeTrue();
});

// ---------------------------------------------------------------------------
// Push — create + idempotency
// ---------------------------------------------------------------------------

test('pushing a spot with a client uuid creates it, and replaying the same push leaves exactly one row', function (): void {
    actingAsSyncer($this->explorer);

    $uuid = (string) Str::uuid();
    $payload = [
        'sto_spots' => [
            'created' => [[
                'uuid' => $uuid,
                'title' => 'Pushed Cove',
                'latitude' => 6.1,
                'longitude' => 125.1,
                'status' => SpotStatus::Published->value,
            ]],
        ],
    ];

    $first = $this->postJson('/api/v1/stourify/sync/push', $payload, orgHeader($this->organization))->assertOk();
    expect($first->json('data.results.0.status'))->toBe('ok');
    expect($first->json('data.results.0.record.uuid'))->toBe($uuid);

    $this->postJson('/api/v1/stourify/sync/push', $payload, orgHeader($this->organization))->assertOk();

    expect(Spot::withTrashed()->where('uuid', $uuid)->count())->toBe(1);
    $this->assertDatabaseHas('sto_spots', ['uuid' => $uuid, 'user_id' => $this->explorer->id, 'title' => 'Pushed Cove']);
});

// ---------------------------------------------------------------------------
// Push — validation
// ---------------------------------------------------------------------------

test('an invalid row in a push batch is rejected per-op, and the other valid op in the same batch still succeeds', function (): void {
    actingAsSyncer($this->explorer);

    $validUuid = (string) Str::uuid();

    $response = $this->postJson('/api/v1/stourify/sync/push', [
        'sto_spots' => [
            'created' => [
                ['uuid' => (string) Str::uuid()], // missing title/lat/lng
                ['uuid' => $validUuid, 'title' => 'Fine Spot', 'latitude' => 6.1, 'longitude' => 125.1],
            ],
        ],
    ], orgHeader($this->organization))->assertOk();

    $results = collect($response->json('data.results'));

    expect($results->firstWhere('uuid', $validUuid)['status'])->toBe('ok');

    $rejected = $results->first(fn ($r) => $r['status'] === 'rejected');
    expect($rejected)->not->toBeNull()
        ->and($rejected['reason'])->toBe('validation')
        ->and($rejected['errors'])->toHaveKey('title');

    $this->assertDatabaseHas('sto_spots', ['uuid' => $validUuid]);
});

// ---------------------------------------------------------------------------
// Push — authorization / ownership
// ---------------------------------------------------------------------------

test('user_id is forced to the caller on push; a row naming another users id does not write to that user', function (): void {
    actingAsSyncer($this->explorer);

    $uuid = (string) Str::uuid();

    $this->postJson('/api/v1/stourify/sync/push', [
        'sto_spots' => [
            'created' => [[
                'uuid' => $uuid,
                'title' => 'Not Really Theirs',
                'latitude' => 6.1,
                'longitude' => 125.1,
                'user_id' => $this->other->id,
            ]],
        ],
    ], orgHeader($this->organization))->assertOk();

    $this->assertDatabaseHas('sto_spots', ['uuid' => $uuid, 'user_id' => $this->explorer->id]);
    $this->assertDatabaseMissing('sto_spots', ['uuid' => $uuid, 'user_id' => $this->other->id]);
});

test('pushing without the create permission is rejected per-op, not a 403 for the whole batch', function (): void {
    $unprivileged = $this->createUserWithPermissions($this->organization, []);
    actingAsSyncer($unprivileged);

    $uuid = (string) Str::uuid();

    $response = $this->postJson('/api/v1/stourify/sync/push', [
        'sto_spots' => [
            'created' => [[
                'uuid' => $uuid,
                'title' => 'Forbidden Spot',
                'latitude' => 6.1,
                'longitude' => 125.1,
            ]],
        ],
    ], orgHeader($this->organization));

    $response->assertOk();
    expect($response->json('data.results.0.status'))->toBe('rejected');
    expect($response->json('data.results.0.reason'))->toBe('forbidden');

    $this->assertDatabaseMissing('sto_spots', ['uuid' => $uuid]);
});

// ---------------------------------------------------------------------------
// Push — update by uuid
// ---------------------------------------------------------------------------

test('pushing an update for an existing uuid mutates it', function (): void {
    actingAsSyncer($this->explorer);

    $spot = Spot::factory()->for($this->organization)->create([
        'user_id' => $this->explorer->id,
        'status' => SpotStatus::Published,
        'title' => 'Original Title',
    ]);

    $response = $this->postJson('/api/v1/stourify/sync/push', [
        'sto_spots' => [
            'updated' => [[
                'uuid' => $spot->uuid,
                'title' => 'Updated Via Push',
            ]],
        ],
    ], orgHeader($this->organization))->assertOk();

    expect($response->json('data.results.0.status'))->toBe('ok')
        ->and($response->json('data.results.0.record.title'))->toBe('Updated Via Push');

    $this->assertDatabaseHas('sto_spots', ['uuid' => $spot->uuid, 'title' => 'Updated Via Push']);
});

// ---------------------------------------------------------------------------
// Push — delete
// ---------------------------------------------------------------------------

test('pushing a uuid in deleted removes it, and a uuid already gone is a no-op', function (): void {
    actingAsSyncer($this->explorer);

    $spot = Spot::factory()->for($this->organization)->create(['user_id' => $this->explorer->id, 'status' => SpotStatus::Published]);
    $wish = WishlistItem::factory()->for($this->organization)->create(['user_id' => $this->explorer->id, 'spot_id' => $spot->id]);

    $response = $this->postJson('/api/v1/stourify/sync/push', [
        'sto_wishlist_items' => ['deleted' => [$wish->uuid]],
    ], orgHeader($this->organization))->assertOk();

    expect($response->json('data.results.0.status'))->toBe('ok');
    $this->assertDatabaseMissing('sto_wishlist_items', ['uuid' => $wish->uuid]);

    // Already gone — idempotent, not an error.
    $second = $this->postJson('/api/v1/stourify/sync/push', [
        'sto_wishlist_items' => ['deleted' => [$wish->uuid]],
    ], orgHeader($this->organization))->assertOk();

    expect($second->json('data.results.0.status'))->toBe('ok');
});

// ---------------------------------------------------------------------------
// Cross-device delete
// ---------------------------------------------------------------------------

test('a delete on one device reaches a later delta pulled with an earlier cursor from another device', function (): void {
    actingAsSyncer($this->explorer);

    $spot = Spot::factory()->for($this->organization)->create(['user_id' => $this->explorer->id, 'status' => SpotStatus::Published]);

    // Device B's cursor, captured before device A deletes.
    $deviceBCursor = now()->toIso8601String();

    $this->travelTo(now()->addMinute());
    $this->postJson('/api/v1/stourify/sync/push', [
        'sto_spots' => ['deleted' => [$spot->uuid]],
    ], orgHeader($this->organization))->assertOk();
    $this->travelBack();

    $response = $this->getJson(deltaUrl($deviceBCursor), orgHeader($this->organization))->assertOk();

    expect($response->json('sto_spots.deleted'))->toContain($spot->uuid);
});

// ---------------------------------------------------------------------------
// Route registration
// ---------------------------------------------------------------------------

test('every sync endpoint rejects an unauthenticated caller', function (string $method, string $uri): void {
    $this->json($method, $uri, [], orgHeader($this->organization))->assertUnauthorized();
})->with([
    ['get', '/api/v1/stourify/sync/delta'],
    ['post', '/api/v1/stourify/sync/push'],
]);

test('cities cannot be pushed', function (): void {
    actingAsSyncer($this->explorer);

    $this->postJson('/api/v1/stourify/sync/push', [
        'sto_cities' => ['created' => [['uuid' => (string) Str::uuid(), 'name' => 'Nope']]],
    ], orgHeader($this->organization))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['sto_cities']);
});
