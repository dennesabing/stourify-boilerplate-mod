<?php

declare(strict_types=1);

use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Stourify\Enums\PostVisibility;
use Modules\Stourify\Enums\SpotStatus;
use Modules\Stourify\Models\Post;
use Modules\Stourify\Models\Spot;
use Modules\Stourify\Support\Hashtags\HashtagParser;
use Tests\Traits\InteractsWithTestSetup;

uses(RefreshDatabase::class, InteractsWithTestSetup::class);

/**
 * @var list<string>
 */
const HASHTAG_BROWSING_PERMISSIONS = [
    'stourify.posts.view',
    'stourify.posts.create',
    'stourify.spots.view',
    'stourify.spots.create',
];

beforeEach(function (): void {
    $this->organization = $this->setUpTestOrganization();
    $this->seedPermissions(HASHTAG_BROWSING_PERMISSIONS);

    $this->viewer = $this->createUserWithPermissions($this->organization, HASHTAG_BROWSING_PERMISSIONS);
    $this->stranger = $this->createUserWithPermissions($this->organization, HASHTAG_BROWSING_PERMISSIONS);
});

/**
 * A post belonging to `$owner`, carrying whatever hashtags its caption holds.
 */
function taggedPost(object $test, object $owner, string $caption, bool $published = true): Post
{
    return Post::factory()->for($test->organization)->create([
        'user_id' => $owner->id,
        'visibility' => PostVisibility::Public->value,
        'published_at' => $published ? now() : null,
        'caption' => $caption,
    ]);
}

/**
 * A spot belonging to `$owner`, carrying whatever hashtags its description holds.
 */
function taggedSpot(object $test, object $owner, string $description, SpotStatus $status = SpotStatus::Published): Spot
{
    return Spot::factory()->for($test->organization)->create([
        'user_id' => $owner->id,
        'status' => $status->value,
        'description' => $description,
    ]);
}

// ---------------------------------------------------------------------------
// Filtering the listings — the audience rule must not widen
// ---------------------------------------------------------------------------

test('filtering posts by tag returns only the posts carrying it', function (): void {
    taggedPost($this, $this->viewer, 'noodles #streetfood');
    taggedPost($this, $this->viewer, 'a temple #history');

    Sanctum::actingAs($this->viewer);

    $captions = $this->getJson('/api/v1/posts?tag=streetfood', orgHeader($this->organization))
        ->assertOk()
        ->json('data.*.caption');

    expect($captions)->toBe(['noodles #streetfood']);
});

test('a tag filter cannot show a post the ordinary listing would have hidden', function (): void {
    // The assertion this card exists to protect. Both posts carry the tag; one
    // of them is somebody else's unpublished work. Before the filter is
    // validated, `tag` is a parameter Laravel discards -- so the listing
    // returns EVERYTHING while the caller believes it is filtered.
    taggedPost($this, $this->viewer, 'mine #streetfood');
    taggedPost($this, $this->stranger, 'not yours #streetfood', published: false);

    Sanctum::actingAs($this->viewer);

    $captions = $this->getJson('/api/v1/posts?tag=streetfood', orgHeader($this->organization))
        ->assertOk()
        ->json('data.*.caption');

    expect($captions)->toBe(['mine #streetfood']);
});

test('filtering spots by tag excludes another explorer draft exactly as the ordinary listing does', function (): void {
    taggedSpot($this, $this->viewer, 'dawn light #viewpoint');
    taggedSpot($this, $this->stranger, 'unfinished #viewpoint', SpotStatus::Draft);

    Sanctum::actingAs($this->viewer);

    $descriptions = $this->getJson('/api/v1/spots?tag=viewpoint', orgHeader($this->organization))
        ->assertOk()
        ->json('data.*.description');

    expect($descriptions)->toBe(['dawn light #viewpoint']);
});

test('a tag an administrator attached does not answer a hashtag filter', function (): void {
    // Same table, different vocabulary. A curated label filed under a slug an
    // explorer also types must not drag its content into a user-facing listing.
    $post = taggedPost($this, $this->viewer, 'no hashtag here');

    $curated = Tag::query()->create([
        'organization_id' => $this->organization->id,
        'name' => 'Streetfood',
        'slug' => 'streetfood',
        'type' => null,
    ]);
    $post->tags()->attach($curated->id);

    Sanctum::actingAs($this->viewer);

    $this->getJson('/api/v1/posts?tag=streetfood', orgHeader($this->organization))
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('a slug no tag uses returns an empty list rather than an error', function (): void {
    taggedPost($this, $this->viewer, 'noodles #streetfood');

    Sanctum::actingAs($this->viewer);

    $this->getJson('/api/v1/posts?tag=nothinguses this', orgHeader($this->organization))
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

// ---------------------------------------------------------------------------
// Looking a tag up — three states, not two
// ---------------------------------------------------------------------------

test('looking up a tag returns the spelling the first author used', function (): void {
    taggedPost($this, $this->viewer, 'noodles #StreetFood');

    Sanctum::actingAs($this->viewer);

    $this->getJson('/api/v1/discover/tags/streetfood', orgHeader($this->organization))
        ->assertOk()
        ->assertJsonPath('data.slug', 'streetfood')
        ->assertJsonPath('data.name', 'StreetFood');
});

test('looking up a slug no tag uses is a 404, distinguishable from a tag with nothing on it', function (): void {
    // The whole point of this endpoint. `404` means "no such word"; a `200`
    // beside an empty listing means "the word exists, nothing carries it".
    // STOURIFY-85 to STOURIFY-90 are a cluster of cards about a page that
    // could not tell those apart -- and neither could it tell either from a
    // request that simply failed.
    taggedPost($this, $this->viewer, 'noodles #streetfood');

    Sanctum::actingAs($this->viewer);

    $this->getJson('/api/v1/discover/tags/nosuchtag', orgHeader($this->organization))
        ->assertNotFound();
});

test('a tag whose only post was deleted still exists and still answers', function (): void {
    $post = taggedPost($this, $this->viewer, 'noodles #streetfood');
    $post->delete();

    Sanctum::actingAs($this->viewer);

    $this->getJson('/api/v1/discover/tags/streetfood', orgHeader($this->organization))
        ->assertOk()
        ->assertJsonPath('data.slug', 'streetfood');

    $this->getJson('/api/v1/posts?tag=streetfood', orgHeader($this->organization))
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('a tag an administrator created is not reachable through the hashtag lookup', function (): void {
    Tag::query()->create([
        'organization_id' => $this->organization->id,
        'name' => 'Internal',
        'slug' => 'internal',
        'type' => null,
    ]);

    Sanctum::actingAs($this->viewer);

    $this->getJson('/api/v1/discover/tags/internal', orgHeader($this->organization))
        ->assertNotFound();
});

test('the tag lookup refuses a caller who may not view spots', function (): void {
    taggedPost($this, $this->viewer, 'noodles #streetfood');

    $outsider = $this->createUserWithPermissions($this->organization, []);
    Sanctum::actingAs($outsider);

    $this->getJson('/api/v1/discover/tags/streetfood', orgHeader($this->organization))
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// Searching for the tags themselves — STOURIFY-25
// ---------------------------------------------------------------------------

test('discover search accepts type=tags instead of refusing it', function (): void {
    taggedPost($this, $this->viewer, 'noodles #streetfood');

    Sanctum::actingAs($this->viewer);

    $slugs = $this->getJson('/api/v1/discover/search?q=street&type=tags', orgHeader($this->organization))
        ->assertOk()
        ->json('data.*.slug');

    expect($slugs)->toContain('streetfood');
});

test('a tag an administrator created never appears in a user-facing tag search', function (): void {
    taggedPost($this, $this->viewer, 'noodles #streetfood');

    Tag::query()->create([
        'organization_id' => $this->organization->id,
        'name' => 'Street internal note',
        'slug' => 'street-internal-note',
        'type' => null,
    ]);

    Sanctum::actingAs($this->viewer);

    $slugs = $this->getJson('/api/v1/discover/search?q=street&type=tags', orgHeader($this->organization))
        ->assertOk()
        ->json('data.*.slug');

    expect($slugs)->toContain('streetfood')
        ->not->toContain('street-internal-note');
});

test('the combined preview carries a tags section so the app can discover the tab', function (): void {
    taggedPost($this, $this->viewer, 'noodles #streetfood');

    Sanctum::actingAs($this->viewer);

    $this->getJson('/api/v1/discover/search?q=street', orgHeader($this->organization))
        ->assertOk()
        ->assertJsonStructure(['data' => ['spots', 'cities', 'people', 'tags']]);
});

// ---------------------------------------------------------------------------
// The query counts — STOURIFY-153's complaint, pinned
// ---------------------------------------------------------------------------

test('a tag-filtered post listing costs no more queries as it returns more rows', function (): void {
    Sanctum::actingAs($this->viewer);

    $makePosts = function (int $count): void {
        foreach (range(1, $count) as $n) {
            taggedPost($this, $this->viewer, "one #own{$n} #shared");
        }
    };

    $countQueries = function (): int {
        // A fresh query string each time: the listing is cached per query
        // string, so a repeated request would measure the cache rather than
        // the query.
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/v1/posts?tag=shared&per_page=50&_='.Str::random(8), orgHeader($this->organization))->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $makePosts(2);
    $small = $countQueries();

    $makePosts(8);
    $large = $countQueries();

    // Not equality: the first request through a fresh application warms caches
    // the second then reads, so the counts legitimately differ by a small
    // constant in the CHEAPER direction. The claim worth pinning is that five
    // times the rows must not cost more queries.
    expect($large)->toBeLessThanOrEqual($small);
});

test('a tag-filtered spot listing costs no more queries as it returns more rows', function (): void {
    Sanctum::actingAs($this->viewer);

    $makeSpots = function (int $count): void {
        foreach (range(1, $count) as $n) {
            taggedSpot($this, $this->viewer, "one #own{$n} #shared");
        }
    };

    $countQueries = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/v1/spots?tag=shared&per_page=50&_='.Str::random(8), orgHeader($this->organization))->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $makeSpots(2);
    $small = $countQueries();

    $makeSpots(8);
    $large = $countQueries();

    expect($large)->toBeLessThanOrEqual($small);
});

// ---------------------------------------------------------------------------
// Nothing changes for a caller who did not ask for any of this
// ---------------------------------------------------------------------------

test('a listing with no tag parameter is unaffected', function (): void {
    taggedPost($this, $this->viewer, 'noodles #streetfood');
    taggedPost($this, $this->viewer, 'a temple #history');

    Sanctum::actingAs($this->viewer);

    $this->getJson('/api/v1/posts', orgHeader($this->organization))
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

test('the hashtag type constant is what both the filter and the lookup match on', function (): void {
    // A guard against the two drifting apart into two spellings of one word.
    expect(HashtagParser::TAG_TYPE)->toBe('hashtag');
});
