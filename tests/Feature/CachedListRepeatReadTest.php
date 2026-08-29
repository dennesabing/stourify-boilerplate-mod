<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\AbstractCursorPaginator;
use Illuminate\Pagination\AbstractPaginator;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Modules\Stourify\Enums\PostVisibility;
use Modules\Stourify\Enums\SpotStatus;
use Modules\Stourify\Models\City;
use Modules\Stourify\Models\Post;
use Modules\Stourify\Models\Spot;
use Modules\Stourify\Models\WishlistItem;
use Tests\Traits\InteractsWithTestSetup;

uses(RefreshDatabase::class, InteractsWithTestSetup::class);

/**
 * Ask a cached list endpoint the same question twice and get the same answer
 * (STOURIFY-245).
 *
 * Nothing in this suite used to do that, which is the only reason the bug
 * survived. Every list endpoint in this module reads a page of rows out of the
 * cache and hands it straight to `SomeResource::collection(...)` — and that
 * call does not merely read the page, it swaps the rows for display objects
 * **in the very object it was given**. On a cache driver that hands back the
 * live object rather than a copy (the `array` store this suite runs on) the
 * cache was left holding the rewritten page, and the second identical request
 * got that instead of the rows.
 *
 * `nearby` was the one that fell over loudly, because the code after its cache
 * read has a type hint standing exactly where the damage lands, so it threw a
 * 500. The others carried on rendering, because a resource wrapping a resource
 * happens to work — which is worse, not better: a silently different answer to
 * an identical question.
 *
 * The fix is one level down, in the platform's `Cacheable` trait
 * (`saas-boilerplate/app/Traits/Cacheable.php`), which now hands back a copy of
 * a cached paginator. These tests are the module's own proof that it holds
 * through a real request, on more than one endpoint, so nobody has to trust
 * that every controller remembered something.
 *
 * @var list<string>
 */
const REPEAT_READ_PERMISSIONS = [
    'stourify.spots.view',
    'stourify.posts.view',
    'stourify.wishlist.manage',
];

beforeEach(function (): void {
    $this->organization = $this->setUpTestOrganization();
    $this->seedPermissions(REPEAT_READ_PERMISSIONS);

    $this->explorer = $this->createUserWithPermissions($this->organization, REPEAT_READ_PERMISSIONS);

    $this->city = City::factory()->for($this->organization)->create(['name' => 'General Santos']);

    // Two rows, not one: a page has to actually contain something for the
    // rewrite-in-place to have anything to rewrite.
    $this->spots = collect([
        ['latitude' => 6.1164, 'longitude' => 125.1716],
        ['latitude' => 6.1200, 'longitude' => 125.1800],
    ])->map(fn (array $point) => Spot::factory()->for($this->organization)->create([
        'user_id' => $this->explorer->id,
        'status' => SpotStatus::Published,
        'city_id' => $this->city->id,
        ...$point,
    ]));

    Sanctum::actingAs($this->explorer);
});

/**
 * Every page currently sitting in the `array` cache store.
 *
 * Reaching into the store's own storage is unusual in a test and it is done
 * deliberately, because the obvious assertion is weaker than it looks. Compare
 * two identical responses and only `nearby` ever disagrees — the other
 * endpoints render a resource wrapped in a resource to byte-identical JSON,
 * since `JsonResource` forwards anything it does not understand to whatever it
 * wraps. So "the second answer equals the first" is satisfied by a cache that
 * has been quietly corrupted, and a regression test that cannot see the
 * corruption is not a regression test. Looking at what the cache is actually
 * holding is the assertion that fails when the bug is present.
 *
 * @return list<mixed>
 */
function cachedPages(): array
{
    $store = Cache::store('array')->getStore();

    $storage = (new ReflectionProperty($store, 'storage'))->getValue($store);

    return array_values(array_map(
        fn (array $entry): mixed => $entry['value'] ?? null,
        array_filter($storage, 'is_array'),
    ));
}

/**
 * Call one endpoint twice and insist nothing was disturbed along the way.
 *
 * The first call is what populates the cache and then renders it; the second is
 * the one that used to be served a page somebody had already written into.
 */
function twoIdenticalReadsAgree(string $url): void
{
    $headers = orgHeader(test()->organization);

    $first = test()->getJson($url, $headers)->assertOk();

    // Checked between the two calls, because this is the moment the damage was
    // done: the first render is what used to rewrite the cached page.
    foreach (cachedPages() as $page) {
        if ($page instanceof AbstractPaginator || $page instanceof AbstractCursorPaginator) {
            expect($page->getCollection()->first())->not->toBeInstanceOf(JsonResource::class);
        }
    }

    $second = test()->getJson($url, $headers)->assertOk();

    expect($second->json())->toEqual($first->json());
}

test('the spot list answers a repeated request identically', function (): void {
    twoIdenticalReadsAgree('/api/v1/spots');

    expect(test()->getJson('/api/v1/spots', orgHeader($this->organization))->json('data'))
        ->toHaveCount(2);
});

test('the proximity search answers a repeated request identically', function (): void {
    // The endpoint that failed loudly: `attachDistances()` runs over the rows
    // after the cache read and its parameter is typed `Spot`, so a page full of
    // `SpotResource` objects threw a 500 on the second call.
    twoIdenticalReadsAgree('/api/v1/spots/nearby?lat=6.1164&lng=125.1716&radius=10');
});

test('the post list answers a repeated request identically', function (): void {
    Post::factory()->count(2)->for($this->organization)->create([
        'user_id' => $this->explorer->id,
        'spot_id' => $this->spots->first()->id,
        'visibility' => PostVisibility::Public,
        'published_at' => now(),
    ]);

    twoIdenticalReadsAgree('/api/v1/posts');
});

test('the wishlist answers a repeated request identically', function (): void {
    foreach ($this->spots as $spot) {
        WishlistItem::factory()->for($this->organization)->create([
            'user_id' => $this->explorer->id,
            'spot_id' => $spot->id,
            'city_id' => $this->city->id,
        ]);
    }

    twoIdenticalReadsAgree('/api/v1/wishlist');
});
