<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Modules\Stourify\Enums\SpotStatus;
use Modules\Stourify\Models\ExplorerProfile;
use Modules\Stourify\Models\Post;
use Modules\Stourify\Models\Spot;
use Modules\Stourify\Models\WishlistItem;
use Tests\Traits\InteractsWithTestSetup;

uses(RefreshDatabase::class, InteractsWithTestSetup::class);

/**
 * `shows_location_on_spots` is a curtain rail that had no curtain on it: the
 * column was accepted by the API, cast on the model, returned to its owner and
 * copied to every device -- and read by nothing, so a spot's exact coordinates
 * went to every caller regardless (STOURIFY-185).
 *
 * A spot's position escapes down more paths than the obvious one, and the
 * non-obvious path is the reason this file exists rather than a single
 * assertion on the resource:
 *
 *   1. `SpotResource` -- `latitude` / `longitude` outright.
 *   2. `/spots/nearby` -- `distance_km` on each row.
 *   3. `/spots/nearby` -- THE QUERY ITSELF. Membership of a radius result is a
 *      position. Withhold the two numbers but still answer "is this spot within
 *      2 km of here?" and the same fact falls out of three requests instead of
 *      one.
 *
 *   4. The offline sync delta -- covered at the bottom of this file. It turned
 *      out never to have been open: the delta only ever sends a device its own
 *      owner's spots, so a hidden spot never reaches anybody else's phone at
 *      all (STOURIFY-187). Those tests are a tripwire on that scope rather than
 *      a fix, and the block above them says why.
 */
const LOCATION_EXPLORER_PERMISSIONS = [
    'stourify.spots.view',
    'stourify.spots.create',
    'stourify.spots.update',
    'stourify.spots.delete',
];

beforeEach(function (): void {
    $this->organization = $this->setUpTestOrganization();
    $this->seedPermissions([...LOCATION_EXPLORER_PERMISSIONS, 'stourify.spots.manage']);

    // The contributor whose spots carry the hidden location.
    $this->hider = $this->createUserWithPermissions($this->organization, LOCATION_EXPLORER_PERMISSIONS);
    // Somebody else entirely -- the viewer the setting exists to defend against.
    $this->viewer = $this->createUserWithPermissions($this->organization, LOCATION_EXPLORER_PERMISSIONS);
});

function actingAsViewer(User $user): void
{
    Sanctum::actingAs($user);
}

/**
 * A published spot at a known position, whose contributor has decided whether
 * its location is visible.
 */
function spotWithLocationVisibility(bool $shown, User $contributor, object $organization, float $lat = 6.1164, float $lng = 125.1716): Spot
{
    ExplorerProfile::factory()->for($organization)->create([
        'user_id' => $contributor->id,
        'shows_location_on_spots' => $shown,
    ]);

    return Spot::factory()->for($organization)->create([
        'user_id' => $contributor->id,
        'status' => SpotStatus::Published,
        'latitude' => $lat,
        'longitude' => $lng,
    ]);
}

// ---------------------------------------------------------------------------
// Path 1 -- the resource
// ---------------------------------------------------------------------------

test('a spot detail withholds coordinates when its contributor hid them', function (): void {
    $spot = spotWithLocationVisibility(false, $this->hider, $this->organization);

    actingAsViewer($this->viewer);

    $data = $this->getJson("/api/v1/spots/{$spot->uuid}", orgHeader($this->organization))
        ->assertOk()
        ->json('data');

    expect($data)->not->toHaveKey('latitude')
        ->and($data)->not->toHaveKey('longitude');

    // The spot itself is still perfectly visible -- this hides a position, not a place.
    expect($data['uuid'])->toBe($spot->uuid);
});

test('a spot detail carries coordinates when its contributor left them shown', function (): void {
    $spot = spotWithLocationVisibility(true, $this->hider, $this->organization);

    actingAsViewer($this->viewer);

    $this->getJson("/api/v1/spots/{$spot->uuid}", orgHeader($this->organization))
        ->assertOk()
        ->assertJsonPath('data.latitude', fn ($v): bool => $v !== null)
        ->assertJsonPath('data.longitude', fn ($v): bool => $v !== null);
});

test('a contributor still sees the coordinates of their own hidden spot', function (): void {
    $spot = spotWithLocationVisibility(false, $this->hider, $this->organization);

    actingAsViewer($this->hider);

    $this->getJson("/api/v1/spots/{$spot->uuid}", orgHeader($this->organization))
        ->assertOk()
        ->assertJsonPath('data.latitude', fn ($v): bool => $v !== null);
});

test('a moderator still sees the coordinates of a hidden spot', function (): void {
    // A report about a spot is frequently a report about where it is, so a
    // moderation queue that cannot see the location cannot act on it.
    $moderator = $this->createUserWithPermissions(
        $this->organization,
        [...LOCATION_EXPLORER_PERMISSIONS, 'stourify.spots.manage'],
    );

    $spot = spotWithLocationVisibility(false, $this->hider, $this->organization);

    actingAsViewer($moderator);

    $this->getJson("/api/v1/spots/{$spot->uuid}", orgHeader($this->organization))
        ->assertOk()
        ->assertJsonPath('data.latitude', fn ($v): bool => $v !== null);
});

test('the spot list withholds coordinates too, not only the detail', function (): void {
    spotWithLocationVisibility(false, $this->hider, $this->organization);

    actingAsViewer($this->viewer);

    $rows = $this->getJson('/api/v1/spots', orgHeader($this->organization))
        ->assertOk()
        ->json('data');

    expect($rows)->not->toBeEmpty();

    foreach ($rows as $row) {
        expect($row)->not->toHaveKey('latitude')
            ->and($row)->not->toHaveKey('longitude');
    }
});

// ---------------------------------------------------------------------------
// Path 2 and 3 -- nearby, both its payload and its membership
// ---------------------------------------------------------------------------

test('a hidden spot is absent from a nearby result entirely, not merely stripped of its distance', function (): void {
    // This is the assertion the whole card turns on. Leaving the row in place
    // without a `distance_km` still answers "is this spot within 2 km of here?",
    // and three such answers trilaterate the position the setting was meant to
    // withhold.
    $hidden = spotWithLocationVisibility(false, $this->hider, $this->organization);

    actingAsViewer($this->viewer);

    $rows = $this->getJson('/api/v1/spots/nearby?lat=6.1164&lng=125.1716&radius=10', orgHeader($this->organization))
        ->assertOk()
        ->json('data');

    expect(collect($rows)->pluck('uuid'))->not->toContain($hidden->uuid);
});

test('a nearby result still contains spots whose contributors left location shown', function (): void {
    $shown = spotWithLocationVisibility(true, $this->hider, $this->organization);

    actingAsViewer($this->viewer);

    $rows = $this->getJson('/api/v1/spots/nearby?lat=6.1164&lng=125.1716&radius=10', orgHeader($this->organization))
        ->assertOk()
        ->json('data');

    expect(collect($rows)->pluck('uuid'))->toContain($shown->uuid);
});

test('a contributor still finds their own hidden spot nearby, with its distance', function (): void {
    $hidden = spotWithLocationVisibility(false, $this->hider, $this->organization);

    actingAsViewer($this->hider);

    $rows = $this->getJson('/api/v1/spots/nearby?lat=6.1164&lng=125.1716&radius=10', orgHeader($this->organization))
        ->assertOk()
        ->json('data');

    $row = collect($rows)->firstWhere('uuid', $hidden->uuid);

    expect($row)->not->toBeNull()
        ->and($row)->toHaveKey('distance_km');
});

// ---------------------------------------------------------------------------
// The cache, which decides whether any of the above survives a second request
// ---------------------------------------------------------------------------

test('one viewer\'s nearby result is never served to another', function (): void {
    // `spots:nearby` keys on the caller precisely because a block filter is
    // per-viewer, and this setting has the same shape: the owner sees a row the
    // stranger must not. A shared entry would hand the owner's answer -- hidden
    // spot included -- straight to the next caller.
    $hidden = spotWithLocationVisibility(false, $this->hider, $this->organization);

    // The owner asks first, warming a cache entry that legitimately contains it.
    actingAsViewer($this->hider);
    $ownerRows = $this->getJson('/api/v1/spots/nearby?lat=6.1164&lng=125.1716&radius=10', orgHeader($this->organization))
        ->assertOk()
        ->json('data');
    expect(collect($ownerRows)->pluck('uuid'))->toContain($hidden->uuid);

    // The stranger asks the identical question and must not receive it.
    actingAsViewer($this->viewer);
    $viewerRows = $this->getJson('/api/v1/spots/nearby?lat=6.1164&lng=125.1716&radius=10', orgHeader($this->organization))
        ->assertOk()
        ->json('data');
    expect(collect($viewerRows)->pluck('uuid'))->not->toContain($hidden->uuid);
});

// ---------------------------------------------------------------------------
// The default, which is what makes this change invisible until the toggle ships
// ---------------------------------------------------------------------------

test('a contributor with no explorer profile at all still shows coordinates', function (): void {
    // The flag defaults to true and most rows have no profile yet. If absence
    // read as "hidden", this change would silently strip coordinates from most
    // of the catalogue the day it merged.
    $spot = Spot::factory()->for($this->organization)->create([
        'user_id' => $this->hider->id,
        'status' => SpotStatus::Published,
        'latitude' => 6.1164,
        'longitude' => 125.1716,
    ]);

    actingAsViewer($this->viewer);

    $this->getJson("/api/v1/spots/{$spot->uuid}", orgHeader($this->organization))
        ->assertOk()
        ->assertJsonPath('data.latitude', fn ($v): bool => $v !== null);
});

// ---------------------------------------------------------------------------
// Path 4 -- the offline sync delta
// ---------------------------------------------------------------------------

/*
 * This section is a tripwire, not a description, and the difference is the
 * point of it.
 *
 * The delta was written up as the fourth leak path on the grandparent card
 * STOURIFY-75: `SyncSerializer::COLUMNS['sto_spots']` lists `latitude` and
 * `longitude` with no reference to `shows_location_on_spots` anywhere near it.
 * Read that file on its own and the conclusion is unavoidable.
 *
 * It is wrong, and what makes it wrong is one line in a different file.
 * `SyncRegistry::scope()` restricts every delta query for `sto_spots` to
 * `user_id = the caller`, so the only spots the server ever sends a device are
 * that device owner's own spots -- and the owner is exactly the person who
 * keeps seeing their coordinates by design. A hidden spot does not reach a
 * stranger's phone stripped of its position; it does not reach it at all.
 *
 * So these tests pass today for the strongest reason available: the row is not
 * there. They exist because that guarantee currently rests on a `where()`
 * clause in a file about synchronisation, which reads as plumbing rather than
 * as a privacy control. Shipping the spots of people you follow, so they can be
 * read offline, is an ordinary thing an offline-first travel app eventually
 * wants -- and the day somebody widens that scope, these go red in the same
 * commit.
 *
 * If that ever happens, closing it properly is two changes and they must land
 * in this order: the device's WatermelonDB schema has to accept a spot with no
 * coordinates FIRST, and only then may the serializer stop sending them. The
 * reverse order writes a `null` into a non-optional client column, and
 * WatermelonDB does not refuse that -- it silently stores `0`, which draws a
 * pin at (0, 0) in the Atlantic (STOURIFY-187).
 */

function locationDeltaUrl(?string $since = null): string
{
    return $since === null
        ? '/api/v1/stourify/sync/delta'
        : '/api/v1/stourify/sync/delta?since='.urlencode($since);
}

test('the sync delta never hands another explorer a hidden spot, so its coordinates never reach that device', function (): void {
    $hidden = spotWithLocationVisibility(false, $this->hider, $this->organization);

    actingAsViewer($this->viewer);

    $payload = $this->getJson(locationDeltaUrl(), orgHeader($this->organization))
        ->assertOk()
        ->json('sto_spots');

    $rows = collect([...$payload['created'], ...$payload['updated']]);

    expect($rows->pluck('uuid'))->not->toContain($hidden->uuid);

    // Belt as well as braces: assert on the coordinates themselves, not only on
    // the identity of the row carrying them. A future change that reshapes the
    // delta's rows but keeps the position in them would slip past a uuid check.
    foreach ($rows as $row) {
        expect($row['latitude'] ?? null)->not->toEqual((string) $hidden->latitude);
        expect($row['longitude'] ?? null)->not->toEqual((string) $hidden->longitude);
    }
});

test('an incremental delta does not deliver a hidden spot to another explorer either', function (): void {
    // The card that asked for this worried about the case where a device is
    // already holding a spot and then pulls an update for it. That is the
    // `since` cursor, and it runs through the same scope as the first full
    // pull -- but it is a separate query, so it gets its own assertion rather
    // than an argument that it must behave the same.
    $hidden = spotWithLocationVisibility(false, $this->hider, $this->organization);

    $cursor = now()->subMinute()->toIso8601String();

    $hidden->forceFill(['title' => 'Renamed after the cursor'])->save();

    actingAsViewer($this->viewer);

    $payload = $this->getJson(locationDeltaUrl($cursor), orgHeader($this->organization))
        ->assertOk()
        ->json('sto_spots');

    $uuids = collect([...$payload['created'], ...$payload['updated']])->pluck('uuid');

    expect($uuids)->not->toContain($hidden->uuid);
});

test('the contributor of a hidden spot still receives its coordinates on their own delta', function (): void {
    // The other half, and the reason no change to the serializer is wanted: the
    // only device a spot's position is ever synced to belongs to the person who
    // put it there. Withholding it here would break the author's own offline
    // copy of their own map.
    $hidden = spotWithLocationVisibility(false, $this->hider, $this->organization);

    actingAsViewer($this->hider);

    $rows = collect($this->getJson(locationDeltaUrl(), orgHeader($this->organization))
        ->assertOk()
        ->json('sto_spots.created'));

    $row = $rows->firstWhere('uuid', $hidden->uuid);

    expect($row)->not->toBeNull()
        ->and($row['latitude'])->not->toBeNull()
        ->and($row['longitude'])->not->toBeNull();
});

test('every spot on a delta belongs to the caller, which is what makes the guarantee above hold', function (): void {
    // The tripwire proper. The three tests above are about one hidden spot;
    // this one is about the rule they all rest on, stated once so that widening
    // the scope fails here first and unambiguously.
    spotWithLocationVisibility(false, $this->hider, $this->organization);

    $mine = Spot::factory()->for($this->organization)->create([
        'user_id' => $this->viewer->id,
        'status' => SpotStatus::Published,
        'latitude' => 6.1164,
        'longitude' => 125.1716,
    ]);

    actingAsViewer($this->viewer);

    $payload = $this->getJson(locationDeltaUrl(), orgHeader($this->organization))
        ->assertOk()
        ->json('sto_spots');

    $rows = collect([...$payload['created'], ...$payload['updated']]);

    expect($rows->pluck('uuid'))->toContain($mine->uuid);

    foreach ($rows as $row) {
        expect($row['user_id'])->toBe($this->viewer->id);
    }
});

// ---------------------------------------------------------------------------
// The cache the other way round -- when the setting CHANGES (STOURIFY-244)
// ---------------------------------------------------------------------------

/*
 * The tests above all ask the same question: given a flag, does the API respect
 * it? The answer was yes, and it was still yes an hour after somebody changed
 * their mind -- which is the bug.
 *
 * A shop puts its opening hours on a board outside. Change the hours inside and
 * the board keeps telling the street the old ones until somebody walks out and
 * repaints it. Every list below is that board: the result is cached, so the
 * exclusion `withLocationVisibleTo()` performs, and the omission `SpotResource`
 * performs, both happen only on a cache miss.
 *
 * Two separate things go stale, and it is worth knowing they are two, because
 * fixing only the first would leave three quarters of this section red:
 *
 *   1. THE QUERY. `nearby` wraps its whole query in `Spot::getCachedList()`,
 *      and the scope that drops a hidden contributor's spots runs inside that
 *      closure.
 *   2. THE FLAG ITSELF. `Spot` eager-loads `contributorProfile` (STOURIFY-185
 *      put it on the model to kill an N+1), so a cached spot carries a frozen
 *      copy of its contributor's profile row. `SpotResource` runs OUTSIDE the
 *      cache and still reads that copy -- so `spots:index`, `posts:index` and
 *      `wishlist:index` hand out coordinates from a flag nobody is looking at
 *      any more.
 *
 * Every test here therefore WARMS the cache first. That is not set-up noise; it
 * is the entire experiment. A version of these tests that asked once would pass
 * against the broken code, which is the specific failure this card had to rule
 * out.
 */

function locationProfileOf(User $contributor): ExplorerProfile
{
    return ExplorerProfile::query()->where('user_id', $contributor->id)->firstOrFail();
}

test('turning the flag off drops a contributor spot out of a stranger already-warmed nearby result on the very next request', function (): void {
    // The card's own reproduction, measured on a real emulator with two
    // accounts before it was written down.
    $spot = spotWithLocationVisibility(true, $this->hider, $this->organization);

    actingAsViewer($this->viewer);

    $warm = $this->getJson('/api/v1/spots/nearby?lat=6.1164&lng=125.1716&radius=10', orgHeader($this->organization))
        ->assertOk()
        ->json('data');
    expect(collect($warm)->pluck('uuid'))->toContain($spot->uuid);

    locationProfileOf($this->hider)->forceFill(['shows_location_on_spots' => false])->save();

    $after = $this->getJson('/api/v1/spots/nearby?lat=6.1164&lng=125.1716&radius=10', orgHeader($this->organization))
        ->assertOk()
        ->json('data');

    expect(collect($after)->pluck('uuid'))->not->toContain($spot->uuid);
});

test('turning the flag off strips coordinates from an already-warmed spot listing on the very next request', function (): void {
    $spot = spotWithLocationVisibility(true, $this->hider, $this->organization);

    actingAsViewer($this->viewer);

    $warm = collect($this->getJson('/api/v1/spots', orgHeader($this->organization))
        ->assertOk()
        ->json('data'))->firstWhere('uuid', $spot->uuid);
    expect($warm)->toHaveKey('latitude');

    locationProfileOf($this->hider)->forceFill(['shows_location_on_spots' => false])->save();

    $after = collect($this->getJson('/api/v1/spots', orgHeader($this->organization))
        ->assertOk()
        ->json('data'))->firstWhere('uuid', $spot->uuid);

    expect($after)->not->toBeNull()
        ->and($after)->not->toHaveKey('latitude')
        ->and($after)->not->toHaveKey('longitude');
});

test('turning the flag off strips coordinates from the spot nested inside an already-warmed post listing', function (): void {
    // `posts:index` is a different cache family with its own tag, so clearing
    // the spot family alone would leave this one answering with the old flag.
    $spot = spotWithLocationVisibility(true, $this->hider, $this->organization);
    Post::factory()->for($this->organization)->create([
        'user_id' => $this->hider->id,
        'spot_id' => $spot->id,
    ]);

    $this->seedPermissions(['stourify.posts.view']);
    $this->viewer->givePermissionTo('stourify.posts.view');

    actingAsViewer($this->viewer);

    $warm = $this->getJson('/api/v1/posts', orgHeader($this->organization))
        ->assertOk()
        ->json('data');
    expect($warm[0]['spot'])->toHaveKey('latitude');

    locationProfileOf($this->hider)->forceFill(['shows_location_on_spots' => false])->save();

    $after = $this->getJson('/api/v1/posts', orgHeader($this->organization))
        ->assertOk()
        ->json('data');

    expect($after[0]['spot'])->not->toHaveKey('latitude')
        ->and($after[0]['spot'])->not->toHaveKey('longitude');
});

test('turning the flag off strips coordinates from the spot nested inside an already-warmed wishlist', function (): void {
    // Somebody else has saved this spot to their own wishlist. Their saved copy
    // is cached under a third tag family, and it renders the same nested spot.
    $spot = spotWithLocationVisibility(true, $this->hider, $this->organization);

    $this->seedPermissions(['stourify.wishlist.manage']);
    $this->viewer->givePermissionTo('stourify.wishlist.manage');

    WishlistItem::factory()->for($this->organization)->create([
        'user_id' => $this->viewer->id,
        'spot_id' => $spot->id,
    ]);

    actingAsViewer($this->viewer);

    $warm = $this->getJson('/api/v1/wishlist', orgHeader($this->organization))
        ->assertOk()
        ->json('data');
    expect($warm[0]['spot'])->toHaveKey('latitude');

    locationProfileOf($this->hider)->forceFill(['shows_location_on_spots' => false])->save();

    $after = $this->getJson('/api/v1/wishlist', orgHeader($this->organization))
        ->assertOk()
        ->json('data');

    expect($after[0]['spot'])->not->toHaveKey('latitude')
        ->and($after[0]['spot'])->not->toHaveKey('longitude');
});

test('turning the flag back on restores the spot just as promptly', function (): void {
    // This is a correctness fix, not a one-way tripwire. Somebody who hides
    // their location, thinks better of it and turns it back on must not be told
    // to wait an hour either.
    $spot = spotWithLocationVisibility(false, $this->hider, $this->organization);

    actingAsViewer($this->viewer);

    $warm = $this->getJson('/api/v1/spots/nearby?lat=6.1164&lng=125.1716&radius=10', orgHeader($this->organization))
        ->assertOk()
        ->json('data');
    expect(collect($warm)->pluck('uuid'))->not->toContain($spot->uuid);

    locationProfileOf($this->hider)->forceFill(['shows_location_on_spots' => true])->save();

    $after = $this->getJson('/api/v1/spots/nearby?lat=6.1164&lng=125.1716&radius=10', orgHeader($this->organization))
        ->assertOk()
        ->json('data');

    expect(collect($after)->pluck('uuid'))->toContain($spot->uuid);
});

test('an ordinary profile edit clears nothing', function (): void {
    // The guard on the decision to check the flag rather than clear on every
    // save. Editing a bio must not throw away every viewer's cached map, and
    // somebody tidying this code later must find that out from a red test
    // rather than from a graph.
    //
    // Proving a cache was NOT emptied needs something to look for afterwards,
    // so this leaves a marker of its own in the same tag family the real
    // entries live in. The marker survives a bio change and does not survive a
    // change to the flag -- both halves asserted here, because "nothing was
    // cleared" is only meaningful next to a case where something was.
    spotWithLocationVisibility(true, $this->hider, $this->organization);

    Cache::tags(['Spot:list'])->put('stourify-244-probe', 'warm', 600);

    locationProfileOf($this->hider)->forceFill(['bio' => 'Still wandering.'])->save();

    expect(Cache::tags(['Spot:list'])->get('stourify-244-probe'))->toBe('warm');

    locationProfileOf($this->hider)->forceFill(['shows_location_on_spots' => false])->save();

    expect(Cache::tags(['Spot:list'])->get('stourify-244-probe'))->toBeNull();
});

test('a profile created already holding the flag off clears the caches too', function (): void {
    // Eloquent only works out what changed on an UPDATE, so `wasChanged()` is
    // false straight after an insert. A contributor who publishes spots first
    // and fills in their profile afterwards would otherwise slip through.
    $spot = Spot::factory()->for($this->organization)->create([
        'user_id' => $this->hider->id,
        'status' => SpotStatus::Published,
        'latitude' => 6.1164,
        'longitude' => 125.1716,
    ]);

    actingAsViewer($this->viewer);

    $warm = $this->getJson('/api/v1/spots/nearby?lat=6.1164&lng=125.1716&radius=10', orgHeader($this->organization))
        ->assertOk()
        ->json('data');
    expect(collect($warm)->pluck('uuid'))->toContain($spot->uuid);

    ExplorerProfile::factory()->for($this->organization)->create([
        'user_id' => $this->hider->id,
        'shows_location_on_spots' => false,
    ]);

    $after = $this->getJson('/api/v1/spots/nearby?lat=6.1164&lng=125.1716&radius=10', orgHeader($this->organization))
        ->assertOk()
        ->json('data');

    expect(collect($after)->pluck('uuid'))->not->toContain($spot->uuid);
});

test('deleting a profile clears the caches, because no profile means shown', function (): void {
    // The rule STOURIFY-185 set: a spot whose contributor has no profile row at
    // all shows its coordinates. So deleting a profile makes positions MORE
    // visible, and a cache still holding the hidden answer is wrong in the
    // opposite direction -- stale in a way that hides a real place from the map
    // rather than leaking one.
    $spot = spotWithLocationVisibility(false, $this->hider, $this->organization);

    actingAsViewer($this->viewer);

    $warm = $this->getJson('/api/v1/spots/nearby?lat=6.1164&lng=125.1716&radius=10', orgHeader($this->organization))
        ->assertOk()
        ->json('data');
    expect(collect($warm)->pluck('uuid'))->not->toContain($spot->uuid);

    locationProfileOf($this->hider)->delete();

    $after = $this->getJson('/api/v1/spots/nearby?lat=6.1164&lng=125.1716&radius=10', orgHeader($this->organization))
        ->assertOk()
        ->json('data');

    expect(collect($after)->pluck('uuid'))->toContain($spot->uuid);
});
