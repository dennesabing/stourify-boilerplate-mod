<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Modules\Stourify\Enums\PostVisibility;
use Modules\Stourify\Enums\SpotStatus;
use Modules\Stourify\Models\Post;
use Modules\Stourify\Models\Review;
use Modules\Stourify\Models\Spot;
use Tests\Traits\InteractsWithTestSetup;

uses(RefreshDatabase::class, InteractsWithTestSetup::class);

/**
 * The reaction permissions an explorer needs to like a post and mark a review
 * helpful, plus the base view permissions the read paths require.
 *
 * @var list<string>
 */
const REACTION_PERMISSIONS = [
    'stourify.posts.view',
    'posts.reactions.view',
    'posts.reactions.create',
    'stourify.reviews.view',
    'reviews.reactions.view',
    'reviews.reactions.create',
];

beforeEach(function (): void {
    $this->organization = $this->setUpTestOrganization();
    $this->seedPermissions(REACTION_PERMISSIONS);

    $this->explorer = $this->createUserWithPermissions($this->organization, REACTION_PERMISSIONS);
    $this->spot = Spot::factory()->for($this->organization)->create([
        'user_id' => $this->explorer->id, 'status' => SpotStatus::Published,
    ]);
    $this->post = Post::factory()->for($this->organization)->create([
        'user_id' => $this->explorer->id, 'spot_id' => $this->spot->id,
        'visibility' => PostVisibility::Public, 'published_at' => now(),
    ]);
    $this->review = Review::factory()->for($this->organization)->create([
        'user_id' => $this->explorer->id, 'spot_id' => $this->spot->id,
    ]);
});

function actingAsLiker(User $user): void
{
    Sanctum::actingAs($user);
}

/**
 * Toggle the caller's reaction on a host through the platform's reaction endpoint.
 */
function react(object $test, string $morphType, string $uuid, string $type): TestResponse
{
    return $test->postJson('/api/v1/reactions', [
        'reactable_type' => $morphType,
        'reactable_uuid' => $uuid,
        'type' => $type,
    ], orgHeader($test->organization));
}

// ---------------------------------------------------------------------------
// Post likes
// ---------------------------------------------------------------------------

test('liking a post increments likes_count and sets is_liked', function (): void {
    actingAsLiker($this->explorer);

    react($this, 'stourify_post', $this->post->uuid, Post::LIKE_REACTION)
        ->assertOk()
        ->assertJsonPath('data.reacted', true)
        ->assertJsonPath('data.counts.like', 1);

    expect($this->post->fresh()->likes_count)->toBe(1);

    $this->getJson("/api/v1/posts/{$this->post->uuid}", orgHeader($this->organization))
        ->assertOk()
        ->assertJsonPath('data.likes_count', 1)
        ->assertJsonPath('data.is_liked', true);
});

test('unliking a post decrements likes_count and clears is_liked', function (): void {
    actingAsLiker($this->explorer);

    react($this, 'stourify_post', $this->post->uuid, Post::LIKE_REACTION)->assertOk();
    expect($this->post->fresh()->likes_count)->toBe(1);

    // The reaction endpoint toggles: liking again removes it.
    react($this, 'stourify_post', $this->post->uuid, Post::LIKE_REACTION)
        ->assertOk()
        ->assertJsonPath('data.reacted', false);

    expect($this->post->fresh()->likes_count)->toBe(0);

    $this->getJson("/api/v1/posts/{$this->post->uuid}", orgHeader($this->organization))
        ->assertOk()
        ->assertJsonPath('data.is_liked', false);
});

test('likes_count reflects multiple explorers', function (): void {
    $other = $this->createUserWithPermissions($this->organization, REACTION_PERMISSIONS);

    actingAsLiker($this->explorer);
    react($this, 'stourify_post', $this->post->uuid, Post::LIKE_REACTION)->assertOk();

    actingAsLiker($other);
    react($this, 'stourify_post', $this->post->uuid, Post::LIKE_REACTION)->assertOk();

    expect($this->post->fresh()->likes_count)->toBe(2);
});

test('is_liked is per-viewer, not global', function (): void {
    $other = $this->createUserWithPermissions($this->organization, REACTION_PERMISSIONS);

    actingAsLiker($this->explorer);
    react($this, 'stourify_post', $this->post->uuid, Post::LIKE_REACTION)->assertOk();

    // The other explorer has not liked it — likes_count is 1, but is_liked is
    // false for them.
    actingAsLiker($other);
    $this->getJson("/api/v1/posts/{$this->post->uuid}", orgHeader($this->organization))
        ->assertOk()
        ->assertJsonPath('data.likes_count', 1)
        ->assertJsonPath('data.is_liked', false);
});

test('the feed carries likes_count and the viewer is_liked flag', function (): void {
    actingAsLiker($this->explorer);
    react($this, 'stourify_post', $this->post->uuid, Post::LIKE_REACTION)->assertOk();

    $data = $this->getJson('/api/v1/feed', orgHeader($this->organization))->assertOk()->json('data');
    $mine = collect($data)->firstWhere('uuid', $this->post->uuid);

    expect($mine['likes_count'])->toBe(1)->and($mine['is_liked'])->toBeTrue();
});

// ---------------------------------------------------------------------------
// Review helpful votes
// ---------------------------------------------------------------------------

test('marking a review helpful increments helpful_count and sets marked_helpful', function (): void {
    actingAsLiker($this->explorer);

    react($this, 'stourify_review', $this->review->uuid, Review::HELPFUL_REACTION)
        ->assertOk()
        ->assertJsonPath('data.counts.helpful', 1);

    expect($this->review->fresh()->helpful_count)->toBe(1);

    $this->getJson("/api/v1/reviews/{$this->review->uuid}", orgHeader($this->organization))
        ->assertOk()
        ->assertJsonPath('data.helpful_count', 1)
        ->assertJsonPath('data.marked_helpful', true);
});

test('un-marking a review helpful decrements the count', function (): void {
    actingAsLiker($this->explorer);

    react($this, 'stourify_review', $this->review->uuid, Review::HELPFUL_REACTION)->assertOk();
    expect($this->review->fresh()->helpful_count)->toBe(1);

    react($this, 'stourify_review', $this->review->uuid, Review::HELPFUL_REACTION)->assertOk();
    expect($this->review->fresh()->helpful_count)->toBe(0);
});

// ---------------------------------------------------------------------------
// Type constraints — a post is not "love"-able, a review is not "like"-able
// ---------------------------------------------------------------------------

test('a post accepts only the like reaction', function (): void {
    actingAsLiker($this->explorer);

    react($this, 'stourify_post', $this->post->uuid, 'love')
        ->assertStatus(422);

    expect($this->post->fresh()->likes_count)->toBe(0);
});

test('a review accepts only the helpful reaction', function (): void {
    actingAsLiker($this->explorer);

    react($this, 'stourify_review', $this->review->uuid, 'like')
        ->assertStatus(422);

    expect($this->review->fresh()->helpful_count)->toBe(0);
});

// ---------------------------------------------------------------------------
// The counter is recomputed, so it survives a direct reaction deletion
// ---------------------------------------------------------------------------

test('deleting a reaction directly still corrects the counter', function (): void {
    actingAsLiker($this->explorer);
    react($this, 'stourify_post', $this->post->uuid, Post::LIKE_REACTION)->assertOk();
    expect($this->post->fresh()->likes_count)->toBe(1);

    // A reaction removed outside the toggle path (moderation, cascade) still
    // drives the observer, because the count is recomputed rather than
    // decremented.
    $this->post->reactions()->where('user_id', $this->explorer->id)->first()->delete();

    expect($this->post->fresh()->likes_count)->toBe(0);
});
