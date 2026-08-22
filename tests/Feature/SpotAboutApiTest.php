<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Stourify\Models\Spot;
use Modules\Stourify\Models\SpotAbout;
use Tests\Traits\InteractsWithTestSetup;

uses(RefreshDatabase::class, InteractsWithTestSetup::class);

/**
 * The permissions an ordinary explorer holds over About entries — everything
 * except `manage`, which is what separates a contributor from a moderator.
 * The two `spot_abouts.reactions.*` names are not declared anywhere by hand:
 * the platform discovers them from the model's `HasReactions` trait and its
 * permission prefix.
 *
 * @var list<string>
 */
const ABOUT_EXPLORER_PERMISSIONS = [
    'stourify.spot_abouts.view',
    'stourify.spot_abouts.create',
    'stourify.spot_abouts.update',
    'stourify.spot_abouts.delete',
    'spot_abouts.reactions.view',
    'spot_abouts.reactions.create',
    'spot_abouts.reactions.delete',
];

beforeEach(function (): void {
    $this->organization = $this->setUpTestOrganization();
    $this->seedPermissions([...ABOUT_EXPLORER_PERMISSIONS, 'stourify.spot_abouts.manage', 'stourify.spots.view']);

    $this->explorer = $this->createUserWithPermissions(
        $this->organization,
        [...ABOUT_EXPLORER_PERMISSIONS, 'stourify.spots.view'],
    );

    $this->spot = Spot::factory()->create([
        'organization_id' => $this->organization->id,
        'user_id' => $this->explorer->id,
    ]);
});

/**
 * Build an About entry belonging to the test's spot and organization.
 */
function makeAbout(User $author, ?Spot $spot = null, array $attributes = []): SpotAbout
{
    return SpotAbout::factory()->create([
        'organization_id' => $author->organizations->first()?->id ?? test()->organization->id,
        'spot_id' => ($spot ?? test()->spot)->id,
        'user_id' => $author->id,
        ...$attributes,
    ]);
}

/**
 * Set a row's `likes_count` directly.
 *
 * Test scaffolding, deliberately bypassing the application: these ordering
 * tests are about the ORDER BY, and building it out of real reactions would
 * need a distinct user per like and would make an ordering failure look like a
 * counter failure. The counter has its own test below, driven through the real
 * endpoint, which is where that claim belongs.
 */
function setLikes(SpotAbout $about, int $count): void
{
    DB::table('sto_spot_abouts')->where('id', $about->id)->update(['likes_count' => $count]);
    $about->clearCache();
}

// ---------------------------------------------------------------------------
// Writing an entry
// ---------------------------------------------------------------------------

test('an explorer adds an About entry to a spot', function (): void {
    Sanctum::actingAs($this->explorer);

    $response = $this->postJson('/api/v1/spot-abouts', [
        'spot_uuid' => $this->spot->uuid,
        'body' => 'The side entrance on the alley is the one that is actually open.',
    ], ['X-Organization-Id' => $this->organization->uuid]);

    $response->assertCreated()
        ->assertJsonPath('data.body', 'The side entrance on the alley is the one that is actually open.')
        ->assertJsonPath('data.likes_count', 0)
        ->assertJsonPath('data.author.uuid', $this->explorer->uuid)
        ->assertJsonPath('data.spot_uuid', $this->spot->uuid);

    expect($response->json('data.created_at'))->not->toBeNull();

    $this->assertDatabaseHas('sto_spot_abouts', [
        'spot_id' => $this->spot->id,
        'user_id' => $this->explorer->id,
    ]);
});

test('an entry cannot be written against a spot that does not exist', function (): void {
    Sanctum::actingAs($this->explorer);

    $this->postJson('/api/v1/spot-abouts', [
        'spot_uuid' => '00000000-0000-4000-8000-000000000000',
        'body' => 'Nowhere in particular.',
    ], ['X-Organization-Id' => $this->organization->uuid])
        ->assertStatus(422)
        ->assertJsonValidationErrors('spot_uuid');
});

test('a signed-out caller cannot read or write entries', function (): void {
    $this->getJson('/api/v1/spot-abouts?spot_uuid='.$this->spot->uuid)->assertUnauthorized();
    $this->postJson('/api/v1/spot-abouts', ['spot_uuid' => $this->spot->uuid, 'body' => 'x'])->assertUnauthorized();
});

test('a user without the create permission is refused before validation runs', function (): void {
    $outsider = $this->createUserWithPermissions($this->organization, ['stourify.spots.view']);
    Sanctum::actingAs($outsider);

    // No `body` at all: a 422 here would mean the server itemised its fields
    // for somebody who may not create an entry — the ordering defect STOURIFY-23
    // records, which the FormRequest's authorize() override exists to prevent.
    $this->postJson('/api/v1/spot-abouts', [
        'spot_uuid' => $this->spot->uuid,
    ], ['X-Organization-Id' => $this->organization->uuid])->assertForbidden();
});

// ---------------------------------------------------------------------------
// Listing, and the order the card is actually about
// ---------------------------------------------------------------------------

test('the list holds one spot only, most-liked first', function (): void {
    $otherSpot = Spot::factory()->create([
        'organization_id' => $this->organization->id,
        'user_id' => $this->explorer->id,
    ]);

    $quiet = makeAbout($this->explorer, null, ['body' => 'Quiet note']);
    $popular = makeAbout($this->explorer, null, ['body' => 'Popular note']);
    $elsewhere = makeAbout($this->explorer, $otherSpot, ['body' => 'Another spot entirely']);

    setLikes($quiet, 1);
    setLikes($popular, 9);
    setLikes($elsewhere, 99);

    Sanctum::actingAs($this->explorer);

    $response = $this->getJson(
        '/api/v1/spot-abouts?spot_uuid='.$this->spot->uuid,
        ['X-Organization-Id' => $this->organization->uuid],
    )->assertOk();

    expect($response->json('data.*.uuid'))->toBe([$popular->uuid, $quiet->uuid]);
});

test('paging over tied like counts returns every entry exactly once', function (): void {
    // Two rows tie on 5. Without a unique tie-breaker the database may return
    // them in a different order per query, so one row can appear on both pages
    // while another appears on neither.
    $first = makeAbout($this->explorer, null, ['body' => 'Tied A']);
    $second = makeAbout($this->explorer, null, ['body' => 'Tied B']);
    $third = makeAbout($this->explorer, null, ['body' => 'Lonely']);

    setLikes($first, 5);
    setLikes($second, 5);
    setLikes($third, 2);

    Sanctum::actingAs($this->explorer);

    $url = '/api/v1/spot-abouts?spot_uuid='.$this->spot->uuid.'&per_page=2&page=';
    $headers = ['X-Organization-Id' => $this->organization->uuid];

    $pageOne = $this->getJson($url.'1', $headers)->assertOk()->json('data.*.uuid');
    $pageTwo = $this->getJson($url.'2', $headers)->assertOk()->json('data.*.uuid');

    expect($pageOne)->toHaveCount(2)
        ->and($pageTwo)->toHaveCount(1)
        ->and(array_unique([...$pageOne, ...$pageTwo]))->toHaveCount(3)
        ->and($pageTwo[0])->toBe($third->uuid);
});

test('the caller may sort by recency instead', function (): void {
    $older = makeAbout($this->explorer, null, ['body' => 'Older']);
    $newer = makeAbout($this->explorer, null, ['body' => 'Newer']);

    DB::table('sto_spot_abouts')->where('id', $older->id)->update(['created_at' => now()->subDay()]);
    setLikes($older, 50);

    Sanctum::actingAs($this->explorer);

    $response = $this->getJson(
        '/api/v1/spot-abouts?spot_uuid='.$this->spot->uuid.'&sort=created_at&direction=desc',
        ['X-Organization-Id' => $this->organization->uuid],
    )->assertOk();

    expect($response->json('data.*.uuid'))->toBe([$newer->uuid, $older->uuid]);
});

test('an unknown sort column is rejected rather than ignored', function (): void {
    Sanctum::actingAs($this->explorer);

    $this->getJson(
        '/api/v1/spot-abouts?spot_uuid='.$this->spot->uuid.'&sort=body',
        ['X-Organization-Id' => $this->organization->uuid],
    )->assertStatus(422)->assertJsonValidationErrors('sort');
});

test('listing a page of entries does not query per row', function (): void {
    $authors = collect(range(1, 5))->map(fn (): User => $this->createUserWithPermissions(
        $this->organization,
        ABOUT_EXPLORER_PERMISSIONS,
    ));

    $authors->each(fn (User $author) => makeAbout($author));

    Sanctum::actingAs($this->explorer);

    DB::enableQueryLog();
    DB::flushQueryLog();

    $this->getJson(
        '/api/v1/spot-abouts?spot_uuid='.$this->spot->uuid,
        ['X-Organization-Id' => $this->organization->uuid],
    )->assertOk()->assertJsonCount(5, 'data');

    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Five rows by five different authors. A per-row author lookup would put
    // this well past the ceiling; the eager loads keep it flat.
    expect($queries)->toBeLessThan(20);
});

// ---------------------------------------------------------------------------
// Likes — the platform's own reactions endpoint, no new route
// ---------------------------------------------------------------------------

test('liking an entry moves its counter, and unliking moves it back', function (): void {
    $about = makeAbout($this->explorer);

    Sanctum::actingAs($this->explorer);
    $headers = ['X-Organization-Id' => $this->organization->uuid];

    $this->postJson('/api/v1/reactions', [
        'reactable_type' => 'stourify_spot_about',
        'reactable_uuid' => $about->uuid,
        'type' => 'like',
    ], $headers)->assertOk()->assertJsonPath('data.reacted', true);

    expect(SpotAbout::query()->whereKey($about->id)->value('likes_count'))->toBe(1);

    $this->deleteJson('/api/v1/reactions', [
        'reactable_type' => 'stourify_spot_about',
        'reactable_uuid' => $about->uuid,
    ], $headers)->assertOk();

    expect(SpotAbout::query()->whereKey($about->id)->value('likes_count'))->toBe(0);
});

test('an entry accepts a like and nothing else', function (): void {
    $about = makeAbout($this->explorer);

    Sanctum::actingAs($this->explorer);

    $this->postJson('/api/v1/reactions', [
        'reactable_type' => 'stourify_spot_about',
        'reactable_uuid' => $about->uuid,
        'type' => 'love',
    ], ['X-Organization-Id' => $this->organization->uuid])->assertStatus(422);

    expect(SpotAbout::query()->whereKey($about->id)->value('likes_count'))->toBe(0);
});

test('the list says whether the caller has liked each entry', function (): void {
    $about = makeAbout($this->explorer);

    Sanctum::actingAs($this->explorer);
    $headers = ['X-Organization-Id' => $this->organization->uuid];

    $this->postJson('/api/v1/reactions', [
        'reactable_type' => 'stourify_spot_about',
        'reactable_uuid' => $about->uuid,
        'type' => 'like',
    ], $headers)->assertOk();

    $this->getJson('/api/v1/spot-abouts?spot_uuid='.$this->spot->uuid, $headers)
        ->assertOk()
        ->assertJsonPath('data.0.is_liked', true)
        ->assertJsonPath('data.0.likes_count', 1);
});

// ---------------------------------------------------------------------------
// Who may change an entry
// ---------------------------------------------------------------------------

test('the author edits and removes their own entry', function (): void {
    $about = makeAbout($this->explorer);

    Sanctum::actingAs($this->explorer);
    $headers = ['X-Organization-Id' => $this->organization->uuid];

    $this->patchJson('/api/v1/spot-abouts/'.$about->uuid, ['body' => 'Corrected.'], $headers)
        ->assertOk()
        ->assertJsonPath('data.body', 'Corrected.');

    $this->deleteJson('/api/v1/spot-abouts/'.$about->uuid, [], $headers)->assertOk();

    $this->assertSoftDeleted('sto_spot_abouts', ['id' => $about->id]);
});

test('somebody else cannot edit or remove an entry', function (): void {
    $author = $this->createUserWithPermissions($this->organization, ABOUT_EXPLORER_PERMISSIONS);
    $about = makeAbout($author);

    Sanctum::actingAs($this->explorer);
    $headers = ['X-Organization-Id' => $this->organization->uuid];

    $this->patchJson('/api/v1/spot-abouts/'.$about->uuid, ['body' => 'Not mine.'], $headers)->assertForbidden();
    $this->deleteJson('/api/v1/spot-abouts/'.$about->uuid, [], $headers)->assertForbidden();

    $this->assertNotSoftDeleted('sto_spot_abouts', ['id' => $about->id]);
});

test("a moderator removes anybody else's entry", function (): void {
    $author = $this->createUserWithPermissions($this->organization, ABOUT_EXPLORER_PERMISSIONS);
    $about = makeAbout($author);

    $moderator = $this->createUserWithPermissions($this->organization, [
        ...ABOUT_EXPLORER_PERMISSIONS,
        'stourify.spot_abouts.manage',
    ]);

    Sanctum::actingAs($moderator);

    $this->deleteJson('/api/v1/spot-abouts/'.$about->uuid, [], [
        'X-Organization-Id' => $this->organization->uuid,
    ])->assertOk();

    $this->assertSoftDeleted('sto_spot_abouts', ['id' => $about->id]);
});

test('one entry can be read on its own', function (): void {
    $about = makeAbout($this->explorer);

    Sanctum::actingAs($this->explorer);

    $this->getJson('/api/v1/spot-abouts/'.$about->uuid, ['X-Organization-Id' => $this->organization->uuid])
        ->assertOk()
        ->assertJsonPath('data.uuid', $about->uuid)
        ->assertJsonPath('data.spot_uuid', $this->spot->uuid);
});
