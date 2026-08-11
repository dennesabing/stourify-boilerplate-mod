<?php

declare(strict_types=1);

use App\Events\Domain\UserDeleted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Stourify\Models\ExplorerProfile;
use Modules\Stourify\Models\Follow;
use Modules\Stourify\Models\Post;
use Modules\Stourify\Models\Review;
use Modules\Stourify\Models\Spot;
use Modules\Stourify\Models\SyncTombstone;
use Modules\Stourify\Models\WishlistItem;
use Tests\Traits\InteractsWithTestSetup;

uses(RefreshDatabase::class, InteractsWithTestSetup::class);

/**
 * What happens to an explorer's content when they delete their account.
 *
 * The platform soft-deletes the user row and announces `UserDeleted`; it cannot
 * know this module exists, let alone which tables belong to a departing user.
 * This module answers that question for its own tables, and these tests are the
 * record of the answer — including, deliberately, what is left alone.
 */
beforeEach(function (): void {
    $this->organization = $this->setUpTestOrganization();

    $this->leaver = $this->createUserWithPermissions($this->organization, []);
    $this->stayer = $this->createUserWithPermissions($this->organization, []);
});

function stourifySpotFor(int $userId, int $orgId): Spot
{
    return Spot::factory()->create(['user_id' => $userId, 'organization_id' => $orgId]);
}

it('soft-deletes the departing explorer spots, posts and reviews', function (): void {
    $spot = stourifySpotFor($this->leaver->id, $this->organization->id);
    $post = Post::factory()->create([
        'user_id' => $this->leaver->id,
        'organization_id' => $this->organization->id,
        'spot_id' => $spot->id,
    ]);
    $review = Review::factory()->create([
        'user_id' => $this->leaver->id,
        'organization_id' => $this->organization->id,
        'spot_id' => $spot->id,
    ]);

    UserDeleted::dispatch($this->leaver);

    expect(Spot::find($spot->id))->toBeNull()
        ->and(Post::find($post->id))->toBeNull()
        ->and(Review::find($review->id))->toBeNull();

    // Soft, not hard: the platform's retention window is what erases them, and
    // until then a mistaken deletion is recoverable.
    expect(Spot::withTrashed()->find($spot->id)->trashed())->toBeTrue()
        ->and(Post::withTrashed()->find($post->id)->trashed())->toBeTrue()
        ->and(Review::withTrashed()->find($review->id)->trashed())->toBeTrue();
});

it('removes follow edges in both directions', function (): void {
    $outgoing = Follow::factory()->create([
        'follower_id' => $this->leaver->id,
        'followee_id' => $this->stayer->id,
        'organization_id' => $this->organization->id,
    ]);
    $incoming = Follow::factory()->create([
        'follower_id' => $this->stayer->id,
        'followee_id' => $this->leaver->id,
        'organization_id' => $this->organization->id,
    ]);

    UserDeleted::dispatch($this->leaver);

    // Both directions, because a follow is one row read from two sides. Only
    // clearing `follower_id` would leave the stayer with a follow of nobody.
    expect(Follow::find($outgoing->id))->toBeNull()
        ->and(Follow::find($incoming->id))->toBeNull();
});

it('removes the wishlist and the explorer profile', function (): void {
    $spot = stourifySpotFor($this->stayer->id, $this->organization->id);
    $wish = WishlistItem::factory()->create([
        'user_id' => $this->leaver->id,
        'organization_id' => $this->organization->id,
        'spot_id' => $spot->id,
    ]);
    $profile = ExplorerProfile::factory()->create([
        'user_id' => $this->leaver->id,
        'organization_id' => $this->organization->id,
    ]);

    UserDeleted::dispatch($this->leaver);

    expect(WishlistItem::find($wish->id))->toBeNull()
        ->and(ExplorerProfile::find($profile->id))->toBeNull();
});

it('leaves everyone else content alone', function (): void {
    $mySpot = stourifySpotFor($this->leaver->id, $this->organization->id);
    $theirSpot = stourifySpotFor($this->stayer->id, $this->organization->id);
    $theirPost = Post::factory()->create([
        'user_id' => $this->stayer->id,
        'organization_id' => $this->organization->id,
        'spot_id' => $theirSpot->id,
    ]);
    $theirFollow = Follow::factory()->create([
        'follower_id' => $this->stayer->id,
        'followee_id' => $this->createUserWithPermissions($this->organization, [])->id,
        'organization_id' => $this->organization->id,
    ]);

    UserDeleted::dispatch($this->leaver);

    // The assertion that actually matters for a destructive listener: the
    // happy-path one above passes just as well against code that emptied every
    // table in the module.
    expect(Spot::find($mySpot->id))->toBeNull()
        ->and(Spot::find($theirSpot->id))->not->toBeNull()
        ->and(Post::find($theirPost->id))->not->toBeNull()
        ->and(Follow::find($theirFollow->id))->not->toBeNull();
});

it('writes tombstones so an offline client drops the content too', function (): void {
    $spot = stourifySpotFor($this->leaver->id, $this->organization->id);
    $follow = Follow::factory()->create([
        'follower_id' => $this->leaver->id,
        'followee_id' => $this->stayer->id,
        'organization_id' => $this->organization->id,
    ]);

    UserDeleted::dispatch($this->leaver);

    // Deleting through the models rather than with a mass `->delete()` query is
    // what keeps SyncTombstoneObserver firing. A device that was offline during
    // the deletion has no other way to learn the rows are gone.
    expect(SyncTombstone::where('entity_type', Spot::morphAlias())->where('entity_uuid', $spot->uuid)->exists())->toBeTrue();

    // A follow writes one tombstone per party, so the edge's disappearance
    // reaches the stayer's delta as well as the leaver's.
    expect(SyncTombstone::where('entity_type', Follow::morphAlias())->where('entity_uuid', $follow->uuid)->pluck('user_id')->all())
        ->toEqualCanonicalizing([$this->leaver->id, $this->stayer->id]);
});
