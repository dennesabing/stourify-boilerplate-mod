<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
const POSTER_PERMISSIONS = [
    'stourify.posts.view',
    'stourify.posts.create',
    'stourify.posts.update',
    'stourify.posts.delete',
];

beforeEach(function (): void {
    $this->organization = $this->setUpTestOrganization();
    $this->seedPermissions([...POSTER_PERMISSIONS, 'stourify.posts.manage']);

    $this->author = $this->createUserWithPermissions($this->organization, POSTER_PERMISSIONS);
    $this->viewer = $this->createUserWithPermissions($this->organization, POSTER_PERMISSIONS);

    $this->spot = Spot::factory()->for($this->organization)->create([
        'user_id' => $this->author->id,
        'status' => SpotStatus::Published,
    ]);
});

function actingAsPoster(User $user): void
{
    Sanctum::actingAs($user);
}

/**
 * Make $follower an accepted follower of $followee.
 */
function follow(User $follower, User $followee, mixed $organization, FollowStatus $status = FollowStatus::Active): void
{
    Follow::factory()->for($organization)->create([
        'follower_id' => $follower->id,
        'followee_id' => $followee->id,
        'status' => $status,
        'accepted_at' => $status === FollowStatus::Active ? now() : null,
    ]);
}

// ---------------------------------------------------------------------------
// CRUD and publishing
// ---------------------------------------------------------------------------

test('an explorer creates an unpublished post by default', function (): void {
    actingAsPoster($this->author);

    $this->postJson('/api/v1/posts', [
        'spot_uuid' => $this->spot->uuid,
        'caption' => 'Golden hour.',
    ], orgHeader($this->organization))
        ->assertCreated()
        ->assertJsonPath('data.is_published', false)
        ->assertJsonPath('data.published_at', null)
        ->assertJsonPath('data.visibility', PostVisibility::Public->value);
});

test('a post can be created already published', function (): void {
    actingAsPoster($this->author);

    $this->postJson('/api/v1/posts', [
        'spot_uuid' => $this->spot->uuid,
        'publish' => true,
    ], orgHeader($this->organization))
        ->assertCreated()
        ->assertJsonPath('data.is_published', true);
});

// ---------------------------------------------------------------------------
// `is_liked` — must be present (not merely absent) on every write response
// ---------------------------------------------------------------------------

test('is_liked is present, not absent, on the create response', function (): void {
    actingAsPoster($this->author);

    $response = $this->postJson('/api/v1/posts', [
        'spot_uuid' => $this->spot->uuid,
        'publish' => true,
    ], orgHeader($this->organization))->assertCreated();

    expect($response->json('data'))->toHaveKey('is_liked')
        ->and($response->json('data.is_liked'))->toBeFalse();
});

test('is_liked is present, not absent, on the update response', function (): void {
    actingAsPoster($this->author);
    $post = Post::factory()->for($this->organization)->create([
        'user_id' => $this->author->id, 'spot_id' => $this->spot->id, 'published_at' => now(),
    ]);

    $response = $this->patchJson("/api/v1/posts/{$post->uuid}", [
        'caption' => 'Edited caption',
    ], orgHeader($this->organization))->assertOk();

    expect($response->json('data'))->toHaveKey('is_liked')
        ->and($response->json('data.is_liked'))->toBeFalse();
});

test('is_liked is present, not absent, on the publish response', function (): void {
    actingAsPoster($this->author);
    $post = Post::factory()->for($this->organization)->create([
        'user_id' => $this->author->id, 'spot_id' => $this->spot->id, 'published_at' => null,
    ]);

    $response = $this->postJson("/api/v1/posts/{$post->uuid}/publish", [], orgHeader($this->organization))
        ->assertOk();

    expect($response->json('data'))->toHaveKey('is_liked')
        ->and($response->json('data.is_liked'))->toBeFalse();
});

test('the client cannot dictate published_at', function (): void {
    actingAsPoster($this->author);

    $uuid = $this->postJson('/api/v1/posts', [
        'spot_uuid' => $this->spot->uuid,
        'publish' => true,
        'published_at' => '2099-01-01T00:00:00Z',
    ], orgHeader($this->organization))->assertCreated()->json('data.uuid');

    expect(Post::query()->where('uuid', $uuid)->value('published_at')->year)
        ->toBe((int) now()->year);
});

test('publishing is idempotent and does not move the post up the feed', function (): void {
    actingAsPoster($this->author);
    $post = Post::factory()->for($this->organization)->create([
        'user_id' => $this->author->id, 'spot_id' => $this->spot->id, 'published_at' => null,
    ]);

    $this->postJson("/api/v1/posts/{$post->uuid}/publish", [], orgHeader($this->organization))
        ->assertOk()
        ->assertJsonPath('data.is_published', true);

    $firstPublishedAt = $post->fresh()->published_at;

    $this->postJson("/api/v1/posts/{$post->uuid}/publish", [], orgHeader($this->organization))->assertOk();

    expect($post->fresh()->published_at->equalTo($firstPublishedAt))->toBeTrue();
});

test('a post is updated and deleted by its author', function (): void {
    actingAsPoster($this->author);
    $post = Post::factory()->for($this->organization)->create([
        'user_id' => $this->author->id, 'spot_id' => $this->spot->id, 'published_at' => now(),
    ]);

    $this->patchJson("/api/v1/posts/{$post->uuid}", [
        'caption' => 'Edited caption',
        'visibility' => PostVisibility::Private->value,
    ], orgHeader($this->organization))
        ->assertOk()
        ->assertJsonPath('data.caption', 'Edited caption')
        ->assertJsonPath('data.visibility', PostVisibility::Private->value);

    $this->deleteJson("/api/v1/posts/{$post->uuid}", [], orgHeader($this->organization))->assertOk();
    $this->assertSoftDeleted('sto_posts', ['id' => $post->id]);
});

// ---------------------------------------------------------------------------
// Media — the feed's photo source
// ---------------------------------------------------------------------------

test('a post with attached photos returns a media array with a uuid and a url per photo', function (): void {
    Storage::fake('media');

    $post = Post::factory()->for($this->organization)->create([
        'user_id' => $this->author->id, 'spot_id' => $this->spot->id, 'published_at' => now(),
    ]);
    $post->addMedia(UploadedFile::fake()->image('photo.jpg', 200, 200))->toMediaCollection('attachments');

    actingAsPoster($this->author);

    $media = $this->getJson("/api/v1/posts/{$post->uuid}", orgHeader($this->organization))
        ->assertOk()
        ->json('data.media');

    expect($media)->toHaveCount(1)
        ->and($media[0]['uuid'])->not->toBeNull()
        ->and($media[0]['url'])->not->toBeNull();
});

test('a post with no media returns an empty array, never null', function (): void {
    $post = Post::factory()->for($this->organization)->create([
        'user_id' => $this->author->id, 'spot_id' => $this->spot->id, 'published_at' => now(),
    ]);

    actingAsPoster($this->author);

    $this->getJson("/api/v1/posts/{$post->uuid}", orgHeader($this->organization))
        ->assertOk()
        ->assertJsonPath('data.media', []);
});

// ---------------------------------------------------------------------------
// Visibility — the privacy surface. Checked on both show and list.
// ---------------------------------------------------------------------------

test('an unpublished post is invisible to everyone but its author', function (): void {
    $post = Post::factory()->for($this->organization)->create([
        'user_id' => $this->author->id, 'spot_id' => $this->spot->id,
        'visibility' => PostVisibility::Public, 'published_at' => null,
    ]);

    actingAsPoster($this->viewer);
    $this->getJson("/api/v1/posts/{$post->uuid}", orgHeader($this->organization))->assertForbidden();
    expect(collect($this->getJson('/api/v1/posts', orgHeader($this->organization))->json('data'))->pluck('uuid'))
        ->not->toContain($post->uuid);

    actingAsPoster($this->author);
    $this->getJson("/api/v1/posts/{$post->uuid}", orgHeader($this->organization))->assertOk();
});

test('a private post is invisible to everyone but its author', function (): void {
    $post = Post::factory()->for($this->organization)->create([
        'user_id' => $this->author->id, 'spot_id' => $this->spot->id,
        'visibility' => PostVisibility::Private, 'published_at' => now(),
    ]);

    actingAsPoster($this->viewer);
    $this->getJson("/api/v1/posts/{$post->uuid}", orgHeader($this->organization))->assertForbidden();
    expect(collect($this->getJson('/api/v1/posts', orgHeader($this->organization))->json('data'))->pluck('uuid'))
        ->not->toContain($post->uuid);
});

test('a followers-only post is visible to an accepted follower', function (): void {
    follow($this->viewer, $this->author, $this->organization);

    $post = Post::factory()->for($this->organization)->create([
        'user_id' => $this->author->id, 'spot_id' => $this->spot->id,
        'visibility' => PostVisibility::Followers, 'published_at' => now(),
    ]);

    actingAsPoster($this->viewer);
    $this->getJson("/api/v1/posts/{$post->uuid}", orgHeader($this->organization))->assertOk();
    expect(collect($this->getJson('/api/v1/posts', orgHeader($this->organization))->json('data'))->pluck('uuid'))
        ->toContain($post->uuid);
});

test('a followers-only post is hidden from a non-follower', function (): void {
    $post = Post::factory()->for($this->organization)->create([
        'user_id' => $this->author->id, 'spot_id' => $this->spot->id,
        'visibility' => PostVisibility::Followers, 'published_at' => now(),
    ]);

    actingAsPoster($this->viewer);
    $this->getJson("/api/v1/posts/{$post->uuid}", orgHeader($this->organization))->assertForbidden();
    expect(collect($this->getJson('/api/v1/posts', orgHeader($this->organization))->json('data'))->pluck('uuid'))
        ->not->toContain($post->uuid);
});

test('a pending follow request does not unlock followers-only content', function (): void {
    follow($this->viewer, $this->author, $this->organization, FollowStatus::Pending);

    $post = Post::factory()->for($this->organization)->create([
        'user_id' => $this->author->id, 'spot_id' => $this->spot->id,
        'visibility' => PostVisibility::Followers, 'published_at' => now(),
    ]);

    actingAsPoster($this->viewer);
    $this->getJson("/api/v1/posts/{$post->uuid}", orgHeader($this->organization))->assertForbidden();
    expect(collect($this->getJson('/api/v1/posts', orgHeader($this->organization))->json('data'))->pluck('uuid'))
        ->not->toContain($post->uuid);
});

test('the follow edge is directional — being followed does not grant access', function (): void {
    // The author follows the viewer, not the other way round.
    follow($this->author, $this->viewer, $this->organization);

    $post = Post::factory()->for($this->organization)->create([
        'user_id' => $this->author->id, 'spot_id' => $this->spot->id,
        'visibility' => PostVisibility::Followers, 'published_at' => now(),
    ]);

    actingAsPoster($this->viewer);
    $this->getJson("/api/v1/posts/{$post->uuid}", orgHeader($this->organization))->assertForbidden();
});

test('a moderator sees restricted and unpublished posts', function (): void {
    $moderator = $this->createUserWithPermissions(
        $this->organization, [...POSTER_PERMISSIONS, 'stourify.posts.manage'],
    );

    $private = Post::factory()->for($this->organization)->create([
        'user_id' => $this->author->id, 'spot_id' => $this->spot->id,
        'visibility' => PostVisibility::Private, 'published_at' => now(),
    ]);
    $unpublished = Post::factory()->for($this->organization)->create([
        'user_id' => $this->author->id, 'spot_id' => $this->spot->id, 'published_at' => null,
    ]);

    actingAsPoster($moderator);

    $uuids = collect($this->getJson('/api/v1/posts', orgHeader($this->organization))->json('data'))->pluck('uuid');
    expect($uuids)->toContain($private->uuid)->and($uuids)->toContain($unpublished->uuid);
});

test('the list and the record agree on visibility for every combination', function (
    string $visibility, bool $published, bool $follows, bool $expected
): void {
    if ($follows) {
        follow($this->viewer, $this->author, $this->organization);
    }

    $post = Post::factory()->for($this->organization)->create([
        'user_id' => $this->author->id,
        'spot_id' => $this->spot->id,
        'visibility' => $visibility,
        'published_at' => $published ? now() : null,
    ]);

    actingAsPoster($this->viewer);

    $inList = collect($this->getJson('/api/v1/posts', orgHeader($this->organization))->json('data'))
        ->pluck('uuid')
        ->contains($post->uuid);

    $showStatus = $this->getJson("/api/v1/posts/{$post->uuid}", orgHeader($this->organization))->status();

    // The two enforcement points must never disagree — if they do, whichever
    // is more permissive is a leak.
    expect($inList)->toBe($expected)
        ->and($showStatus === 200)->toBe($expected);
})->with([
    'public, published, stranger' => ['public', true, false, true],
    'public, unpublished, stranger' => ['public', false, false, false],
    'followers, published, follower' => ['followers', true, true, true],
    'followers, published, stranger' => ['followers', true, false, false],
    'followers, unpublished, follower' => ['followers', false, true, false],
    'private, published, follower' => ['private', true, true, false],
    'private, published, stranger' => ['private', false, false, false],
]);

// ---------------------------------------------------------------------------
// Permissions
// ---------------------------------------------------------------------------

test('post endpoints reject an unauthenticated caller', function (string $method, string $uri): void {
    $this->json($method, $uri, [], orgHeader($this->organization))->assertUnauthorized();
})->with([
    ['get', '/api/v1/posts'],
    ['post', '/api/v1/posts'],
]);

test('listing posts is denied without the view permission', function (): void {
    actingAsPoster($this->createUserWithPermissions($this->organization, []));

    $this->getJson('/api/v1/posts', orgHeader($this->organization))->assertForbidden();
});

test('one explorer cannot edit, publish or delete another explorer\'s post', function (): void {
    $post = Post::factory()->for($this->organization)->create([
        'user_id' => $this->author->id, 'spot_id' => $this->spot->id, 'published_at' => null,
    ]);

    actingAsPoster($this->viewer);

    $this->patchJson("/api/v1/posts/{$post->uuid}", ['caption' => 'Hijacked'], orgHeader($this->organization))
        ->assertForbidden();
    $this->postJson("/api/v1/posts/{$post->uuid}/publish", [], orgHeader($this->organization))
        ->assertForbidden();
    $this->deleteJson("/api/v1/posts/{$post->uuid}", [], orgHeader($this->organization))
        ->assertForbidden();

    expect($post->fresh()->published_at)->toBeNull();
});

// ---------------------------------------------------------------------------
// Validation
// ---------------------------------------------------------------------------

test('a post rejects an unknown spot and an invalid visibility', function (): void {
    actingAsPoster($this->author);

    $this->postJson('/api/v1/posts', [
        'spot_uuid' => '00000000-0000-4000-8000-000000000000',
    ], orgHeader($this->organization))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['spot_uuid']);

    $this->postJson('/api/v1/posts', [
        'spot_uuid' => $this->spot->uuid,
        'visibility' => 'everyone',
    ], orgHeader($this->organization))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['visibility']);
});

test('a post may exist without a spot', function (): void {
    actingAsPoster($this->author);

    $this->postJson('/api/v1/posts', [
        'caption' => 'Untethered thought.',
    ], orgHeader($this->organization))->assertCreated();
});

test('the list rejects an unsortable column', function (): void {
    actingAsPoster($this->author);

    $this->getJson('/api/v1/posts?sort=user_id', orgHeader($this->organization))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['sort']);
});
