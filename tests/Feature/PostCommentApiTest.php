<?php

declare(strict_types=1);

use App\Models\Comment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Stourify\Enums\PostVisibility;
use Modules\Stourify\Enums\SpotStatus;
use Modules\Stourify\Models\Post;
use Modules\Stourify\Models\Spot;
use Tests\Traits\InteractsWithTestSetup;

uses(RefreshDatabase::class, InteractsWithTestSetup::class);

/**
 * @var list<string>
 */
const COMMENTER_PERMISSIONS = [
    'stourify.posts.view',
    'stourify.posts.create',
    'posts.comments.view',
    'posts.comments.create',
];

beforeEach(function (): void {
    $this->organization = $this->setUpTestOrganization();
    $this->seedPermissions(COMMENTER_PERMISSIONS);

    $this->author = $this->createUserWithPermissions($this->organization, COMMENTER_PERMISSIONS);
    $this->commenter = $this->createUserWithPermissions($this->organization, COMMENTER_PERMISSIONS);
    $this->stranger = $this->createUserWithPermissions($this->organization, COMMENTER_PERMISSIONS);

    $this->spot = Spot::factory()->for($this->organization)->create([
        'user_id' => $this->author->id,
        'status' => SpotStatus::Published,
    ]);

    $this->post = Post::factory()->for($this->organization)->create([
        'user_id' => $this->author->id,
        'spot_id' => $this->spot->id,
        'visibility' => PostVisibility::Public,
        'published_at' => now(),
    ]);
});

function actingAsCommenter(User $user): void
{
    Sanctum::actingAs($user);
}

// ---------------------------------------------------------------------------
// Listing
// ---------------------------------------------------------------------------

test('a post\'s comments are listed newest-first, paginated, with the commenter loaded', function (): void {
    actingAsCommenter($this->commenter);

    $older = Comment::factory()->for($this->organization)->create([
        'commentable_type' => Post::class,
        'commentable_id' => $this->post->id,
        'user_id' => $this->commenter->id,
        'body' => 'First!',
        'created_at' => now()->subMinutes(5),
    ]);

    $newer = Comment::factory()->for($this->organization)->create([
        'commentable_type' => Post::class,
        'commentable_id' => $this->post->id,
        'user_id' => $this->author->id,
        'body' => 'Nice spot.',
        'created_at' => now(),
    ]);

    $response = $this->getJson("/api/v1/posts/{$this->post->uuid}/comments", orgHeader($this->organization))
        ->assertOk();

    $ids = collect($response->json('data'))->pluck('id');

    expect($ids->first())->toBe($newer->uuid)
        ->and($ids->last())->toBe($older->uuid)
        ->and($response->json('data.0.user.id'))->toBe($this->author->uuid)
        ->and($response->json('meta'))->not->toBeNull();
});

test('a stranger who cannot view the post is forbidden from listing its comments', function (): void {
    $privatePost = Post::factory()->for($this->organization)->create([
        'user_id' => $this->author->id,
        'spot_id' => $this->spot->id,
        'visibility' => PostVisibility::Private,
        'published_at' => now(),
    ]);

    actingAsCommenter($this->stranger);

    $this->getJson("/api/v1/posts/{$privatePost->uuid}/comments", orgHeader($this->organization))
        ->assertForbidden();
});

test('someone who can see the post but holds no comment-view permission cannot list its thread', function (): void {
    // The second of the endpoint's two independent locks (STOURIFY-154). This
    // user can see the post perfectly well — they just may not read what people
    // wrote underneath it. Before that check existed, a post's thread was open
    // to anybody who could open the post, which made `posts.comments.view` a
    // permission nothing in the read path ever asked for.
    $postViewerOnly = $this->createUserWithPermissions($this->organization, [
        'stourify.posts.view',
    ]);

    actingAsCommenter($postViewerOnly);

    $this->getJson("/api/v1/posts/{$this->post->uuid}/comments", orgHeader($this->organization))
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// Creating
// ---------------------------------------------------------------------------

test('a comment is created on a post and returned', function (): void {
    actingAsCommenter($this->commenter);

    $response = $this->postJson("/api/v1/posts/{$this->post->uuid}/comments", [
        'body' => 'Great capture.',
    ], orgHeader($this->organization))
        ->assertCreated()
        ->assertJsonPath('data.body', 'Great capture.')
        ->assertJsonPath('data.user.id', $this->commenter->uuid);

    $this->assertDatabaseHas('comments', [
        'uuid' => $response->json('data.id'),
        'commentable_type' => Post::class,
        'commentable_id' => $this->post->id,
        'user_id' => $this->commenter->id,
        'body' => 'Great capture.',
    ]);
});

test('a stranger who cannot view the post is forbidden from commenting on it', function (): void {
    $privatePost = Post::factory()->for($this->organization)->create([
        'user_id' => $this->author->id,
        'spot_id' => $this->spot->id,
        'visibility' => PostVisibility::Private,
        'published_at' => now(),
    ]);

    actingAsCommenter($this->stranger);

    $this->postJson("/api/v1/posts/{$privatePost->uuid}/comments", [
        'body' => 'Can I see this?',
    ], orgHeader($this->organization))
        ->assertForbidden();

    $this->assertDatabaseMissing('comments', [
        'commentable_type' => Post::class,
        'commentable_id' => $privatePost->id,
    ]);
});

/**
 * The whole round trip, using only what a client can see.
 *
 * The old version of this test read the parent's uuid out of the response and
 * then went to the DATABASE for the numeric id it actually had to send, because
 * no response from this API has ever carried one. It passed while describing a
 * request no phone could make (STOURIFY-152). Now the uuid the first response
 * hands back is the uuid the second request sends, with nothing in between.
 */
test('a reply is created by the parent uuid and comes back carrying it', function (): void {
    actingAsCommenter($this->commenter);

    $parentUuid = $this->postJson("/api/v1/posts/{$this->post->uuid}/comments", [
        'body' => 'Where is this?',
    ], orgHeader($this->organization))->assertCreated()->json('data.id');

    $response = $this->postJson("/api/v1/posts/{$this->post->uuid}/comments", [
        'body' => 'At the summit lookout.',
        'parent_id' => $parentUuid,
    ], orgHeader($this->organization))->assertCreated();

    expect($response->json('data.parent_id'))->toBe($parentUuid);

    $reply = Comment::query()->where('uuid', $response->json('data.id'))->firstOrFail();
    expect($reply->parent_id)->toBe(Comment::query()->where('uuid', $parentUuid)->value('id'));
});

/**
 * The listing has to let the app join a reply to its parent without leaving the
 * payload — that join is exactly what draws the indentation.
 */
test('a listed reply points at a parent that is in the same payload', function (): void {
    actingAsCommenter($this->commenter);

    $parentUuid = $this->postJson("/api/v1/posts/{$this->post->uuid}/comments", [
        'body' => 'Where is this?',
    ], orgHeader($this->organization))->assertCreated()->json('data.id');

    $this->postJson("/api/v1/posts/{$this->post->uuid}/comments", [
        'body' => 'At the summit lookout.',
        'parent_id' => $parentUuid,
    ], orgHeader($this->organization))->assertCreated();

    $rows = $this->getJson("/api/v1/posts/{$this->post->uuid}/comments", orgHeader($this->organization))
        ->assertOk()
        ->json('data');

    $ids = array_column($rows, 'id');
    $parentIds = array_values(array_filter(array_column($rows, 'parent_id')));

    expect($parentIds)->toBe([$parentUuid])
        ->and($ids)->toContain($parentUuid);
});

test('a parent_id belonging to another post is rejected', function (): void {
    actingAsCommenter($this->commenter);

    $otherPost = Post::factory()->for($this->organization)->create([
        'user_id' => $this->author->id,
        'spot_id' => $this->spot->id,
        'visibility' => PostVisibility::Public,
        'published_at' => now(),
    ]);

    $foreignParent = Comment::factory()->for($this->organization)->create([
        'commentable_type' => Post::class,
        'commentable_id' => $otherPost->id,
        'user_id' => $this->author->id,
        'body' => 'On a different post.',
    ]);

    $this->postJson("/api/v1/posts/{$this->post->uuid}/comments", [
        'body' => 'Mismatched thread.',
        'parent_id' => $foreignParent->uuid,
    ], orgHeader($this->organization))->assertUnprocessable();
});
