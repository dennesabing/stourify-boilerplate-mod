<?php

declare(strict_types=1);

namespace Modules\Stourify\Listeners;

use App\Events\Domain\UserDeleted;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Modules\Stourify\Models\ExplorerProfile;
use Modules\Stourify\Models\Follow;
use Modules\Stourify\Models\Post;
use Modules\Stourify\Models\Review;
use Modules\Stourify\Models\Spot;
use Modules\Stourify\Models\WishlistItem;
use Modules\Stourify\Observers\SyncTombstoneObserver;

/**
 * Takes a departing explorer's content off every read surface, immediately.
 *
 * ## Why this listener exists at all
 *
 * When somebody deletes their account, the platform soft-deletes the `users`
 * row and the scheduled `db:prune-soft-deleted` command eventually hard-deletes
 * it — at which point every `sto_*` foreign key, all of which are
 * `cascadeOnDelete`, removes this module's rows without a line of code.
 *
 * That is a correct end state and a poor experience of it. The retention window
 * is eighteen months by default (`config('prune.retention_months')`), so relying
 * on the cascade alone means the app tells a person their account is deleted
 * while continuing to publish their photographs, spots and reviews to everyone
 * else for a year and a half. "Deleted" has to be true the moment they ask, not
 * only eventually — and the published privacy policy says exactly that.
 *
 * So the cascade stays as the mechanism that *erases*, and this listener is the
 * mechanism that *withdraws*. The platform announces `UserDeleted`; this module
 * decides what that means for the tables it owns, and the boilerplate never
 * learns that `sto_spots` exists.
 *
 * ## Soft for content, hard for edges
 *
 * The split is not a new judgement — it follows what each table already is.
 * `Spot`, `Post` and `Review` carry `SoftDeletes`, so they are withdrawn and
 * remain recoverable for the same retention window as the account itself.
 * `Follow` and `WishlistItem` and `ExplorerProfile` have no `deleted_at` at all;
 * a follow is one row read from two sides, and unfollowing has always been a
 * real removal (see `Follow`'s own note).
 *
 * ## Why row by row instead of one mass delete
 *
 * `Spot::where('user_id', $id)->delete()` would be one query instead of many,
 * and it would be wrong here: a mass delete bypasses model events, so
 * `SyncTombstoneObserver` never fires and no tombstone is written. A device that
 * happened to be offline during the deletion would then hold the departed
 * explorer's content forever, because the delta sync has no way to express a
 * removal it was never told about. The extra queries buy the one property that
 * makes deletion reach the devices that already have the data.
 *
 * @see UserDeleted
 * @see SyncTombstoneObserver
 */
class RemoveExplorerContentOnUserDeleted
{
    public function handle(UserDeleted $event): void
    {
        $userId = $event->user->id;

        // Soft-deleted: withdrawn now, erased by the platform's retention job.
        $this->deleteEach(Spot::query()->where('user_id', $userId)->get());
        $this->deleteEach(Post::query()->where('user_id', $userId)->get());
        $this->deleteEach(Review::query()->where('user_id', $userId)->get());

        // Hard-deleted: these tables have no `deleted_at`, and an edge to a
        // departed account is not information anybody can use.
        $this->deleteEach(WishlistItem::query()->where('user_id', $userId)->get());
        $this->deleteEach(ExplorerProfile::query()->where('user_id', $userId)->get());

        // Both directions. Clearing only the follows they made would leave every
        // person who followed them holding an edge to nobody.
        $this->deleteEach(
            Follow::query()
                ->where('follower_id', $userId)
                ->orWhere('followee_id', $userId)
                ->get()
        );
    }

    /**
     * @param  Collection<int, Model>|\Illuminate\Database\Eloquent\Collection<int, Model>  $models
     */
    private function deleteEach(iterable $models): void
    {
        foreach ($models as $model) {
            $model->delete();
        }
    }
}
