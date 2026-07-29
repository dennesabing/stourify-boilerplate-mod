<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Stourify\Enums\FollowStatus;
use Modules\Stourify\Enums\PostVisibility;
use Modules\Stourify\Enums\SpotStatus;
use Modules\Stourify\Models\ExplorerProfile;
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
// Author identity — a feed row's header must not need a per-post round trip
// ---------------------------------------------------------------------------

test('a feed post carries its author\'s identity for the row header', function (): void {
    ExplorerProfile::factory()->for($this->organization)->create([
        'user_id' => $this->author->id,
        'username' => 'wanderer',
    ]);
    publishedPost($this->organization, $this->author, $this->spot, '2026-07-09 09:00:00');

    actingAsFeedUser($this->viewer);
    $post = $this->getJson('/api/v1/feed', orgHeader($this->organization))->assertOk()->json('data.0');

    expect($post['author'])->not->toBeNull()
        ->and($post['author']['uuid'])->toBe($this->author->uuid)
        ->and($post['author']['name'])->toBe($this->author->name)
        ->and($post['author']['username'])->toBe('wanderer')
        // Backward compatibility: mobile already consumes author_uuid directly.
        ->and($post['author_uuid'])->toBe($this->author->uuid);
});

test('a feed post reports a null username when the author has no ExplorerProfile yet', function (): void {
    publishedPost($this->organization, $this->author, $this->spot, '2026-07-09 09:00:00');

    actingAsFeedUser($this->viewer);
    $post = $this->getJson('/api/v1/feed', orgHeader($this->organization))->assertOk()->json('data.0');

    expect($post['author']['username'])->toBeNull()
        ->and($post['author']['uuid'])->toBe($this->author->uuid);
});

test('a feed post carries its photos, and a photo-less post carries an empty array, never null', function (): void {
    Storage::fake('media');

    $withPhoto = publishedPost($this->organization, $this->author, $this->spot, '2026-07-09 09:00:00');
    $withPhoto->addMedia(UploadedFile::fake()->image('photo.jpg', 200, 200))->toMediaCollection('attachments');
    $withoutPhoto = publishedPost($this->organization, $this->author, $this->spot, '2026-07-08 09:00:00');

    actingAsFeedUser($this->viewer);
    $posts = collect($this->getJson('/api/v1/feed', orgHeader($this->organization))->assertOk()->json('data'))
        ->keyBy('uuid');

    expect($posts[$withPhoto->uuid]['media'])->toHaveCount(1)
        ->and($posts[$withPhoto->uuid]['media'][0]['url'])->not->toBeNull()
        ->and($posts[$withoutPhoto->uuid]['media'])->toBe([]);
});

test('the feed page query count does not grow with the number of posts, only with the number of distinct authors (no N+1)', function (): void {
    Storage::fake('media');

    // Five distinct authors, each with their own ExplorerProfile and avatar —
    // the identity data `PostResource::author` renders. The author *set* is
    // fixed across both measurements below; only the post count changes. That
    // isolates the invariant Gap 1 protects — cost must scale with distinct
    // authors on the page, never with post count — from unrelated per-author
    // overhead (e.g. media URL resolution) that would otherwise contaminate a
    // baseline built from a different, smaller author set.
    $authors = collect(range(1, 5))->map(function (int $i): User {
        $author = $this->createUserWithPermissions($this->organization, FEED_PERMISSIONS);
        ExplorerProfile::factory()->for($this->organization)->create([
            'user_id' => $author->id, 'username' => "explorer{$i}",
        ]);
        $author->addMedia(UploadedFile::fake()->image("avatar{$i}.jpg", 100, 100))->toMediaCollection('avatar');

        return $author;
    });

    $sharedSpot = Spot::factory()->for($this->organization)->create([
        'user_id' => $authors->first()->id, 'status' => SpotStatus::Published,
    ]);

    // Round 1: one post per author (5 posts, 5 authors).
    $authors->each(fn (User $author, int $idx) => publishedPost(
        $this->organization, $author, $sharedSpot, sprintf('2026-06-%02d 09:00:00', $idx + 1),
    ));

    actingAsFeedUser($this->viewer);

    DB::enableQueryLog();
    $this->getJson('/api/v1/feed?limit=25', orgHeader($this->organization))
        ->assertOk()->assertJsonCount(5, 'data');
    $queriesFivePosts = count(DB::getQueryLog());
    DB::flushQueryLog();
    DB::disableQueryLog();

    // Round 2: three more posts per author (20 posts total), same 5 authors.
    $authors->each(function (User $author, int $idx) use ($sharedSpot): void {
        foreach (range(1, 3) as $j) {
            publishedPost(
                $this->organization, $author, $sharedSpot,
                sprintf('2026-07-%02d %02d:00:00', $idx + 1, $j),
            );
        }
    });

    DB::enableQueryLog();
    $this->getJson('/api/v1/feed?limit=25', orgHeader($this->organization))
        ->assertOk()->assertJsonCount(20, 'data');
    $queriesTwentyPosts = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Same 5 authors, 4x the posts: query count must stay flat, not scale
    // with the row count — that is the N+1 Gap 1 exists to close.
    expect($queriesTwentyPosts)->toBeLessThanOrEqual($queriesFivePosts + 2,
        "Expected no N+1: {$queriesFivePosts} queries for 5 posts, {$queriesTwentyPosts} for 20 posts — same 5 authors."
    );
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
