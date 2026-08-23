<?php

declare(strict_types=1);

use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Stourify\Enums\PostVisibility;
use Modules\Stourify\Enums\SpotStatus;
use Modules\Stourify\Models\Post;
use Modules\Stourify\Models\Spot;
use Modules\Stourify\Support\Hashtags\HashtagParser;
use Tests\Traits\InteractsWithTestSetup;

uses(RefreshDatabase::class, InteractsWithTestSetup::class);

/**
 * Taking one hashtag down, and what that must NOT reach (STOURIFY-174).
 *
 * A hashtag exists because somebody typed it. Nobody approves the word first,
 * which is exactly what makes the feature useful — and it means the first
 * person to type a slur mints a word everybody else can then attach to. Once
 * STOURIFY-172 and STOURIFY-173 gave every word a search entry and a page of
 * its own, that word stopped being one post you had to stumble across and
 * became a destination.
 *
 * So an administrator can hide the word. The comparison is a caretaker taking
 * one notice off a noticeboard: the notice comes down, and nothing else in the
 * room is disturbed.
 *
 * **The tests that matter most here are the ones asserting what did NOT
 * happen.** Hiding a word must not hide the people who used it — most of them
 * innocently, some of them criticising it. A suite that only checked the word
 * had disappeared would pass just as happily over a change that had quietly
 * taken a dozen posts out of the feed with it.
 */

/**
 * @var list<string>
 */
const SUPPRESSION_PERMISSIONS = [
    'stourify.posts.view',
    'stourify.posts.create',
    'stourify.spots.view',
    'stourify.spots.create',
];

beforeEach(function (): void {
    $this->organization = $this->setUpTestOrganization();
    $this->seedPermissions([...SUPPRESSION_PERMISSIONS, 'organizations.tags.delete']);

    $this->viewer = $this->createUserWithPermissions($this->organization, SUPPRESSION_PERMISSIONS);
    $this->moderator = $this->createUserWithPermissions(
        $this->organization,
        [...SUPPRESSION_PERMISSIONS, 'organizations.tags.delete'],
    );
});

/**
 * A published, public post carrying whatever hashtags its caption holds.
 */
function suppressionPost(object $test, string $caption): Post
{
    return Post::factory()->for($test->organization)->create([
        'user_id' => $test->viewer->id,
        'visibility' => PostVisibility::Public->value,
        'published_at' => now(),
        'caption' => $caption,
    ]);
}

/**
 * The hashtag row for one word, as the parse-on-write path created it.
 */
function hashtagRow(object $test, string $slug): Tag
{
    return Tag::query()
        ->where('organization_id', $test->organization->id)
        ->where('slug', $slug)
        ->where('type', HashtagParser::TAG_TYPE)
        ->firstOrFail();
}

/**
 * Hide a word, the way the administrator endpoint does.
 */
function hide(object $test, string $slug): Tag
{
    $tag = hashtagRow($test, $slug);
    $tag->forceFill(['suppressed_at' => now()])->save();

    return $tag->refresh();
}

// ---------------------------------------------------------------------------
// The fence around the blast radius — asserted first, and it must never move
// ---------------------------------------------------------------------------

test('a post carrying a suppressed hashtag is still published and still listed', function (): void {
    suppressionPost($this, 'noodles #streetfood');
    hide($this, 'streetfood');

    Sanctum::actingAs($this->viewer);

    // The ordinary listing, with no tag filter: exactly what any reader sees.
    $captions = $this->getJson('/api/v1/posts', orgHeader($this->organization))
        ->assertOk()
        ->json('data.*.caption');

    expect($captions)->toContain('noodles #streetfood');
});

test('suppressing one word leaves every other word on the same post alone', function (): void {
    suppressionPost($this, 'noodles #streetfood and #history');
    hide($this, 'streetfood');

    Sanctum::actingAs($this->viewer);

    $slugs = $this->getJson('/api/v1/posts', orgHeader($this->organization))
        ->assertOk()
        ->json('data.0.tags.*.slug');

    expect($slugs)->toContain('history')
        ->not->toContain('streetfood');
});

// ---------------------------------------------------------------------------
// The five surfaces a hashtag reaches a reader through
// ---------------------------------------------------------------------------

test('looking up a suppressed word answers exactly as a word nobody typed does', function (): void {
    suppressionPost($this, 'noodles #streetfood');

    Sanctum::actingAs($this->viewer);
    $this->getJson('/api/v1/discover/tags/streetfood', orgHeader($this->organization))->assertOk();

    hide($this, 'streetfood');

    // The pair is the evidence, not either half. A 404 on its own would also be
    // what a missing route answers, so the 200 above is what gives it meaning.
    $this->getJson('/api/v1/discover/tags/streetfood', orgHeader($this->organization))
        ->assertNotFound();
});

test('a suppressed word leaves the search results', function (): void {
    suppressionPost($this, 'noodles #streetfood');

    Sanctum::actingAs($this->viewer);

    // Present first, so a later absence means suppression and not a query that
    // never matched.
    expect($this->getJson('/api/v1/discover/search?q=street&type=tags', orgHeader($this->organization))
        ->assertOk()
        ->json('data.*.slug'))->toContain('streetfood');

    hide($this, 'streetfood');

    expect($this->getJson('/api/v1/discover/search?q=street&type=tags', orgHeader($this->organization))
        ->assertOk()
        ->json('data.*.slug'))->not->toContain('streetfood');
});

test('asking a suppressed word for its posts returns none of them', function (): void {
    suppressionPost($this, 'noodles #streetfood');
    hide($this, 'streetfood');

    Sanctum::actingAs($this->viewer);

    expect($this->getJson('/api/v1/posts?tag=streetfood', orgHeader($this->organization))
        ->assertOk()
        ->json('data'))->toBe([]);
});

test('asking a suppressed word for its spots returns none of them', function (): void {
    Spot::factory()->for($this->organization)->create([
        'user_id' => $this->viewer->id,
        'status' => SpotStatus::Published->value,
        'description' => 'a view #viewpoint',
    ]);
    hide($this, 'viewpoint');

    Sanctum::actingAs($this->viewer);

    expect($this->getJson('/api/v1/spots?tag=viewpoint', orgHeader($this->organization))
        ->assertOk()
        ->json('data'))->toBe([]);
});

test('a suppressed word is not offered as something to tap', function (): void {
    suppressionPost($this, 'noodles #streetfood');
    hide($this, 'streetfood');

    Sanctum::actingAs($this->viewer);

    expect($this->getJson('/api/v1/posts', orgHeader($this->organization))
        ->assertOk()
        ->json('data.0.tags'))->toBe([]);
});

// ---------------------------------------------------------------------------
// Nothing was destroyed, so all of it comes back
// ---------------------------------------------------------------------------

test('bringing a word back restores every surface it was hidden from', function (): void {
    suppressionPost($this, 'noodles #streetfood');
    $tag = hide($this, 'streetfood');

    $tag->forceFill(['suppressed_at' => null])->save();

    Sanctum::actingAs($this->viewer);

    $this->getJson('/api/v1/discover/tags/streetfood', orgHeader($this->organization))->assertOk();

    expect($this->getJson('/api/v1/posts?tag=streetfood', orgHeader($this->organization))
        ->assertOk()
        ->json('data.*.caption'))->toBe(['noodles #streetfood']);

    expect($this->getJson('/api/v1/posts', orgHeader($this->organization))
        ->assertOk()
        ->json('data.0.tags.*.slug'))->toContain('streetfood');
});

// ---------------------------------------------------------------------------
// Who may pull the switch
// ---------------------------------------------------------------------------

test('an ordinary explorer cannot hide a word', function (): void {
    suppressionPost($this, 'noodles #streetfood');
    $tag = hashtagRow($this, 'streetfood');

    Sanctum::actingAs($this->viewer);

    $this->patchJson("/api/v1/tags/{$tag->uuid}", ['is_suppressed' => true], orgHeader($this->organization))
        ->assertForbidden();

    expect($tag->refresh()->suppressed_at)->toBeNull();
});

test('the explorer who minted the word cannot hide it either', function (): void {
    // The trap this ability exists to avoid. `update` and `delete` on a tag both
    // grant its creator, and the creator of a hashtag is whoever typed the word
    // first — so reusing either would hand the person who minted an offensive
    // tag the power to un-hide it.
    suppressionPost($this, 'noodles #streetfood');

    $tag = hashtagRow($this, 'streetfood');
    $tag->forceFill(['created_by_id' => $this->viewer->id])->save();

    Sanctum::actingAs($this->viewer);

    $this->patchJson("/api/v1/tags/{$tag->uuid}", ['is_suppressed' => true], orgHeader($this->organization))
        ->assertForbidden();
});

test('a moderator can hide a word and bring it back', function (): void {
    suppressionPost($this, 'noodles #streetfood');
    $tag = hashtagRow($this, 'streetfood');

    Sanctum::actingAs($this->moderator);

    $this->patchJson("/api/v1/tags/{$tag->uuid}", ['is_suppressed' => true], orgHeader($this->organization))
        ->assertOk()
        ->assertJsonPath('data.is_suppressed', true);

    expect($tag->refresh()->suppressed_at)->not->toBeNull();

    $this->patchJson("/api/v1/tags/{$tag->uuid}", ['is_suppressed' => false], orgHeader($this->organization))
        ->assertOk()
        ->assertJsonPath('data.is_suppressed', false);

    expect($tag->refresh()->suppressed_at)->toBeNull();
});

// ---------------------------------------------------------------------------
// How it ships
// ---------------------------------------------------------------------------

test('with nothing suppressed every surface behaves exactly as before', function (): void {
    // The whole mechanism is inert until somebody sets a flag, and this is the
    // test that says so rather than leaving it to be inferred from the others.
    suppressionPost($this, 'noodles #streetfood');

    Sanctum::actingAs($this->viewer);

    $this->getJson('/api/v1/discover/tags/streetfood', orgHeader($this->organization))->assertOk();

    expect($this->getJson('/api/v1/posts?tag=streetfood', orgHeader($this->organization))
        ->assertOk()
        ->json('data.*.caption'))->toBe(['noodles #streetfood']);

    expect($this->getJson('/api/v1/discover/search?q=street&type=tags', orgHeader($this->organization))
        ->assertOk()
        ->json('data.*.slug'))->toContain('streetfood');
});
