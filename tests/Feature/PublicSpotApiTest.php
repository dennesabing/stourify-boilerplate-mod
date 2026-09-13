<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Stourify\Enums\SpotStatus;
use Modules\Stourify\Models\ExplorerProfile;
use Modules\Stourify\Models\Spot;
use Tests\Traits\InteractsWithTestSetup;

uses(RefreshDatabase::class, InteractsWithTestSetup::class);

/**
 * The public spot endpoint: the one read in this module that answers a
 * stranger with no account (STOURIFY-301).
 *
 * A restaurant's menu in the window, not the kitchen door. It exists so a
 * link sent to WhatsApp opens something for somebody who has never installed
 * the app -- which means everything it returns can be read by anyone on the
 * internet, and everything it must NOT return is the reason this file is long.
 *
 * Three privacy rules, each with its own tests:
 *   1. Only a published spot from a non-private account has a public page.
 *      Everything else is a 404 -- never a 403, which would confirm it exists.
 *   2. A contributor who hid their location gets no coordinates and no street
 *      address on the public page.
 *   3. Nothing private leaves: no email, no user ids, no `can`, no status.
 */
/**
 * A spot in `$organization`, contributed by a fresh user whose explorer
 * profile says what the test needs it to say.
 *
 * @param  array<string, mixed>  $profile
 * @param  array<string, mixed>  $spot
 */
function sharedSpot(Organization $organization, array $profile = [], array $spot = []): Spot
{
    $contributor = User::factory()->create();

    ExplorerProfile::factory()->for($organization)->create([
        'user_id' => $contributor->id,
        ...$profile,
    ]);

    return Spot::factory()->for($organization)->create([
        'user_id' => $contributor->id,
        'status' => SpotStatus::Published,
        'title' => 'Blue Cove',
        'description' => 'A quiet cove past the lighthouse.',
        'address' => '12 Coastal Road',
        'latitude' => 6.1164,
        'longitude' => 125.1716,
        'categories' => ['Nature'],
        ...$spot,
    ]);
}

function publicSpotUrl(string $uuid): string
{
    return "/api/v1/public/spots/{$uuid}";
}

beforeEach(function (): void {
    config(['stourify.share.base_url' => 'https://share.test']);

    // Stourify's one public organization, which is the only tenant whose
    // spots can have a public page.
    $this->organization = $this->setUpTestOrganization([
        'slug' => config('stourify.public_organization.slug'),
    ]);
});

// ---------------------------------------------------------------------------
// Rule 1 -- who has a public page at all
// ---------------------------------------------------------------------------

test('a published spot from a public account is readable with no login', function (): void {
    $spot = sharedSpot($this->organization);

    $this->getJson(publicSpotUrl($spot->uuid))
        ->assertOk()
        ->assertJsonPath('data.uuid', $spot->uuid)
        ->assertJsonPath('data.title', 'Blue Cove')
        ->assertJsonPath('data.description', 'A quiet cove past the lighthouse.')
        ->assertJsonPath('data.categories', ['Nature'])
        ->assertJsonPath('data.address', '12 Coastal Road')
        ->assertJsonPath('data.latitude', 6.1164)
        ->assertJsonPath('data.longitude', 125.1716)
        ->assertJsonPath('data.share_url', "https://share.test/s/{$spot->uuid}");
});

test('a contributor with no explorer profile at all still has a public page', function (): void {
    // Most spots predate any profile row. The location rule reads "no profile"
    // as "shown" (STOURIFY-185); the privacy rule must read it as "public", or
    // most of the catalogue would have no page.
    $spot = Spot::factory()->for($this->organization)->create(['status' => SpotStatus::Published]);

    $this->getJson(publicSpotUrl($spot->uuid))->assertOk();
});

test('a spot that is not published has no public page', function (SpotStatus $status): void {
    $spot = sharedSpot($this->organization, spot: ['status' => $status]);

    $this->getJson(publicSpotUrl($spot->uuid))->assertNotFound();
})->with([
    'draft' => [SpotStatus::Draft],
    'under review' => [SpotStatus::UnderReview],
    'removed' => [SpotStatus::Removed],
]);

test('a deleted spot has no public page', function (): void {
    $spot = sharedSpot($this->organization);
    $spot->delete();

    $this->getJson(publicSpotUrl($spot->uuid))->assertNotFound();
});

test('a spot from a private account has no public page', function (): void {
    $spot = sharedSpot($this->organization, profile: ['is_private' => true]);

    $this->getJson(publicSpotUrl($spot->uuid))->assertNotFound();
});

test('a spot outside the public organization has no public page', function (): void {
    // Stourify's content all lives in one public organization. A spot in any
    // other tenant is somebody's private workspace, not a place on the map.
    $spot = sharedSpot(Organization::factory()->create());

    $this->getJson(publicSpotUrl($spot->uuid))->assertNotFound();
});

test('an unknown uuid is a 404 and so is something that is not a uuid', function (): void {
    $this->getJson(publicSpotUrl('7d3c4f1e-9a2b-4c5d-8e6f-0a1b2c3d4e5f'))->assertNotFound();
    $this->getJson(publicSpotUrl('not-a-uuid'))->assertNotFound();
});

test('going private takes the page down on the very next request, even with a warm cache', function (): void {
    // The page is cached. Somebody who makes their account private has a
    // reason to, and "it will come down within the hour" is not an answer.
    $spot = sharedSpot($this->organization);

    $this->getJson(publicSpotUrl($spot->uuid))->assertOk();

    ExplorerProfile::query()->where('user_id', $spot->user_id)->firstOrFail()
        ->forceFill(['is_private' => true])->save();

    $this->getJson(publicSpotUrl($spot->uuid))->assertNotFound();
});

test('unpublishing takes the page down on the very next request, even with a warm cache', function (): void {
    $spot = sharedSpot($this->organization);

    $this->getJson(publicSpotUrl($spot->uuid))->assertOk();

    $spot->forceFill(['status' => SpotStatus::UnderReview])->save();

    $this->getJson(publicSpotUrl($spot->uuid))->assertNotFound();
});

// ---------------------------------------------------------------------------
// Rule 2 -- a hidden location stays hidden
// ---------------------------------------------------------------------------

test('a contributor who hid their location has no coordinates and no street address on the public page', function (): void {
    $spot = sharedSpot($this->organization, profile: ['shows_location_on_spots' => false]);

    $data = $this->getJson(publicSpotUrl($spot->uuid))
        ->assertOk()
        ->json('data');

    expect($data)->not->toHaveKey('latitude')
        ->and($data)->not->toHaveKey('longitude')
        ->and($data)->not->toHaveKey('address')
        // The place is still shareable -- this hides a position, not a spot.
        ->and($data['title'])->toBe('Blue Cove');
});

test('hiding the location strips it from a warm public page on the very next request', function (): void {
    $spot = sharedSpot($this->organization);

    expect($this->getJson(publicSpotUrl($spot->uuid))->json('data'))->toHaveKey('latitude');

    ExplorerProfile::query()->where('user_id', $spot->user_id)->firstOrFail()
        ->forceFill(['shows_location_on_spots' => false])->save();

    $data = $this->getJson(publicSpotUrl($spot->uuid))->assertOk()->json('data');

    expect($data)->not->toHaveKey('latitude')
        ->and($data)->not->toHaveKey('address');
});

test('a signed-in caller gets exactly the stranger view, not their own privileges', function (): void {
    // The contributor may see their own hidden coordinates in the app. The
    // public page is the page a stranger sees, whoever happens to load it --
    // otherwise a contributor checking their share link would be told the
    // location is visible when it is not.
    $spot = sharedSpot($this->organization, profile: ['shows_location_on_spots' => false]);

    Sanctum::actingAs(User::query()->findOrFail($spot->user_id));

    expect($this->getJson(publicSpotUrl($spot->uuid))->assertOk()->json('data'))
        ->not->toHaveKey('latitude');
});

// ---------------------------------------------------------------------------
// Rule 3 -- nothing private leaves
// ---------------------------------------------------------------------------

test('the public page carries no private field', function (): void {
    $spot = sharedSpot($this->organization);
    $contributor = User::query()->findOrFail($spot->user_id);

    $response = $this->getJson(publicSpotUrl($spot->uuid))->assertOk();
    $data = $response->json('data');

    foreach (['id', 'user_id', 'organization_id', 'owner_user_id', 'contributor_uuid', 'status', 'can', 'slug'] as $key) {
        expect($data)->not->toHaveKey($key);
    }

    // Belt as well as braces: the contributor's email and uuid appear nowhere
    // in the body, under any key a future change might add.
    expect($response->getContent())
        ->not->toContain($contributor->email)
        ->not->toContain($contributor->uuid);
});

// ---------------------------------------------------------------------------
// Abuse
// ---------------------------------------------------------------------------

test('a caller is rate limited after sixty requests a minute', function (): void {
    $spot = sharedSpot($this->organization);

    for ($i = 0; $i < 60; $i++) {
        $this->getJson(publicSpotUrl($spot->uuid))->assertOk();
    }

    $this->getJson(publicSpotUrl($spot->uuid))->assertStatus(429);
});

// ---------------------------------------------------------------------------
// The app's half -- where the Share button gets its link
// ---------------------------------------------------------------------------

test('the in-app spot carries its public share link when it has a public page', function (): void {
    $viewer = $this->createUserWithPermissions($this->organization, ['stourify.spots.view']);
    $spot = sharedSpot($this->organization);

    Sanctum::actingAs($viewer);

    $this->getJson("/api/v1/spots/{$spot->uuid}", orgHeader($this->organization))
        ->assertOk()
        ->assertJsonPath('data.share_url', "https://share.test/s/{$spot->uuid}");
});

test('the in-app spot carries no share link when it has no public page', function (): void {
    // A private account's spot is visible to approved members inside the app,
    // but a Share button there would hand out a link that opens a 404.
    $viewer = $this->createUserWithPermissions($this->organization, ['stourify.spots.view']);
    $spot = sharedSpot($this->organization, profile: ['is_private' => true]);

    Sanctum::actingAs($viewer);

    $this->getJson("/api/v1/spots/{$spot->uuid}", orgHeader($this->organization))
        ->assertOk()
        ->assertJsonPath('data.share_url', null);
});
