<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Stourify\Enums\SpotStatus;
use Modules\Stourify\Models\ExplorerProfile;
use Modules\Stourify\Models\Spot;
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
 * The fourth path, the offline sync delta, is deliberately NOT covered here: it
 * cannot be closed until the device's WatermelonDB schema accepts a spot with
 * no coordinates, which is STOURIFY-187.
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
