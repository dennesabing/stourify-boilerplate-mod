<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Stourify\Enums\FollowStatus;
use Modules\Stourify\Models\ExplorerProfile;
use Modules\Stourify\Models\Follow;
use Tests\Traits\InteractsWithTestSetup;

uses(RefreshDatabase::class, InteractsWithTestSetup::class);

/**
 * @var list<string>
 */
const FOLLOW_PERMISSIONS = ['stourify.follows.manage'];

beforeEach(function (): void {
    $this->organization = $this->setUpTestOrganization();
    $this->seedPermissions(FOLLOW_PERMISSIONS);

    $this->alice = $this->createUserWithPermissions($this->organization, FOLLOW_PERMISSIONS);
    $this->bob = $this->createUserWithPermissions($this->organization, FOLLOW_PERMISSIONS);
});

function actingAsExplorerUser(User $user): void
{
    Sanctum::actingAs($user);
}

/**
 * Give $user an ExplorerProfile, private or public.
 */
function profileFor(User $user, Organization $organization, bool $isPrivate): ExplorerProfile
{
    return ExplorerProfile::factory()->for($organization)->create([
        'user_id' => $user->id,
        'is_private' => $isPrivate,
    ]);
}

// ---------------------------------------------------------------------------
// Following a public account
// ---------------------------------------------------------------------------

test('following a public account takes effect immediately', function (): void {
    actingAsExplorerUser($this->alice);

    $this->postJson('/api/v1/follows', [
        'user_uuid' => $this->bob->uuid,
    ], orgHeader($this->organization))
        ->assertCreated()
        ->assertJsonPath('data.status', FollowStatus::Active->value)
        ->assertJsonPath('data.is_pending', false)
        ->assertJsonPath('data.followee.uuid', $this->bob->uuid);

    $this->assertDatabaseHas('sto_follows', [
        'follower_id' => $this->alice->id,
        'followee_id' => $this->bob->id,
        'status' => FollowStatus::Active->value,
    ]);
});

test('a user with no explorer profile is treated as a public account', function (): void {
    // Bob has never opened the app, so no ExplorerProfile row exists.
    actingAsExplorerUser($this->alice);

    $this->postJson('/api/v1/follows', [
        'user_uuid' => $this->bob->uuid,
    ], orgHeader($this->organization))
        ->assertCreated()
        ->assertJsonPath('data.status', FollowStatus::Active->value);
});

// ---------------------------------------------------------------------------
// Following a private account
// ---------------------------------------------------------------------------

test('following a private account creates a pending request', function (): void {
    profileFor($this->bob, $this->organization, isPrivate: true);

    actingAsExplorerUser($this->alice);

    $this->postJson('/api/v1/follows', [
        'user_uuid' => $this->bob->uuid,
    ], orgHeader($this->organization))
        ->assertCreated()
        ->assertJsonPath('data.status', FollowStatus::Pending->value)
        ->assertJsonPath('data.is_pending', true)
        ->assertJsonPath('data.accepted_at', null);
});

test('the client cannot force an active follow on a private account', function (): void {
    profileFor($this->bob, $this->organization, isPrivate: true);

    actingAsExplorerUser($this->alice);

    // Status is derived from the target's privacy, never taken from the payload.
    $this->postJson('/api/v1/follows', [
        'user_uuid' => $this->bob->uuid,
        'status' => FollowStatus::Active->value,
        'accepted_at' => now()->toIso8601String(),
    ], orgHeader($this->organization))
        ->assertCreated()
        ->assertJsonPath('data.status', FollowStatus::Pending->value);
});

test('the followee accepts a pending request', function (): void {
    profileFor($this->bob, $this->organization, isPrivate: true);
    $follow = Follow::factory()->for($this->organization)->pending()->create([
        'follower_id' => $this->alice->id, 'followee_id' => $this->bob->id,
    ]);

    actingAsExplorerUser($this->bob);

    $this->postJson("/api/v1/follows/{$follow->uuid}/accept", [], orgHeader($this->organization))
        ->assertOk()
        ->assertJsonPath('data.status', FollowStatus::Active->value);

    expect($follow->fresh()->accepted_at)->not->toBeNull();
});

test('the follower cannot accept their own request', function (): void {
    profileFor($this->bob, $this->organization, isPrivate: true);
    $follow = Follow::factory()->for($this->organization)->pending()->create([
        'follower_id' => $this->alice->id, 'followee_id' => $this->bob->id,
    ]);

    actingAsExplorerUser($this->alice);

    $this->postJson("/api/v1/follows/{$follow->uuid}/accept", [], orgHeader($this->organization))
        ->assertForbidden();

    expect($follow->fresh()->status)->toBe(FollowStatus::Pending);
});

test('the accept path is not blocked by the update ability it writes through', function (): void {
    // Regression guard: accept() writes via CrudService, which authorizes
    // 'update'. If FollowPolicy::update() ever stops agreeing with accept(),
    // the endpoint 403s for the one person entitled to use it.
    $follow = Follow::factory()->for($this->organization)->pending()->create([
        'follower_id' => $this->alice->id, 'followee_id' => $this->bob->id,
    ]);

    expect($this->bob->can('update', $follow))->toBeTrue()
        ->and($this->bob->can('accept', $follow))->toBeTrue()
        ->and($this->alice->can('update', $follow))->toBeFalse()
        ->and($this->alice->can('accept', $follow))->toBeFalse();
});

test('an unrelated explorer cannot accept someone else\'s request', function (): void {
    $carol = $this->createUserWithPermissions($this->organization, FOLLOW_PERMISSIONS);
    $follow = Follow::factory()->for($this->organization)->pending()->create([
        'follower_id' => $this->alice->id, 'followee_id' => $this->bob->id,
    ]);

    actingAsExplorerUser($carol);

    $this->postJson("/api/v1/follows/{$follow->uuid}/accept", [], orgHeader($this->organization))
        ->assertForbidden();
});

test('accepting is idempotent', function (): void {
    $follow = Follow::factory()->for($this->organization)->pending()->create([
        'follower_id' => $this->alice->id, 'followee_id' => $this->bob->id,
    ]);

    actingAsExplorerUser($this->bob);

    $this->postJson("/api/v1/follows/{$follow->uuid}/accept", [], orgHeader($this->organization))->assertOk();
    $first = $follow->fresh()->accepted_at;

    $this->postJson("/api/v1/follows/{$follow->uuid}/accept", [], orgHeader($this->organization))->assertOk();

    expect($follow->fresh()->accepted_at->equalTo($first))->toBeTrue();
});

// ---------------------------------------------------------------------------
// Ending the relationship — both sides, same endpoint
// ---------------------------------------------------------------------------

test('the follower can unfollow', function (): void {
    $follow = Follow::factory()->for($this->organization)->create([
        'follower_id' => $this->alice->id, 'followee_id' => $this->bob->id,
    ]);

    actingAsExplorerUser($this->alice);
    $this->deleteJson("/api/v1/follows/{$follow->uuid}", [], orgHeader($this->organization))->assertOk();

    $this->assertDatabaseMissing('sto_follows', ['id' => $follow->id]);
});

test('the followee can remove a follower', function (): void {
    $follow = Follow::factory()->for($this->organization)->create([
        'follower_id' => $this->alice->id, 'followee_id' => $this->bob->id,
    ]);

    actingAsExplorerUser($this->bob);
    $this->deleteJson("/api/v1/follows/{$follow->uuid}", [], orgHeader($this->organization))->assertOk();

    $this->assertDatabaseMissing('sto_follows', ['id' => $follow->id]);
});

test('an unrelated explorer cannot delete someone else\'s follow edge', function (): void {
    $carol = $this->createUserWithPermissions($this->organization, FOLLOW_PERMISSIONS);
    $follow = Follow::factory()->for($this->organization)->create([
        'follower_id' => $this->alice->id, 'followee_id' => $this->bob->id,
    ]);

    actingAsExplorerUser($carol);
    $this->deleteJson("/api/v1/follows/{$follow->uuid}", [], orgHeader($this->organization))
        ->assertForbidden();

    $this->assertDatabaseHas('sto_follows', ['id' => $follow->id]);
});

test('unfollowing hard-deletes so the same person can be followed again', function (): void {
    actingAsExplorerUser($this->alice);

    $uuid = $this->postJson('/api/v1/follows', [
        'user_uuid' => $this->bob->uuid,
    ], orgHeader($this->organization))->assertCreated()->json('data.uuid');

    $this->deleteJson("/api/v1/follows/{$uuid}", [], orgHeader($this->organization))->assertOk();

    // A tombstone would collide with the unique (follower_id, followee_id) index.
    $this->postJson('/api/v1/follows', [
        'user_uuid' => $this->bob->uuid,
    ], orgHeader($this->organization))->assertCreated();
});

// ---------------------------------------------------------------------------
// Lists
// ---------------------------------------------------------------------------

test('the followers and following lists read opposite sides of the same edge', function (): void {
    Follow::factory()->for($this->organization)->create([
        'follower_id' => $this->alice->id, 'followee_id' => $this->bob->id,
    ]);

    actingAsExplorerUser($this->alice);

    $aliceFollowing = $this->getJson('/api/v1/follows?direction=following', orgHeader($this->organization))
        ->assertOk()->json('data');
    expect($aliceFollowing)->toHaveCount(1)
        ->and($aliceFollowing[0]['followee']['uuid'])->toBe($this->bob->uuid);

    $aliceFollowers = $this->getJson('/api/v1/follows?direction=followers', orgHeader($this->organization))
        ->assertOk()->json('data');
    expect($aliceFollowers)->toHaveCount(0);

    $bobFollowers = $this->getJson(
        "/api/v1/follows?direction=followers&user_uuid={$this->bob->uuid}", orgHeader($this->organization)
    )->assertOk()->json('data');
    expect($bobFollowers)->toHaveCount(1)
        ->and($bobFollowers[0]['follower']['uuid'])->toBe($this->alice->uuid);
});

test('pending requests do not appear in the public followers list', function (): void {
    Follow::factory()->for($this->organization)->pending()->create([
        'follower_id' => $this->alice->id, 'followee_id' => $this->bob->id,
    ]);

    actingAsExplorerUser($this->bob);

    $this->getJson("/api/v1/follows?direction=followers&user_uuid={$this->bob->uuid}", orgHeader($this->organization))
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('the requests screen shows only requests addressed to the caller', function (): void {
    $carol = $this->createUserWithPermissions($this->organization, FOLLOW_PERMISSIONS);

    $mine = Follow::factory()->for($this->organization)->pending()->create([
        'follower_id' => $this->alice->id, 'followee_id' => $this->bob->id,
    ]);
    Follow::factory()->for($this->organization)->pending()->create([
        'follower_id' => $this->alice->id, 'followee_id' => $carol->id,
    ]);

    actingAsExplorerUser($this->bob);

    $data = $this->getJson('/api/v1/follows/requests', orgHeader($this->organization))->assertOk()->json('data');

    expect($data)->toHaveCount(1)
        ->and($data[0]['uuid'])->toBe($mine->uuid)
        ->and($data[0]['follower']['uuid'])->toBe($this->alice->uuid);
});

test('a private account\'s graph is hidden from strangers but open to accepted followers', function (): void {
    profileFor($this->bob, $this->organization, isPrivate: true);
    $carol = $this->createUserWithPermissions($this->organization, FOLLOW_PERMISSIONS);

    Follow::factory()->for($this->organization)->create([
        'follower_id' => $carol->id, 'followee_id' => $this->bob->id,
    ]);

    // Alice does not follow Bob.
    actingAsExplorerUser($this->alice);
    $this->getJson("/api/v1/follows?direction=followers&user_uuid={$this->bob->uuid}", orgHeader($this->organization))
        ->assertForbidden();

    // Carol does.
    actingAsExplorerUser($carol);
    $this->getJson("/api/v1/follows?direction=followers&user_uuid={$this->bob->uuid}", orgHeader($this->organization))
        ->assertOk();

    // And Bob always sees his own.
    actingAsExplorerUser($this->bob);
    $this->getJson("/api/v1/follows?direction=followers&user_uuid={$this->bob->uuid}", orgHeader($this->organization))
        ->assertOk();
});

test('a pending follower cannot read a private account\'s graph', function (): void {
    profileFor($this->bob, $this->organization, isPrivate: true);
    Follow::factory()->for($this->organization)->pending()->create([
        'follower_id' => $this->alice->id, 'followee_id' => $this->bob->id,
    ]);

    actingAsExplorerUser($this->alice);

    $this->getJson("/api/v1/follows?direction=followers&user_uuid={$this->bob->uuid}", orgHeader($this->organization))
        ->assertForbidden();
});

test('a public account\'s graph is readable by anyone', function (): void {
    Follow::factory()->for($this->organization)->create([
        'follower_id' => $this->alice->id, 'followee_id' => $this->bob->id,
    ]);
    $carol = $this->createUserWithPermissions($this->organization, FOLLOW_PERMISSIONS);

    actingAsExplorerUser($carol);

    $this->getJson("/api/v1/follows?direction=followers&user_uuid={$this->bob->uuid}", orgHeader($this->organization))
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

// ---------------------------------------------------------------------------
// Validation and permissions
// ---------------------------------------------------------------------------

test('an explorer cannot follow themselves', function (): void {
    actingAsExplorerUser($this->alice);

    $this->postJson('/api/v1/follows', [
        'user_uuid' => $this->alice->uuid,
    ], orgHeader($this->organization))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['user_uuid']);
});

test('following the same explorer twice is a validation error, not a 500', function (): void {
    Follow::factory()->for($this->organization)->create([
        'follower_id' => $this->alice->id, 'followee_id' => $this->bob->id,
    ]);

    actingAsExplorerUser($this->alice);

    $this->postJson('/api/v1/follows', [
        'user_uuid' => $this->bob->uuid,
    ], orgHeader($this->organization))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['user_uuid']);
});

test('following requires a real explorer', function (): void {
    actingAsExplorerUser($this->alice);

    $this->postJson('/api/v1/follows', [], orgHeader($this->organization))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['user_uuid']);

    $this->postJson('/api/v1/follows', [
        'user_uuid' => '00000000-0000-4000-8000-000000000000',
    ], orgHeader($this->organization))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['user_uuid']);
});

test('follow endpoints reject an unauthenticated caller', function (string $method, string $uri): void {
    $this->json($method, $uri, [], orgHeader($this->organization))->assertUnauthorized();
})->with([
    ['get', '/api/v1/follows'],
    ['get', '/api/v1/follows/requests'],
    ['post', '/api/v1/follows'],
]);

test('following is denied without the follows permission', function (): void {
    actingAsExplorerUser($this->createUserWithPermissions($this->organization, []));

    $this->postJson('/api/v1/follows', [
        'user_uuid' => $this->bob->uuid,
    ], orgHeader($this->organization))->assertForbidden();
});

test('a follow edge is not readable by an uninvolved explorer', function (): void {
    $carol = $this->createUserWithPermissions($this->organization, FOLLOW_PERMISSIONS);
    $follow = Follow::factory()->for($this->organization)->create([
        'follower_id' => $this->alice->id, 'followee_id' => $this->bob->id,
    ]);

    actingAsExplorerUser($carol);
    $this->getJson("/api/v1/follows/{$follow->uuid}", orgHeader($this->organization))->assertForbidden();

    actingAsExplorerUser($this->alice);
    $this->getJson("/api/v1/follows/{$follow->uuid}", orgHeader($this->organization))->assertOk();
});

test('the explorer resource never exposes an email address', function (): void {
    Follow::factory()->for($this->organization)->create([
        'follower_id' => $this->alice->id, 'followee_id' => $this->bob->id,
    ]);

    actingAsExplorerUser($this->alice);

    $this->getJson('/api/v1/follows?direction=following', orgHeader($this->organization))
        ->assertOk()
        ->assertJsonMissing(['email' => $this->bob->email]);
});

/**
 * STOURIFY-23 — see SpotApiTest for the ordering this asserts. The invalid-body
 * half matters more here than elsewhere: the `user_uuid` rule is an `exists`
 * lookup on `users`, so validating ahead of the gate told an unauthorized
 * caller whether an account exists.
 */
test('following is denied without the follows permission, whatever the payload', function (): void {
    actingAsExplorerUser($this->createUserWithPermissions($this->organization, []));

    $before = Follow::query()->count();

    $this->postJson('/api/v1/follows', [
        'user_uuid' => $this->bob->uuid,
    ], orgHeader($this->organization))->assertForbidden();

    $this->postJson('/api/v1/follows', ['user_uuid' => 'not-a-uuid'], orgHeader($this->organization))
        ->assertForbidden();

    expect(Follow::query()->count())->toBe($before);
});
