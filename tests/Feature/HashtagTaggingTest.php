<?php

declare(strict_types=1);

use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Stourify\Enums\PostVisibility;
use Modules\Stourify\Models\Post;
use Modules\Stourify\Models\Spot;
use Modules\Stourify\Support\Hashtags\HashtagParser;
use Tests\Traits\InteractsWithTestSetup;

uses(RefreshDatabase::class, InteractsWithTestSetup::class);

/**
 * @var list<string>
 */
const HASHTAG_PERMISSIONS = [
    'stourify.posts.view',
    'stourify.posts.create',
    'stourify.posts.update',
    'stourify.posts.delete',
    'stourify.spots.view',
    'stourify.spots.create',
    'stourify.spots.update',
    'stourify.spots.delete',
];

beforeEach(function (): void {
    $this->organization = $this->setUpTestOrganization();
    $this->seedPermissions(HASHTAG_PERMISSIONS);

    $this->author = $this->createUserWithPermissions($this->organization, HASHTAG_PERMISSIONS);
    $this->other = $this->createUserWithPermissions($this->organization, HASHTAG_PERMISSIONS);
});

/**
 * The slugs a record is carrying, sorted so an assertion never depends on the
 * order rows came back in.
 *
 * @return list<string>
 */
function hashtagSlugsOf(Post|Spot $record): array
{
    return $record->tags()->pluck('slug')->sort()->values()->all();
}

// ---------------------------------------------------------------------------
// Attaching on the ordinary API road
// ---------------------------------------------------------------------------

test('a post created with two hashtags in its caption carries both as tags', function (): void {
    Sanctum::actingAs($this->author);

    $uuid = $this->postJson('/api/v1/posts', [
        'caption' => 'great noodles #streetfood #Hanoi',
        'visibility' => PostVisibility::Public->value,
        'publish' => true,
    ], orgHeader($this->organization))->assertCreated()->json('data.uuid');

    $post = Post::query()->where('uuid', $uuid)->firstOrFail();

    expect(hashtagSlugsOf($post))->toBe(['hanoi', 'streetfood']);
});

test('a second post writing the same word in a different case joins the same tag row', function (): void {
    Sanctum::actingAs($this->author);

    $this->postJson('/api/v1/posts', [
        'caption' => 'first one #StreetFood',
        'visibility' => PostVisibility::Public->value,
    ], orgHeader($this->organization))->assertCreated();

    Sanctum::actingAs($this->other);

    $this->postJson('/api/v1/posts', [
        'caption' => 'second one #streetfood',
        'visibility' => PostVisibility::Public->value,
    ], orgHeader($this->organization))->assertCreated();

    $tags = Tag::query()->where('slug', 'streetfood')->get();

    expect($tags)->toHaveCount(1)
        // The first spelling wins, so the tag reads back the way it was
        // first written rather than however the last author typed it.
        ->and($tags->first()->name)->toBe('StreetFood')
        ->and($tags->first()->type)->toBe(HashtagParser::TAG_TYPE)
        ->and(DB::table('taggables')->where('tag_id', $tags->first()->id)->count())->toBe(2);
});

test('editing a caption adds the hashtags it gained and removes the ones it lost', function (): void {
    Sanctum::actingAs($this->author);

    $uuid = $this->postJson('/api/v1/posts', [
        'caption' => 'great noodles #streetfood #Hanoi',
        'visibility' => PostVisibility::Public->value,
    ], orgHeader($this->organization))->assertCreated()->json('data.uuid');

    $this->patchJson("/api/v1/posts/{$uuid}", [
        'caption' => 'great noodles #streetfood #pho',
    ], orgHeader($this->organization))->assertOk();

    $post = Post::query()->where('uuid', $uuid)->firstOrFail();

    expect(hashtagSlugsOf($post))->toBe(['pho', 'streetfood']);
});

test('clearing the caption removes every hashtag from the post', function (): void {
    Sanctum::actingAs($this->author);

    $uuid = $this->postJson('/api/v1/posts', [
        'caption' => 'noodles #streetfood',
        'visibility' => PostVisibility::Public->value,
    ], orgHeader($this->organization))->assertCreated()->json('data.uuid');

    $this->patchJson("/api/v1/posts/{$uuid}", [
        'caption' => 'just noodles',
    ], orgHeader($this->organization))->assertOk();

    expect(hashtagSlugsOf(Post::query()->where('uuid', $uuid)->firstOrFail()))->toBe([]);
});

/**
 * The reason attaching computes a difference instead of calling `sync()`.
 * A tag an administrator put on a post from the admin panel is somebody
 * else's data, and an author fixing a typo must not destroy it.
 */
test('a tag an administrator attached survives the author editing the caption', function (): void {
    Sanctum::actingAs($this->author);

    $uuid = $this->postJson('/api/v1/posts', [
        'caption' => 'noodles #streetfood',
        'visibility' => PostVisibility::Public->value,
    ], orgHeader($this->organization))->assertCreated()->json('data.uuid');

    $post = Post::query()->where('uuid', $uuid)->firstOrFail();

    $curated = Tag::query()->create([
        'organization_id' => $this->organization->id,
        'name' => 'Editor pick',
        'slug' => 'editor-pick',
        'type' => null,
    ]);
    $post->tags()->attach($curated->id);

    $this->patchJson("/api/v1/posts/{$uuid}", [
        'caption' => 'noodles #pho',
    ], orgHeader($this->organization))->assertOk();

    expect(hashtagSlugsOf($post->fresh()))->toBe(['editor-pick', 'pho']);
});

test('a post with no hashtag in its caption carries no tags and mints none', function (): void {
    Sanctum::actingAs($this->author);

    $this->postJson('/api/v1/posts', [
        'caption' => 'just some noodles',
        'visibility' => PostVisibility::Public->value,
    ], orgHeader($this->organization))->assertCreated();

    expect(Tag::query()->count())->toBe(0);
});

test('soft deleting a post leaves its tag attachments in place', function (): void {
    Sanctum::actingAs($this->author);

    $uuid = $this->postJson('/api/v1/posts', [
        'caption' => 'noodles #streetfood',
        'visibility' => PostVisibility::Public->value,
    ], orgHeader($this->organization))->assertCreated()->json('data.uuid');

    $post = Post::query()->where('uuid', $uuid)->firstOrFail();

    $this->deleteJson("/api/v1/posts/{$uuid}", [], orgHeader($this->organization))->assertOk();

    expect(DB::table('taggables')
        ->where('taggable_type', $post->getMorphClass())
        ->where('taggable_id', $post->id)
        ->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// Spots — including the road a controller-side parse would have missed
// ---------------------------------------------------------------------------

test('a spot created through the API carries the hashtags in its description', function (): void {
    Sanctum::actingAs($this->author);

    $uuid = $this->postJson('/api/v1/spots', [
        'title' => 'Cliff Viewpoint',
        'description' => 'best light at dawn #viewpoint #sunrise',
        'latitude' => 6.1,
        'longitude' => 125.1,
    ], orgHeader($this->organization))->assertCreated()->json('data.uuid');

    $spot = Spot::query()->where('uuid', $uuid)->firstOrFail();

    expect(hashtagSlugsOf($spot))->toBe(['sunrise', 'viewpoint']);
});

/**
 * The assertion that earns the whole design.
 *
 * A spot written with no signal never reaches `SpotApiController` — the app
 * sends it later through the offline sync push. A parse bolted onto the
 * controller would leave this red forever, and nothing in the product would
 * say so.
 */
test('a spot pushed through offline sync arrives carrying its hashtags', function (): void {
    Sanctum::actingAs($this->author);

    $uuid = (string) Str::uuid();

    $this->postJson('/api/v1/stourify/sync/push', [
        'sto_spots' => [
            'created' => [[
                'uuid' => $uuid,
                'title' => 'Tunnel Cove',
                'description' => 'found it with no signal #viewpoint',
                'latitude' => 6.1,
                'longitude' => 125.1,
            ]],
        ],
    ], orgHeader($this->organization))->assertOk();

    $spot = Spot::query()->where('uuid', $uuid)->firstOrFail();

    expect(hashtagSlugsOf($spot))->toBe(['viewpoint']);
});

// ---------------------------------------------------------------------------
// What the API shows
// ---------------------------------------------------------------------------

test('a post response carries its tags', function (): void {
    Sanctum::actingAs($this->author);

    $uuid = $this->postJson('/api/v1/posts', [
        'caption' => 'noodles #StreetFood',
        'visibility' => PostVisibility::Public->value,
        'publish' => true,
    ], orgHeader($this->organization))->assertCreated()->json('data.uuid');

    $tags = $this->getJson("/api/v1/posts/{$uuid}", orgHeader($this->organization))
        ->assertOk()
        ->json('data.tags');

    expect($tags)->toBe([['slug' => 'streetfood', 'name' => 'StreetFood']]);
});

test('a spot response carries its tags', function (): void {
    Sanctum::actingAs($this->author);

    $uuid = $this->postJson('/api/v1/spots', [
        'title' => 'Cliff Viewpoint',
        'description' => 'dawn light #Viewpoint',
        'latitude' => 6.1,
        'longitude' => 125.1,
    ], orgHeader($this->organization))->assertCreated()->json('data.uuid');

    $tags = $this->getJson("/api/v1/spots/{$uuid}", orgHeader($this->organization))
        ->assertOk()
        ->json('data.tags');

    expect($tags)->toBe([['slug' => 'viewpoint', 'name' => 'Viewpoint']]);
});

/**
 * The guard against the shape STOURIFY-153 complains about: a listing whose
 * cost grows with the number of rows on the page. Ten posts must cost the
 * same number of queries as two, or `tags` was resolved per row.
 */
test('listing posts costs the same number of queries however many carry tags', function (): void {
    Sanctum::actingAs($this->author);

    $makePosts = function (int $count): void {
        foreach (range(1, $count) as $n) {
            Post::factory()->for($this->organization)->create([
                'user_id' => $this->author->id,
                'visibility' => PostVisibility::Public->value,
                'published_at' => now(),
                'caption' => "one #tag{$n} #shared",
            ]);
        }
    };

    $countQueries = function () {
        // A fresh request each time: the listing is cached per query string,
        // so two identical requests would measure the cache, not the query.
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/v1/posts?per_page=50&_='.Str::random(8), orgHeader($this->organization))->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $makePosts(2);
    $small = $countQueries();

    $makePosts(8);
    $large = $countQueries();

    // Not equality: the first request through a fresh application warms
    // caches the second one then reads, so the counts legitimately differ by
    // a small constant in the *cheaper* direction. The claim being pinned is
    // the one that matters — five times the rows must not cost more queries.
    expect($large)->toBeLessThanOrEqual($small);
});
