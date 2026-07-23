<?php

declare(strict_types=1);

namespace Modules\Stourify\Observers;

use Illuminate\Support\Facades\DB;
use Modules\Stourify\Models\Review;
use Modules\Stourify\Models\Spot;

/**
 * Keeps a spot's denormalized rating aggregates truthful.
 *
 * `sto_spots.rating_average` and `reviews_count` exist because every spot card
 * in the feed, the nearby list and the search results renders them — counting
 * on read would put an aggregate query behind every card.
 *
 * This lives in an observer rather than the controller on purpose. The same
 * numbers have to be right whether a review arrives through the API, the
 * offline sync push, a seeder, a factory in a test, or an artisan command. A
 * controller only covers the first.
 *
 * **This writes to the spot without an authorization check, deliberately.**
 * Going through `CrudService` here would call `Gate::authorize('update', $spot)`
 * as the *reviewer*, who has no business updating that spot — every review by
 * anyone but the spot's author would fail. The write is not user intent; it is
 * a derived value the system owes the spot, so it is a raw, narrow update of
 * exactly two computed columns and nothing else.
 */
class ReviewObserver
{
    public function created(Review $review): void
    {
        $this->recalculate($review->spot_id);
    }

    public function updated(Review $review): void
    {
        $this->recalculate($review->spot_id);

        // A review moved between spots (sync reconciliation can do this) leaves
        // the old spot's aggregate stale.
        $originalSpotId = $review->getOriginal('spot_id');

        if ($originalSpotId !== null && $originalSpotId !== $review->spot_id) {
            $this->recalculate((int) $originalSpotId);
        }
    }

    public function deleted(Review $review): void
    {
        $this->recalculate($review->spot_id);
    }

    public function restored(Review $review): void
    {
        $this->recalculate($review->spot_id);
    }

    public function forceDeleted(Review $review): void
    {
        $this->recalculate($review->spot_id);
    }

    /**
     * Recompute from the table rather than incrementing a counter.
     *
     * An increment drifts the moment anything writes outside the happy path —
     * a restore, a bulk delete, a failed transaction. Recomputing is one
     * indexed aggregate over `(spot_id, rating)` and cannot drift.
     */
    private function recalculate(?int $spotId): void
    {
        if ($spotId === null) {
            return;
        }

        $spot = Spot::query()->find($spotId);

        if ($spot === null) {
            return;
        }

        /** @var object{average: float|null, total: int} $aggregate */
        $aggregate = DB::table('sto_reviews')
            ->where('spot_id', $spotId)
            ->whereNull('deleted_at')
            ->selectRaw('AVG(rating) as average, COUNT(*) as total')
            ->first();

        $spot->forceFill([
            'rating_average' => round((float) ($aggregate->average ?? 0), 2),
            'reviews_count' => (int) ($aggregate->total ?? 0),
        ])->save();
    }
}
