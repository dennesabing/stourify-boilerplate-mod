<?php

declare(strict_types=1);

namespace Modules\Stourify\Observers;

use App\Models\Reaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Modules\Stourify\Models\Post;
use Modules\Stourify\Models\Review;

/**
 * Keeps the module's denormalized reaction counters truthful.
 *
 * `sto_posts.likes_count` and `sto_reviews.helpful_count` exist because the
 * feed and the Reviews screen render and sort on them across many rows —
 * counting reactions per row on read would be an N+1. The counters ARE the
 * denormalized columns; this observer is what makes them true.
 *
 * It observes the platform's `Reaction` model, so it fires for reactions on
 * every host in the app, not just Stourify's — hence the `instanceof` guards.
 * A reaction on anything that is not a Post or Review is ignored.
 *
 * The counter is **recomputed from the table**, never incremented: an increment
 * drifts the instant anything writes outside the happy path (a switch, a bulk
 * delete, a rolled-back transaction), whereas a scoped `COUNT` cannot. Each is
 * one indexed aggregate over the reactable's reactions.
 *
 * The write is a direct column update — no model save, so no `updated_at`
 * churn on a post every time someone likes it, and no event recursion — paired
 * with an explicit cache clear so a cached read reflects the new count. The
 * feed is uncached and always fresh regardless.
 */
class ReactionCountObserver
{
    public function created(Reaction $reaction): void
    {
        $this->sync($reaction);
    }

    public function updated(Reaction $reaction): void
    {
        $this->sync($reaction);
    }

    public function deleted(Reaction $reaction): void
    {
        // On `deleted` the row is already gone, so the recomputed COUNT
        // correctly excludes it.
        $this->sync($reaction);
    }

    private function sync(Reaction $reaction): void
    {
        $host = $reaction->reactable;

        if ($host instanceof Post) {
            $this->recount($host, Post::LIKE_REACTION, 'likes_count');

            return;
        }

        if ($host instanceof Review) {
            $this->recount($host, Review::HELPFUL_REACTION, 'helpful_count');
        }
    }

    /**
     * Recompute one host's counter for a single reaction type.
     */
    private function recount(Model $host, string $type, string $column): void
    {
        $count = $host->reactions()->where('type', $type)->count();

        DB::table($host->getTable())
            ->where('id', $host->getKey())
            ->update([$column => $count]);

        // Direct update skips Cacheable's saved hook, so invalidate explicitly
        // to keep cached list reads (post index, review index) honest.
        if (method_exists($host, 'clearCache')) {
            $host->clearCache();
        }
    }
}
