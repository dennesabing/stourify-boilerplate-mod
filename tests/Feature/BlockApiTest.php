<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Stourify\Enums\FollowStatus;
use Modules\Stourify\Enums\PostVisibility;
use Modules\Stourify\Enums\SpotStatus;
use Modules\Stourify\Models\Block;
use Modules\Stourify\Models\ExplorerProfile;
use Modules\Stourify\Models\Follow;
use Modules\Stourify\Models\Post;
use Modules\Stourify\Models\Spot;
use Tests\TestCase;
use Tests\Traits\InteractsWithTestSetup;

uses(RefreshDatabase::class, InteractsWithTestSetup::class);

/**
 * Blocking, and the thing that makes it real: it holds on BOTH sides.
 *
 * Nearly every test here asserts twice — once as the blocker, once as the
 * blocked party. A block that only hides the blocked user's content from the
 * blocker is the bug this suite exists to catch: the blocked party would go on
 * reading, following and searching the person who blocked them.
 *
 * `$alice` blocks; `$bob` is blocked; `$carol` is the third party who must be
 * unaffected, because a per-viewer filter that leaks into other viewers' lists
 * is the other way this goes wrong.
 */

/**
 * @var list<string>
 */
const BLOCK_PERMISSIONS = [
    'stourify.follows.manage',
    'stourify.posts.view',
    'stourify.posts.create',
    'stourify.spots.view',
];

beforeEach(function (): void {
    $this->organization = $this->setUpTestOrganization();
    $this->seedPermissions([...BLOCK_PERMISSIONS, 'stourify.posts.manage', 'stourify.spots.manage']);

    $this->alice = $this->createUserWithPermissions($this->organization, BLOCK_PERMISSIONS);
    $this->bob = $this->createUserWithPermissions($this->organization, BLOCK_PERMISSIONS);
    $this->carol = $this->createUserWithPermissions($this->organization, BLOCK_PERMISSIONS);
});

function actingAsBlockUser(User $user): void
{
    Sanctum::actingAs($user);
}

/**
 * A published, public spot contributed by $author.
 */
function blockSpot(Organization $organization, User $author, string $title = 'A Place'): Spot
{
    return Spot::factory()->for($organization)->create([
        'user_id' => $author->id,
        'title' => $title,
        'status' => SpotStatus::Published,
    ]);
}

/**
 * A published, public post by $author at their own spot.
 */
function blockPost(Organization $organization, User $author): Post
{
    return Post::factory()->for($organization)->create([
        'user_id' => $author->id,
        'spot_id' => blockSpot($organization, $author)->id,
        'visibility' => PostVisibility::Public,
        'published_at' => now(),
    ]);
}

function blockProfile(Organization $organization, User $user, string $username): ExplorerProfile
{
    return ExplorerProfile::factory()->for($organization)->create([
        'user_id' => $user->id,
        'username' => $username,
    ]);
}

function blockFollow(Organization $organization, User $follower, User $followee, FollowStatus $status = FollowStatus::Active): Follow
{
    return Follow::factory()->for($organization)->create([
        'follower_id' => $follower->id,
        'followee_id' => $followee->id,
        'status' => $status,
        'accepted_at' => $status === FollowStatus::Active ? now() : null,
    ]);
}

/**
 * Alice blocks Bob through the API — the path the app takes, not a factory
 * insert, so the side effects under test actually run.
 */
function aliceBlocksBob(): string
{
    /** @var TestCase $test */
    $test = test();

    actingAsBlockUser($test->alice);

    return $test->postJson('/api/v1/blocks', [
        'user_uuid' => $test->bob->uuid,
    ], orgHeader($test->organization))->assertCreated()->json('data.uuid');
}

// ---------------------------------------------------------------------------
// The block itself
// ---------------------------------------------------------------------------

test('blocking a user creates the row and returns it', function (): void {
    actingAsBlockUser($this->alice);

    $this->postJson('/api/v1/blocks', [
        'user_uuid' => $this->bob->uuid,
    ], orgHeader($this->organization))
        ->assertCreated()
        ->assertJsonPath('data.blocked.uuid', $this->bob->uuid);

    $this->assertDatabaseHas('sto_blocks', [
        'blocker_id' => $this->alice->id,
        'blocked_id' => $this->bob->id,
    ]);
});

test('blocking the same user twice is idempotent', function (): void {
    aliceBlocksBob();

    actingAsBlockUser($this->alice);
    $this->postJson('/api/v1/blocks', [
        'user_uuid' => $this->bob->uuid,
    ], orgHeader($this->organization))->assertOk();

    expect(Block::query()->count())->toBe(1);
});

test('a user cannot block themselves', function (): void {
    actingAsBlockUser($this->alice);

    $this->postJson('/api/v1/blocks', [
        'user_uuid' => $this->alice->uuid,
    ], orgHeader($this->organization))
        ->assertStatus(422)
        ->assertJsonValidationErrors('user_uuid');
});

test('the block list returns only the callers own blocks, never who blocked them', function (): void {
    aliceBlocksBob();

    // Carol blocks Alice. Alice must not learn that from her own list.
    actingAsBlockUser($this->carol);
    $this->postJson('/api/v1/blocks', [
        'user_uuid' => $this->alice->uuid,
    ], orgHeader($this->organization))->assertCreated();

    actingAsBlockUser($this->alice);
    $data = $this->getJson('/api/v1/blocks', orgHeader($this->organization))->assertOk()->json('data');

    expect($data)->toHaveCount(1)
        ->and($data[0]['blocked']['uuid'])->toBe($this->bob->uuid);
});

test('only the blocker can lift a block', function (): void {
    $blockUuid = aliceBlocksBob();

    actingAsBlockUser($this->bob);
    $this->deleteJson("/api/v1/blocks/{$blockUuid}", [], orgHeader($this->organization))
        ->assertForbidden();

    actingAsBlockUser($this->alice);
    $this->deleteJson("/api/v1/blocks/{$blockUuid}", [], orgHeader($this->organization))
        ->assertOk();

    expect(Block::query()->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Severing the follow graph — both directions, both statuses
// ---------------------------------------------------------------------------

test('blocking removes the follow edges in both directions', function (): void {
    blockFollow($this->organization, $this->alice, $this->bob);
    blockFollow($this->organization, $this->bob, $this->alice);

    aliceBlocksBob();

    expect(Follow::query()->count())->toBe(0);
});

test('blocking removes a pending follow request in either direction', function (): void {
    blockFollow($this->organization, $this->bob, $this->alice, FollowStatus::Pending);

    aliceBlocksBob();

    expect(Follow::query()->count())->toBe(0);
});

test('blocking leaves other peoples follow edges alone', function (): void {
    blockFollow($this->organization, $this->carol, $this->bob);
    blockFollow($this->organization, $this->alice, $this->carol);

    aliceBlocksBob();

    expect(Follow::query()->count())->toBe(2);
});

test('unblocking does not restore the severed follows', function (): void {
    blockFollow($this->organization, $this->alice, $this->bob);
    $blockUuid = aliceBlocksBob();

    actingAsBlockUser($this->alice);
    $this->deleteJson("/api/v1/blocks/{$blockUuid}", [], orgHeader($this->organization))->assertOk();

    expect(Follow::query()->count())->toBe(0);
});

test('neither party can follow the other while a block stands', function (): void {
    aliceBlocksBob();

    // The blocker cannot re-follow...
    actingAsBlockUser($this->alice);
    $this->postJson('/api/v1/follows', [
        'user_uuid' => $this->bob->uuid,
    ], orgHeader($this->organization))->assertForbidden();

    // ...and neither can the blocked party, which is the half that gets missed.
    actingAsBlockUser($this->bob);
    $this->postJson('/api/v1/follows', [
        'user_uuid' => $this->alice->uuid,
    ], orgHeader($this->organization))->assertForbidden();

    expect(Follow::query()->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Content — the feed, both sides
// ---------------------------------------------------------------------------

test('the feed hides the blocked users posts from the blocker', function (): void {
    $bobPost = blockPost($this->organization, $this->bob);
    $carolPost = blockPost($this->organization, $this->carol);
    aliceBlocksBob();

    actingAsBlockUser($this->alice);
    $uuids = collect($this->getJson('/api/v1/feed', orgHeader($this->organization))->assertOk()->json('data'))
        ->pluck('uuid');

    // Carol's post is the control: an empty feed would satisfy the negative
    // assertion on its own and prove nothing.
    expect($uuids)->toContain($carolPost->uuid)->not->toContain($bobPost->uuid);
});

test('the feed hides the blockers posts from the blocked user', function (): void {
    $alicePost = blockPost($this->organization, $this->alice);
    $carolPost = blockPost($this->organization, $this->carol);
    aliceBlocksBob();

    actingAsBlockUser($this->bob);
    $uuids = collect($this->getJson('/api/v1/feed', orgHeader($this->organization))->assertOk()->json('data'))
        ->pluck('uuid');

    expect($uuids)->toContain($carolPost->uuid)->not->toContain($alicePost->uuid);
});

test('a third party still sees both parties posts', function (): void {
    $alicePost = blockPost($this->organization, $this->alice);
    $bobPost = blockPost($this->organization, $this->bob);
    aliceBlocksBob();

    actingAsBlockUser($this->carol);
    $uuids = collect($this->getJson('/api/v1/feed', orgHeader($this->organization))->assertOk()->json('data'))
        ->pluck('uuid');

    expect($uuids)->toContain($alicePost->uuid)->toContain($bobPost->uuid);
});

test('unblocking restores mutual feed visibility', function (): void {
    $bobPost = blockPost($this->organization, $this->bob);
    $blockUuid = aliceBlocksBob();

    actingAsBlockUser($this->alice);
    $this->deleteJson("/api/v1/blocks/{$blockUuid}", [], orgHeader($this->organization))->assertOk();

    $uuids = collect($this->getJson('/api/v1/feed', orgHeader($this->organization))->assertOk()->json('data'))
        ->pluck('uuid');

    expect($uuids)->toContain($bobPost->uuid);
});

// ---------------------------------------------------------------------------
// Content — the post index and a single post, both sides
// ---------------------------------------------------------------------------

test('the post index hides the other party from both sides', function (): void {
    $alicePost = blockPost($this->organization, $this->alice);
    $bobPost = blockPost($this->organization, $this->bob);
    aliceBlocksBob();

    // Each side still sees their own — otherwise "absent" would prove nothing.
    actingAsBlockUser($this->alice);
    expect(collect($this->getJson('/api/v1/posts', orgHeader($this->organization))->assertOk()->json('data'))->pluck('uuid'))
        ->toContain($alicePost->uuid)
        ->not->toContain($bobPost->uuid);

    actingAsBlockUser($this->bob);
    expect(collect($this->getJson('/api/v1/posts', orgHeader($this->organization))->assertOk()->json('data'))->pluck('uuid'))
        ->toContain($bobPost->uuid)
        ->not->toContain($alicePost->uuid);
});

test('a single post is unreachable across a block from both sides', function (): void {
    $alicePost = blockPost($this->organization, $this->alice);
    $bobPost = blockPost($this->organization, $this->bob);
    aliceBlocksBob();

    actingAsBlockUser($this->alice);
    $this->getJson("/api/v1/posts/{$bobPost->uuid}", orgHeader($this->organization))->assertForbidden();

    actingAsBlockUser($this->bob);
    $this->getJson("/api/v1/posts/{$alicePost->uuid}", orgHeader($this->organization))->assertForbidden();
});

// ---------------------------------------------------------------------------
// Content — spots, both sides
// ---------------------------------------------------------------------------

test('the spot index hides the other partys spots from both sides', function (): void {
    $aliceSpot = blockSpot($this->organization, $this->alice, 'Alice Ridge');
    $bobSpot = blockSpot($this->organization, $this->bob, 'Bob Cove');
    aliceBlocksBob();

    actingAsBlockUser($this->alice);
    expect(collect($this->getJson('/api/v1/spots', orgHeader($this->organization))->assertOk()->json('data'))->pluck('uuid'))
        ->toContain($aliceSpot->uuid)
        ->not->toContain($bobSpot->uuid);

    actingAsBlockUser($this->bob);
    expect(collect($this->getJson('/api/v1/spots', orgHeader($this->organization))->assertOk()->json('data'))->pluck('uuid'))
        ->toContain($bobSpot->uuid)
        ->not->toContain($aliceSpot->uuid);
});

test('spot search hides the other partys spots from both sides', function (): void {
    $aliceSpot = blockSpot($this->organization, $this->alice, 'Kalanggaman Sandbar');
    $bobSpot = blockSpot($this->organization, $this->bob, 'Kalanggaman Point');
    aliceBlocksBob();

    actingAsBlockUser($this->alice);
    expect(collect($this->getJson('/api/v1/discover/search?type=spots&q=Kalanggaman', orgHeader($this->organization))->assertOk()->json('data'))->pluck('uuid'))
        ->toContain($aliceSpot->uuid)
        ->not->toContain($bobSpot->uuid);

    actingAsBlockUser($this->bob);
    expect(collect($this->getJson('/api/v1/discover/search?type=spots&q=Kalanggaman', orgHeader($this->organization))->assertOk()->json('data'))->pluck('uuid'))
        ->toContain($bobSpot->uuid)
        ->not->toContain($aliceSpot->uuid);
});

// ---------------------------------------------------------------------------
// Discovery — people search and the profile header, both sides
// ---------------------------------------------------------------------------

test('people search hides the other party from both sides', function (): void {
    $aliceProfile = blockProfile($this->organization, $this->alice, 'wander_alice');
    $bobProfile = blockProfile($this->organization, $this->bob, 'wander_bob');
    aliceBlocksBob();

    $carolProfile = blockProfile($this->organization, $this->carol, 'wander_carol');

    actingAsBlockUser($this->alice);
    expect(collect($this->getJson('/api/v1/discover/search?type=people&q=wander', orgHeader($this->organization))->assertOk()->json('data'))->pluck('uuid'))
        ->toContain($carolProfile->uuid)
        ->not->toContain($bobProfile->uuid);

    actingAsBlockUser($this->bob);
    expect(collect($this->getJson('/api/v1/discover/search?type=people&q=wander', orgHeader($this->organization))->assertOk()->json('data'))->pluck('uuid'))
        ->toContain($carolProfile->uuid)
        ->not->toContain($aliceProfile->uuid);
});

test('the profile header is refused across a block, identically in both directions', function (): void {
    blockProfile($this->organization, $this->alice, 'wander_alice');
    blockProfile($this->organization, $this->bob, 'wander_bob');
    aliceBlocksBob();

    actingAsBlockUser($this->alice);
    $blockerSaw = $this->getJson("/api/v1/profiles/{$this->bob->uuid}", orgHeader($this->organization))
        ->assertForbidden();

    actingAsBlockUser($this->bob);
    $blockedSaw = $this->getJson("/api/v1/profiles/{$this->alice->uuid}", orgHeader($this->organization))
        ->assertForbidden();

    // The blocked party must not be able to tell they are the blocked one:
    // same status, same message, nothing naming a direction.
    expect($blockedSaw->json('message'))->toBe($blockerSaw->json('message'))
        ->and($blockedSaw->json('message'))->not->toContain($this->alice->name)
        ->and($blockedSaw->json('message'))->not->toContain('block');
});

// ---------------------------------------------------------------------------
// Cost
// ---------------------------------------------------------------------------

test('a standing block costs one query per feed page, not one per post', function (): void {
    // `PostPolicy::view()` is asked about every row, because PostResource
    // resolves a `can` key for each one. Without the per-request memo on
    // Block::hiddenUserIdsFor() that is a query per post, and it scales with
    // page size — this test is what catches the memo being removed.
    aliceBlocksBob();

    $spot = blockSpot($this->organization, $this->carol, 'Shared Spot');
    foreach (range(1, 3) as $i) {
        Post::factory()->for($this->organization)->create([
            'user_id' => $this->carol->id,
            'spot_id' => $spot->id,
            'visibility' => PostVisibility::Public,
            'published_at' => sprintf('2026-07-%02d 09:00:00', $i),
        ]);
    }

    // Counting `sto_blocks` reads rather than total queries: the feed has its
    // own per-row costs that are not this card's business, and a total-query
    // budget would make this test fail for reasons that have nothing to do
    // with blocking.
    $blockQueries = function (): int {
        return collect(DB::getQueryLog())
            ->filter(fn (array $entry): bool => str_contains($entry['query'], 'sto_blocks'))
            ->count();
    };

    actingAsBlockUser($this->alice);

    // Fresh memo per request is the real contract, so measure a request that
    // has not been preceded by one in the same container.
    Block::forgetHiddenMemo();
    DB::enableQueryLog();
    $this->getJson('/api/v1/feed?limit=25', orgHeader($this->organization))
        ->assertOk()->assertJsonCount(3, 'data');
    expect($blockQueries())->toBe(1);
    DB::flushQueryLog();

    foreach (range(4, 12) as $i) {
        Post::factory()->for($this->organization)->create([
            'user_id' => $this->carol->id,
            'spot_id' => $spot->id,
            'visibility' => PostVisibility::Public,
            'published_at' => sprintf('2026-07-%02d 09:00:00', $i),
        ]);
    }

    Block::forgetHiddenMemo();
    DB::flushQueryLog();
    $this->getJson('/api/v1/feed?limit=25', orgHeader($this->organization))
        ->assertOk()->assertJsonCount(12, 'data');
    $twelvePosts = $blockQueries();
    DB::disableQueryLog();

    expect($twelvePosts)->toBe(1, "Block lookup ran {$twelvePosts} times for a 12-post page; it must run once.");
});

test('a third party can still read both profiles', function (): void {
    blockProfile($this->organization, $this->alice, 'wander_alice');
    blockProfile($this->organization, $this->bob, 'wander_bob');
    aliceBlocksBob();

    actingAsBlockUser($this->carol);
    $this->getJson("/api/v1/profiles/{$this->alice->uuid}", orgHeader($this->organization))->assertOk();
    $this->getJson("/api/v1/profiles/{$this->bob->uuid}", orgHeader($this->organization))->assertOk();
});
