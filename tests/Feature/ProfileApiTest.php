<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Stourify\Enums\SpotStatus;
use Modules\Stourify\Models\City;
use Modules\Stourify\Models\ExplorerProfile;
use Modules\Stourify\Models\Follow;
use Modules\Stourify\Models\Spot;
use Tests\Traits\InteractsWithTestSetup;

uses(RefreshDatabase::class, InteractsWithTestSetup::class);

beforeEach(function (): void {
    $this->organization = $this->setUpTestOrganization();
    // Profiles are ownership-gated, not permission-gated, so no module
    // permissions are needed to exercise them.
    $this->alice = $this->createUserWithPermissions($this->organization, []);
    $this->bob = $this->createUserWithPermissions($this->organization, []);
});

function actingAsProfileUser(User $user): void
{
    Sanctum::actingAs($user);
}

// ---------------------------------------------------------------------------
// The upsert
// ---------------------------------------------------------------------------

test('GET /profile returns null before a profile is created', function (): void {
    actingAsProfileUser($this->alice);

    $this->getJson('/api/v1/profile', orgHeader($this->organization))
        ->assertOk()
        ->assertJsonPath('data', null);
});

test('the first PATCH creates the profile and requires a username', function (): void {
    actingAsProfileUser($this->alice);

    // No username on first write is rejected.
    $this->patchJson('/api/v1/profile', [
        'bio' => 'Chasing sunsets.',
    ], orgHeader($this->organization))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['username']);

    $this->patchJson('/api/v1/profile', [
        'username' => 'alice_gensan',
        'bio' => 'Chasing sunsets.',
    ], orgHeader($this->organization))
        ->assertCreated()
        ->assertJsonPath('data.username', 'alice_gensan')
        ->assertJsonPath('data.bio', 'Chasing sunsets.');

    $this->assertDatabaseHas('sto_explorer_profiles', [
        'user_id' => $this->alice->id,
        'username' => 'alice_gensan',
    ]);
});

test('a later PATCH edits without restating the username', function (): void {
    ExplorerProfile::factory()->for($this->organization)->create([
        'user_id' => $this->alice->id, 'username' => 'alice_gensan', 'bio' => 'Old bio.',
    ]);

    actingAsProfileUser($this->alice);
    $this->patchJson('/api/v1/profile', [
        'bio' => 'New bio.',
    ], orgHeader($this->organization))
        ->assertOk()
        ->assertJsonPath('data.bio', 'New bio.')
        ->assertJsonPath('data.username', 'alice_gensan');
});

test('a username is normalized to lowercase', function (): void {
    actingAsProfileUser($this->alice);

    // Uppercase fails the lowercase rule rather than being silently coerced,
    // so the client learns the canonical form.
    $this->patchJson('/api/v1/profile', [
        'username' => 'AliceGenSan',
    ], orgHeader($this->organization))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['username']);
});

test('a username must be unique across the platform', function (): void {
    ExplorerProfile::factory()->for($this->organization)->create([
        'user_id' => $this->bob->id, 'username' => 'taken',
    ]);

    actingAsProfileUser($this->alice);
    $this->patchJson('/api/v1/profile', [
        'username' => 'taken',
    ], orgHeader($this->organization))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['username']);
});

test('re-saving an unchanged username is not a conflict with itself', function (): void {
    ExplorerProfile::factory()->for($this->organization)->create([
        'user_id' => $this->alice->id, 'username' => 'alice_gensan',
    ]);

    actingAsProfileUser($this->alice);
    $this->patchJson('/api/v1/profile', [
        'username' => 'alice_gensan',
        'bio' => 'Same handle, new bio.',
    ], orgHeader($this->organization))->assertOk();
});

test('a username rejects illegal characters', function (): void {
    actingAsProfileUser($this->alice);

    $this->patchJson('/api/v1/profile', [
        'username' => 'alice gensan!',
    ], orgHeader($this->organization))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['username']);
});

test('home city is set by uuid and echoed back', function (): void {
    $city = City::factory()->for($this->organization)->create(['name' => 'General Santos']);

    actingAsProfileUser($this->alice);
    $this->patchJson('/api/v1/profile', [
        'username' => 'alice_gensan',
        'home_city_uuid' => $city->uuid,
    ], orgHeader($this->organization))
        ->assertCreated()
        ->assertJsonPath('data.home_city.uuid', $city->uuid);

    $this->assertDatabaseHas('sto_explorer_profiles', [
        'user_id' => $this->alice->id,
        'home_city_id' => $city->id,
    ]);
});

// ---------------------------------------------------------------------------
// Reading other explorers
// ---------------------------------------------------------------------------

test('anyone can read another explorer\'s public header', function (): void {
    ExplorerProfile::factory()->for($this->organization)->create([
        'user_id' => $this->bob->id, 'username' => 'bob_explorer', 'bio' => 'Coffee and coastlines.',
    ]);

    actingAsProfileUser($this->alice);
    $this->getJson("/api/v1/profiles/{$this->bob->uuid}", orgHeader($this->organization))
        ->assertOk()
        ->assertJsonPath('data.username', 'bob_explorer')
        ->assertJsonPath('data.bio', 'Coffee and coastlines.');
});

test('the header carries the explorer\'s display name, not just their handle', function (): void {
    ExplorerProfile::factory()->for($this->organization)->create([
        'user_id' => $this->bob->id, 'username' => 'bob_explorer',
    ]);

    actingAsProfileUser($this->alice);
    $this->getJson("/api/v1/profiles/{$this->bob->uuid}", orgHeader($this->organization))
        ->assertOk()
        ->assertJsonPath('data.name', $this->bob->name)
        ->assertJsonPath('data.username', 'bob_explorer');
});

test('a private account still exposes its header', function (): void {
    ExplorerProfile::factory()->for($this->organization)->private()->create([
        'user_id' => $this->bob->id, 'username' => 'bob_private',
    ]);

    actingAsProfileUser($this->alice);
    $this->getJson("/api/v1/profiles/{$this->bob->uuid}", orgHeader($this->organization))
        ->assertOk()
        ->assertJsonPath('data.username', 'bob_private')
        ->assertJsonPath('data.is_private', true);
});

test('reading the profile of a user who has none is a 404', function (): void {
    actingAsProfileUser($this->alice);

    $this->getJson("/api/v1/profiles/{$this->bob->uuid}", orgHeader($this->organization))
        ->assertNotFound();
});

test('shows_location_on_spots is visible to the owner but not to others', function (): void {
    ExplorerProfile::factory()->for($this->organization)->create([
        'user_id' => $this->bob->id, 'username' => 'bob_explorer', 'shows_location_on_spots' => false,
    ]);

    actingAsProfileUser($this->bob);
    $this->getJson('/api/v1/profile', orgHeader($this->organization))
        ->assertOk()
        ->assertJsonPath('data.shows_location_on_spots', false);

    actingAsProfileUser($this->alice);
    $this->getJson("/api/v1/profiles/{$this->bob->uuid}", orgHeader($this->organization))
        ->assertOk()
        ->assertJsonMissingPath('data.shows_location_on_spots');
});

// ---------------------------------------------------------------------------
// Counts
// ---------------------------------------------------------------------------

test('the header counts reflect the graph', function (): void {
    $profile = ExplorerProfile::factory()->for($this->organization)->create([
        'user_id' => $this->bob->id, 'username' => 'bob_explorer',
    ]);

    // Two published spots by Bob (a draft must not count).
    Spot::factory()->for($this->organization)->count(2)->create([
        'user_id' => $this->bob->id, 'status' => SpotStatus::Published,
    ]);
    Spot::factory()->for($this->organization)->create([
        'user_id' => $this->bob->id, 'status' => SpotStatus::Draft,
    ]);

    // One accepted follower, one pending (which must not count).
    Follow::factory()->for($this->organization)->create([
        'follower_id' => $this->alice->id, 'followee_id' => $this->bob->id,
    ]);
    $carol = $this->createUserWithPermissions($this->organization, []);
    Follow::factory()->for($this->organization)->pending()->create([
        'follower_id' => $carol->id, 'followee_id' => $this->bob->id,
    ]);

    // Bob follows one person.
    Follow::factory()->for($this->organization)->create([
        'follower_id' => $this->bob->id, 'followee_id' => $this->alice->id,
    ]);

    actingAsProfileUser($this->alice);
    $this->getJson("/api/v1/profiles/{$this->bob->uuid}", orgHeader($this->organization))
        ->assertOk()
        ->assertJsonPath('data.counts.spots', 2)
        ->assertJsonPath('data.counts.followers', 1)
        ->assertJsonPath('data.counts.following', 1);
});

// ---------------------------------------------------------------------------
// The viewer block — the relationship between the caller and the subject
// ---------------------------------------------------------------------------

test('the viewer block reports no relationship when the caller does not follow', function (): void {
    ExplorerProfile::factory()->for($this->organization)->create([
        'user_id' => $this->bob->id, 'username' => 'bob_explorer',
    ]);

    actingAsProfileUser($this->alice);
    $this->getJson("/api/v1/profiles/{$this->bob->uuid}", orgHeader($this->organization))
        ->assertOk()
        ->assertJsonPath('data.viewer.is_self', false)
        ->assertJsonPath('data.viewer.is_following', false)
        ->assertJsonPath('data.viewer.follow_status', null)
        ->assertJsonPath('data.viewer.follow_uuid', null);
});

test('the viewer block carries the edge uuid an unfollow needs', function (): void {
    ExplorerProfile::factory()->for($this->organization)->create([
        'user_id' => $this->bob->id, 'username' => 'bob_explorer',
    ]);
    $edge = Follow::factory()->for($this->organization)->create([
        'follower_id' => $this->alice->id, 'followee_id' => $this->bob->id,
    ]);

    actingAsProfileUser($this->alice);
    $this->getJson("/api/v1/profiles/{$this->bob->uuid}", orgHeader($this->organization))
        ->assertOk()
        ->assertJsonPath('data.viewer.is_following', true)
        ->assertJsonPath('data.viewer.follow_status', 'active')
        ->assertJsonPath('data.viewer.follow_uuid', $edge->uuid);
});

test('a pending request is not following, but is still addressable', function (): void {
    ExplorerProfile::factory()->for($this->organization)->private()->create([
        'user_id' => $this->bob->id, 'username' => 'bob_private',
    ]);
    $edge = Follow::factory()->for($this->organization)->pending()->create([
        'follower_id' => $this->alice->id, 'followee_id' => $this->bob->id,
    ]);

    actingAsProfileUser($this->alice);
    $this->getJson("/api/v1/profiles/{$this->bob->uuid}", orgHeader($this->organization))
        ->assertOk()
        // "Requested", not "Following" — the client renders a third state, and
        // can still cancel the request because the uuid is here.
        ->assertJsonPath('data.viewer.is_following', false)
        ->assertJsonPath('data.viewer.follow_status', 'pending')
        ->assertJsonPath('data.viewer.follow_uuid', $edge->uuid);
});

test('the viewer block reads the caller\'s own edge, not the reverse one', function (): void {
    ExplorerProfile::factory()->for($this->organization)->create([
        'user_id' => $this->bob->id, 'username' => 'bob_explorer',
    ]);
    // Bob follows Alice. Alice does not follow Bob.
    Follow::factory()->for($this->organization)->create([
        'follower_id' => $this->bob->id, 'followee_id' => $this->alice->id,
    ]);

    actingAsProfileUser($this->alice);
    $this->getJson("/api/v1/profiles/{$this->bob->uuid}", orgHeader($this->organization))
        ->assertOk()
        ->assertJsonPath('data.viewer.is_following', false)
        ->assertJsonPath('data.viewer.follow_uuid', null);
});

test('my own profile reports is_self and can never be followed', function (): void {
    ExplorerProfile::factory()->for($this->organization)->create([
        'user_id' => $this->alice->id, 'username' => 'alice_gensan',
    ]);

    actingAsProfileUser($this->alice);
    $this->getJson('/api/v1/profile', orgHeader($this->organization))
        ->assertOk()
        ->assertJsonPath('data.viewer.is_self', true)
        ->assertJsonPath('data.viewer.is_following', false);

    // The same subject read through the public route agrees.
    $this->getJson("/api/v1/profiles/{$this->alice->uuid}", orgHeader($this->organization))
        ->assertOk()
        ->assertJsonPath('data.viewer.is_self', true);
});

// ---------------------------------------------------------------------------
// Permission and authorization
// ---------------------------------------------------------------------------

test('a caller cannot edit another explorer\'s profile', function (): void {
    // There is no route to another profile's update — /profile is always the
    // caller's own — so a second user editing simply upserts their own row,
    // never Bob's. This asserts the isolation directly.
    ExplorerProfile::factory()->for($this->organization)->create([
        'user_id' => $this->bob->id, 'username' => 'bob_explorer', 'bio' => 'Bob owns this.',
    ]);

    actingAsProfileUser($this->alice);
    $this->patchJson('/api/v1/profile', [
        'username' => 'alice_gensan',
        'bio' => 'Alice bio.',
    ], orgHeader($this->organization))->assertCreated();

    expect(ExplorerProfile::query()->where('user_id', $this->bob->id)->value('bio'))
        ->toBe('Bob owns this.');
});

test('profile endpoints reject an unauthenticated caller', function (string $method, string $uri): void {
    $this->json($method, $uri, [], orgHeader($this->organization))->assertUnauthorized();
})->with([
    ['get', '/api/v1/profile'],
    ['patch', '/api/v1/profile'],
]);

test('the profile never exposes an email address', function (): void {
    ExplorerProfile::factory()->for($this->organization)->create([
        'user_id' => $this->bob->id, 'username' => 'bob_explorer',
    ]);

    actingAsProfileUser($this->alice);
    $this->getJson("/api/v1/profiles/{$this->bob->uuid}", orgHeader($this->organization))
        ->assertOk()
        ->assertJsonMissing(['email' => $this->bob->email]);
});
