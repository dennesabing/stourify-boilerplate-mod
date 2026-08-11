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
use Spatie\MediaLibrary\Conversions\ConversionCollection;
use Spatie\MediaLibrary\Conversions\FileManipulator;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
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
 * media-library's default conversion pipeline dispatches the conversion job
 * `->afterCommit()` (config `queue_conversions_after_database_commit`).
 * `RefreshDatabase` wraps every test in a transaction that is rolled back,
 * never committed, so that callback never fires and conversions never run —
 * not a timing issue, a structural one. This runs the library's own
 * `FileManipulator::performConversions()` directly, synchronously, bypassing
 * the queue dispatch entirely rather than asserting on an artifact that
 * would never exist under this trait.
 */
function generatePostConversionsSynchronously(Media $media): void
{
    $conversions = ConversionCollection::createForMedia($media)->getConversions($media->collection_name);

    app(FileManipulator::class)->performConversions($conversions, $media);
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

test('a post photo carries a thumb_url distinct from the original url', function (): void {
    Storage::fake('media');

    $post = Post::factory()->for($this->organization)->create([
        'user_id' => $this->author->id, 'spot_id' => $this->spot->id, 'published_at' => now(),
    ]);
    $media = $post->addMedia(UploadedFile::fake()->image('photo.jpg', 800, 800))
        ->toMediaCollection('attachments');

    generatePostConversionsSynchronously($media);

    // See the equivalent Spot test helper for why RefreshDatabase makes the
    // default afterCommit() dispatch non-deterministic and this bypass
    // is necessary.
    expect($media->hasGeneratedConversion('thumb'))->toBeTrue()
        ->and($media->hasGeneratedConversion('medium'))->toBeTrue();

    actingAsPoster($this->author);

    $item = $this->getJson("/api/v1/posts/{$post->uuid}", orgHeader($this->organization))
        ->assertOk()
        ->json('data.media.0');

    expect($item['thumb_url'])->not->toBeNull()
        ->and($item['thumb_url'])->not->toBe($item['url']);
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
// Listing one explorer's posts — the other-user profile grid (STOURIFY-35)
// ---------------------------------------------------------------------------

test('user_uuid narrows the list to that explorer\'s posts', function (): void {
    $mine = Post::factory()->for($this->organization)->create([
        'user_id' => $this->viewer->id, 'spot_id' => $this->spot->id,
        'visibility' => PostVisibility::Public, 'published_at' => now(),
    ]);
    $theirs = Post::factory()->for($this->organization)->create([
        'user_id' => $this->author->id, 'spot_id' => $this->spot->id,
        'visibility' => PostVisibility::Public, 'published_at' => now(),
    ]);

    actingAsPoster($this->viewer);
    $uuids = collect($this->getJson(
        "/api/v1/posts?user_uuid={$this->author->uuid}", orgHeader($this->organization)
    )->assertOk()->json('data'))->pluck('uuid');

    expect($uuids)->toContain($theirs->uuid)->not->toContain($mine->uuid);
});

test('user_uuid does not widen visibility — a hidden post stays hidden', function (): void {
    // The filter narrows an already-scoped query; it must never become a way
    // to read someone's followers-only or unpublished work.
    $restricted = Post::factory()->for($this->organization)->create([
        'user_id' => $this->author->id, 'spot_id' => $this->spot->id,
        'visibility' => PostVisibility::Followers, 'published_at' => now(),
    ]);
    $unpublished = Post::factory()->for($this->organization)->create([
        'user_id' => $this->author->id, 'spot_id' => $this->spot->id,
        'visibility' => PostVisibility::Public, 'published_at' => null,
    ]);
    $visible = Post::factory()->for($this->organization)->create([
        'user_id' => $this->author->id, 'spot_id' => $this->spot->id,
        'visibility' => PostVisibility::Public, 'published_at' => now(),
    ]);

    actingAsPoster($this->viewer);
    $uuids = collect($this->getJson(
        "/api/v1/posts?user_uuid={$this->author->uuid}", orgHeader($this->organization)
    )->assertOk()->json('data'))->pluck('uuid');

    expect($uuids)
        ->toContain($visible->uuid)
        ->not->toContain($restricted->uuid)
        ->not->toContain($unpublished->uuid);
});

test('an unknown user_uuid is rejected rather than silently listing everything', function (): void {
    actingAsPoster($this->viewer);

    $this->getJson('/api/v1/posts?user_uuid=not-a-uuid', orgHeader($this->organization))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['user_uuid']);
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

test('creating a post is denied without the create permission, and writes nothing', function (): void {
    actingAsPoster($this->createUserWithPermissions($this->organization, ['stourify.posts.view']));

    $before = Post::query()->count();

    $this->postJson('/api/v1/posts', [
        'spot_uuid' => $this->spot->uuid,
        'caption' => 'Should never exist.',
    ], orgHeader($this->organization))->assertForbidden();

    expect(Post::query()->count())->toBe($before);
});

/**
 * The ordering half of STOURIFY-23, and the reason the gate belongs in the
 * FormRequest rather than only in CrudService. A request whose `authorize()`
 * returns true validates first, so a caller who may not create anything at all
 * was answered with 422 and a field-by-field description of the payload the
 * server wanted — a shape they have no business learning. Authorizing in the
 * FormRequest runs the check ahead of validation, so the answer is 403 whatever
 * the body contains.
 */
test('creating a post is denied before validation runs, so an invalid payload still returns 403', function (): void {
    actingAsPoster($this->createUserWithPermissions($this->organization, ['stourify.posts.view']));

    $this->postJson('/api/v1/posts', [
        'spot_uuid' => 'not-a-uuid',
        'visibility' => 'everyone',
    ], orgHeader($this->organization))->assertForbidden();
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
