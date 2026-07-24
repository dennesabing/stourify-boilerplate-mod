<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Stourify\Enums\FollowStatus;
use Modules\Stourify\Enums\PostVisibility;
use Modules\Stourify\Enums\SpotStatus;
use Modules\Stourify\Models\Follow;
use Modules\Stourify\Models\Post;
use Modules\Stourify\Models\Spot;
use Tests\Traits\InteractsWithTestSetup;

uses(RefreshDatabase::class, InteractsWithTestSetup::class);

/**
 * @var list<string>
 */
const FEED_PERMISSIONS = ['stourify.posts.view', 'stourify.posts.create'];

beforeEach(function (): void {
    $this->organization = $this->setUpTestOrganization();
    $this->seedPermissions([...FEED_PERMISSIONS, 'stourify.posts.manage']);

    $this->viewer = $this->createUserWithPermissions($this->organization, FEED_PERMISSIONS);
    $this->author = $this->createUserWithPermissions($this->organization, FEED_PERMISSIONS);

    $this->spot = Spot::factory()->for($this->organization)->create([
        'user_id' => $this->author->id, 'status' => SpotStatus::Published,
    ]);
});

function actingAsFeedUser(User $user): void
{
    Sanctum::actingAs($user);
}

/**
 * A published post by $author at a given moment, so ordering is deterministic.
 */
function publishedPost(
    mixed $organization, User $author, Spot $spot, string $at, PostVisibility $visibility = PostVisibility::Public
): Post {
    return Post::factory()->for($organization)->create([
        'user_id' => $author->id,
        'spot_id' => $spot->id,
        'visibility' => $visibility,
        'published_at' => $at,
    ]);
}

function feedFollow(User $follower, User $followee, mixed $organization): void
{
    Follow::factory()->for($organization)->create([
        'follower_id' => $follower->id, 'followee_id' => $followee->id,
        'status' => FollowStatus::Active, 'accepted_at' => now(),
    ]);
}

// ---------------------------------------------------------------------------
// Composition and order
// ---------------------------------------------------------------------------

test('the feed returns published public posts, newest first', function (): void {
    $older = publishedPost($this->organization, $this->author, $this->spot, '2026-07-01 09:00:00');
    $newer = publishedPost($this->organization, $this->author, $this->spot, '2026-07-10 09:00:00');
    $middle = publishedPost($this->organization, $this->author, $this->spot, '2026-07-05 09:00:00');

    actingAsFeedUser($this->viewer);
    $uuids = collect($this->getJson('/api/v1/feed', orgHeader($this->organization))->assertOk()->json('data'))
        ->pluck('uuid')->all();

    expect($uuids)->toBe([$newer->uuid, $middle->uuid, $older->uuid]);
});

test('the feed includes the viewer\'s own published posts', function (): void {
    $mine = publishedPost($this->organization, $this->viewer, $this->spot, '2026-07-08 09:00:00');
    $theirs = publishedPost($this->organization, $this->author, $this->spot, '2026-07-09 09:00:00');

    actingAsFeedUser($this->viewer);
    $uuids = collect($this->getJson('/api/v1/feed', orgHeader($this->organization))->json('data'))->pluck('uuid');

    expect($uuids)->toContain($mine->uuid)->and($uuids)->toContain($theirs->uuid);
});

test('the feed excludes unpublished posts, including the viewer\'s own drafts', function (): void {
    $myDraft = Post::factory()->for($this->organization)->create([
        'user_id' => $this->viewer->id, 'spot_id' => $this->spot->id, 'published_at' => null,
    ]);
    $theirDraft = Post::factory()->for($this->organization)->create([
        'user_id' => $this->author->id, 'spot_id' => $this->spot->id, 'published_at' => null,
    ]);
    $published = publishedPost($this->organization, $this->author, $this->spot, '2026-07-09 09:00:00');

    actingAsFeedUser($this->viewer);
    $uuids = collect($this->getJson('/api/v1/feed', orgHeader($this->organization))->json('data'))->pluck('uuid');

    expect($uuids)->toContain($published->uuid)
        ->and($uuids)->not->toContain($myDraft->uuid)
        ->and($uuids)->not->toContain($theirDraft->uuid);
});

// ---------------------------------------------------------------------------
// Visibility — the feed honours the same audience rule as the post list
// ---------------------------------------------------------------------------

test('a private post by another author never reaches the feed', function (): void {
    $private = publishedPost(
        $this->organization, $this->author, $this->spot, '2026-07-09 09:00:00', PostVisibility::Private
    );

    actingAsFeedUser($this->viewer);
    $uuids = collect($this->getJson('/api/v1/feed', orgHeader($this->organization))->json('data'))->pluck('uuid');

    expect($uuids)->not->toContain($private->uuid);
});

test('a followers-only post reaches the feed only when the viewer follows the author', function (): void {
    $followersOnly = publishedPost(
        $this->organization, $this->author, $this->spot, '2026-07-09 09:00:00', PostVisibility::Followers
    );

    // Not following yet.
    actingAsFeedUser($this->viewer);
    $before = collect($this->getJson('/api/v1/feed', orgHeader($this->organization))->json('data'))->pluck('uuid');
    expect($before)->not->toContain($followersOnly->uuid);

    // Now following.
    feedFollow($this->viewer, $this->author, $this->organization);
    $after = collect($this->getJson('/api/v1/feed', orgHeader($this->organization))->json('data'))->pluck('uuid');
    expect($after)->toContain($followersOnly->uuid);
});

test('a pending follow does not admit followers-only posts to the feed', function (): void {
    Follow::factory()->for($this->organization)->pending()->create([
        'follower_id' => $this->viewer->id, 'followee_id' => $this->author->id,
    ]);
    $followersOnly = publishedPost(
        $this->organization, $this->author, $this->spot, '2026-07-09 09:00:00', PostVisibility::Followers
    );

    actingAsFeedUser($this->viewer);
    $uuids = collect($this->getJson('/api/v1/feed', orgHeader($this->organization))->json('data'))->pluck('uuid');

    expect($uuids)->not->toContain($followersOnly->uuid);
});

test('a moderator gets their own feed, not every private post in the system', function (): void {
    // The moderator can list restricted posts via the post index, but the feed
    // is a consumption surface and grants no such bypass.
    $moderator = $this->createUserWithPermissions(
        $this->organization, [...FEED_PERMISSIONS, 'stourify.posts.manage']
    );
    $strangersPrivate = publishedPost(
        $this->organization, $this->author, $this->spot, '2026-07-09 09:00:00', PostVisibility::Private
    );
    $public = publishedPost($this->organization, $this->author, $this->spot, '2026-07-08 09:00:00');

    actingAsFeedUser($moderator);
    $uuids = collect($this->getJson('/api/v1/feed', orgHeader($this->organization))->json('data'))->pluck('uuid');

    expect($uuids)->toContain($public->uuid)
        ->and($uuids)->not->toContain($strangersPrivate->uuid);
});

// ---------------------------------------------------------------------------
// Cursor pagination
// ---------------------------------------------------------------------------

test('the feed paginates by cursor without repeating or dropping a post', function (): void {
    // Five posts at distinct times.
    foreach (range(1, 5) as $day) {
        publishedPost($this->organization, $this->author, $this->spot, sprintf('2026-07-%02d 09:00:00', $day));
    }

    actingAsFeedUser($this->viewer);

    $firstPage = $this->getJson('/api/v1/feed?limit=2', orgHeader($this->organization))->assertOk();
    $firstUuids = collect($firstPage->json('data'))->pluck('uuid');
    $nextCursor = $firstPage->json('meta.next_cursor');

    expect($firstUuids)->toHaveCount(2)
        ->and($nextCursor)->not->toBeNull();

    $secondPage = $this->getJson("/api/v1/feed?limit=2&cursor={$nextCursor}", orgHeader($this->organization))->assertOk();
    $secondUuids = collect($secondPage->json('data'))->pluck('uuid');

    $thirdCursor = $secondPage->json('meta.next_cursor');
    $thirdUuids = collect(
        $this->getJson("/api/v1/feed?limit=2&cursor={$thirdCursor}", orgHeader($this->organization))->json('data')
    )->pluck('uuid');

    $all = $firstUuids->concat($secondUuids)->concat($thirdUuids);

    expect($all)->toHaveCount(5)
        ->and($all->unique())->toHaveCount(5);
});

test('the last page reports a null next cursor', function (): void {
    publishedPost($this->organization, $this->author, $this->spot, '2026-07-09 09:00:00');

    actingAsFeedUser($this->viewer);
    $this->getJson('/api/v1/feed?limit=10', orgHeader($this->organization))
        ->assertOk()
        ->assertJsonPath('meta.next_cursor', null);
});

test('the feed limit is capped', function (): void {
    actingAsFeedUser($this->viewer);
    $this->getJson('/api/v1/feed?limit=500', orgHeader($this->organization))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['limit']);
});

// ---------------------------------------------------------------------------
// Permission
// ---------------------------------------------------------------------------

test('the feed rejects an unauthenticated caller', function (): void {
    $this->getJson('/api/v1/feed', orgHeader($this->organization))->assertUnauthorized();
});

test('the feed is denied without the posts view permission', function (): void {
    actingAsFeedUser($this->createUserWithPermissions($this->organization, []));

    $this->getJson('/api/v1/feed', orgHeader($this->organization))->assertForbidden();
});
