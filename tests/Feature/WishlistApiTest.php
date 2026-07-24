<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Stourify\Enums\SpotStatus;
use Modules\Stourify\Models\City;
use Modules\Stourify\Models\Spot;
use Modules\Stourify\Models\WishlistItem;
use Tests\Traits\InteractsWithTestSetup;

uses(RefreshDatabase::class, InteractsWithTestSetup::class);

/**
 * @var list<string>
 */
const WISHLIST_PERMISSIONS = ['stourify.wishlist.manage'];

beforeEach(function (): void {
    $this->organization = $this->setUpTestOrganization();
    $this->seedPermissions(WISHLIST_PERMISSIONS);

    $this->explorer = $this->createUserWithPermissions($this->organization, WISHLIST_PERMISSIONS);
    $this->city = City::factory()->for($this->organization)->create(['name' => 'General Santos']);
    $this->spot = Spot::factory()->for($this->organization)->create([
        'user_id' => $this->explorer->id,
        'status' => SpotStatus::Published,
        'city_id' => $this->city->id,
    ]);
});

function actingAsWisher(User $user): void
{
    Sanctum::actingAs($user);
}

// ---------------------------------------------------------------------------
// Saving and unsaving
// ---------------------------------------------------------------------------

test('an explorer saves a spot', function (): void {
    actingAsWisher($this->explorer);

    $this->postJson('/api/v1/wishlist', [
        'spot_uuid' => $this->spot->uuid,
        'note' => 'Go at sunset.',
    ], orgHeader($this->organization))
        ->assertCreated()
        ->assertJsonPath('data.spot.uuid', $this->spot->uuid)
        ->assertJsonPath('data.note', 'Go at sunset.');

    $this->assertDatabaseHas('sto_wishlist_items', [
        'user_id' => $this->explorer->id,
        'spot_id' => $this->spot->id,
    ]);
});

test('the saved item inherits the spot\'s city, not a client-supplied one', function (): void {
    $otherCity = City::factory()->for($this->organization)->create(['name' => 'Davao']);

    actingAsWisher($this->explorer);
    $this->postJson('/api/v1/wishlist', [
        'spot_uuid' => $this->spot->uuid,
        // A stray city_id in the payload must be ignored — it is denormalized
        // off the spot.
        'city_id' => $otherCity->id,
    ], orgHeader($this->organization))->assertCreated();

    $this->assertDatabaseHas('sto_wishlist_items', [
        'spot_id' => $this->spot->id,
        'city_id' => $this->city->id,
    ]);
});

test('a saved spot is updated and removed by its owner', function (): void {
    actingAsWisher($this->explorer);
    $item = WishlistItem::factory()->for($this->organization)->create([
        'user_id' => $this->explorer->id,
        'spot_id' => $this->spot->id,
        'city_id' => $this->city->id,
    ]);

    $this->patchJson("/api/v1/wishlist/{$item->uuid}", [
        'note' => 'Bring a jacket.',
        'is_downloaded_offline' => true,
    ], orgHeader($this->organization))
        ->assertOk()
        ->assertJsonPath('data.note', 'Bring a jacket.')
        ->assertJsonPath('data.is_downloaded_offline', true);

    $this->deleteJson("/api/v1/wishlist/{$item->uuid}", [], orgHeader($this->organization))->assertOk();
    $this->assertDatabaseMissing('sto_wishlist_items', ['id' => $item->id]);
});

test('unsaving hard-deletes so the same spot can be saved again', function (): void {
    actingAsWisher($this->explorer);

    $uuid = $this->postJson('/api/v1/wishlist', [
        'spot_uuid' => $this->spot->uuid,
    ], orgHeader($this->organization))->assertCreated()->json('data.uuid');

    $this->deleteJson("/api/v1/wishlist/{$uuid}", [], orgHeader($this->organization))->assertOk();

    // A tombstone would collide with the unique (user_id, spot_id) index.
    $this->postJson('/api/v1/wishlist', [
        'spot_uuid' => $this->spot->uuid,
    ], orgHeader($this->organization))->assertCreated();
});

// ---------------------------------------------------------------------------
// Listing, grouping, privacy
// ---------------------------------------------------------------------------

test('the list returns only the caller\'s saved spots', function (): void {
    $other = $this->createUserWithPermissions($this->organization, WISHLIST_PERMISSIONS);

    $mine = WishlistItem::factory()->for($this->organization)->create([
        'user_id' => $this->explorer->id, 'spot_id' => $this->spot->id, 'city_id' => $this->city->id,
    ]);
    $otherSpot = Spot::factory()->for($this->organization)->create([
        'user_id' => $other->id, 'status' => SpotStatus::Published, 'city_id' => $this->city->id,
    ]);
    WishlistItem::factory()->for($this->organization)->create([
        'user_id' => $other->id, 'spot_id' => $otherSpot->id, 'city_id' => $this->city->id,
    ]);

    actingAsWisher($this->explorer);
    $data = $this->getJson('/api/v1/wishlist', orgHeader($this->organization))->assertOk()->json('data');

    expect($data)->toHaveCount(1)->and($data[0]['uuid'])->toBe($mine->uuid);
});

test('the list filters by city', function (): void {
    $davao = City::factory()->for($this->organization)->create(['name' => 'Davao']);
    $davaoSpot = Spot::factory()->for($this->organization)->create([
        'user_id' => $this->explorer->id, 'status' => SpotStatus::Published, 'city_id' => $davao->id,
    ]);

    WishlistItem::factory()->for($this->organization)->create([
        'user_id' => $this->explorer->id, 'spot_id' => $this->spot->id, 'city_id' => $this->city->id,
    ]);
    $davaoItem = WishlistItem::factory()->for($this->organization)->create([
        'user_id' => $this->explorer->id, 'spot_id' => $davaoSpot->id, 'city_id' => $davao->id,
    ]);

    actingAsWisher($this->explorer);
    $data = $this->getJson("/api/v1/wishlist?city_uuid={$davao->uuid}", orgHeader($this->organization))
        ->assertOk()->json('data');

    expect($data)->toHaveCount(1)->and($data[0]['uuid'])->toBe($davaoItem->uuid);
});

test('the list filters by offline-download state', function (): void {
    $downloaded = WishlistItem::factory()->for($this->organization)->create([
        'user_id' => $this->explorer->id, 'spot_id' => $this->spot->id,
        'city_id' => $this->city->id, 'is_downloaded_offline' => true,
    ]);
    $otherSpot = Spot::factory()->for($this->organization)->create([
        'user_id' => $this->explorer->id, 'status' => SpotStatus::Published, 'city_id' => $this->city->id,
    ]);
    WishlistItem::factory()->for($this->organization)->create([
        'user_id' => $this->explorer->id, 'spot_id' => $otherSpot->id,
        'city_id' => $this->city->id, 'is_downloaded_offline' => false,
    ]);

    actingAsWisher($this->explorer);
    $data = $this->getJson('/api/v1/wishlist?downloaded=1', orgHeader($this->organization))
        ->assertOk()->json('data');

    expect($data)->toHaveCount(1)->and($data[0]['uuid'])->toBe($downloaded->uuid);
});

test('an explorer cannot view, edit or delete another explorer\'s saved spot', function (): void {
    $other = $this->createUserWithPermissions($this->organization, WISHLIST_PERMISSIONS);
    $item = WishlistItem::factory()->for($this->organization)->create([
        'user_id' => $other->id, 'spot_id' => $this->spot->id, 'city_id' => $this->city->id,
    ]);

    actingAsWisher($this->explorer);

    $this->getJson("/api/v1/wishlist/{$item->uuid}", orgHeader($this->organization))->assertForbidden();
    $this->patchJson("/api/v1/wishlist/{$item->uuid}", ['note' => 'x'], orgHeader($this->organization))->assertForbidden();
    $this->deleteJson("/api/v1/wishlist/{$item->uuid}", [], orgHeader($this->organization))->assertForbidden();
});

// ---------------------------------------------------------------------------
// Validation and permission
// ---------------------------------------------------------------------------

test('saving the same spot twice is a validation error, not a 500', function (): void {
    WishlistItem::factory()->for($this->organization)->create([
        'user_id' => $this->explorer->id, 'spot_id' => $this->spot->id, 'city_id' => $this->city->id,
    ]);

    actingAsWisher($this->explorer);
    $this->postJson('/api/v1/wishlist', [
        'spot_uuid' => $this->spot->uuid,
    ], orgHeader($this->organization))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['spot_uuid']);
});

test('saving requires a real spot', function (): void {
    actingAsWisher($this->explorer);

    $this->postJson('/api/v1/wishlist', [], orgHeader($this->organization))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['spot_uuid']);

    $this->postJson('/api/v1/wishlist', [
        'spot_uuid' => '00000000-0000-4000-8000-000000000000',
    ], orgHeader($this->organization))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['spot_uuid']);
});

test('wishlist endpoints reject an unauthenticated caller', function (string $method, string $uri): void {
    $this->json($method, $uri, [], orgHeader($this->organization))->assertUnauthorized();
})->with([
    ['get', '/api/v1/wishlist'],
    ['post', '/api/v1/wishlist'],
]);

test('saving is denied without the wishlist permission', function (): void {
    actingAsWisher($this->createUserWithPermissions($this->organization, []));

    $this->postJson('/api/v1/wishlist', [
        'spot_uuid' => $this->spot->uuid,
    ], orgHeader($this->organization))->assertForbidden();
});
