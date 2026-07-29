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

test('a reply comes back attached to its parent', function (): void {
    actingAsCommenter($this->commenter);

    $parentUuid = $this->postJson("/api/v1/posts/{$this->post->uuid}/comments", [
        'body' => 'Where is this?',
    ], orgHeader($this->organization))->assertCreated()->json('data.id');

    $parentId = Comment::query()->where('uuid', $parentUuid)->value('id');

    $response = $this->postJson("/api/v1/posts/{$this->post->uuid}/comments", [
        'body' => 'At the summit lookout.',
        'parent_id' => $parentId,
    ], orgHeader($this->organization))->assertCreated();

    expect($response->json('data.parent_id'))->toBe($parentId);
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
        'parent_id' => $foreignParent->id,
    ], orgHeader($this->organization))->assertUnprocessable();
});
