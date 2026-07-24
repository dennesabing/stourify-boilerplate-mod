<?php

declare(strict_types=1);

namespace Modules\Stourify\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Eager-loads a viewer's OWN reactions onto a query of reactable hosts.
 *
 * The `is_liked` / `marked_helpful` resource flags answer "have *I* reacted?",
 * which is per-viewer and cannot be denormalized like the counts. Loading the
 * `reactions` relation constrained to the caller answers it in one extra query
 * for the whole page rather than one per row — the resources then read the
 * already-loaded, viewer-scoped set. Resolving it inside the resource instead
 * would be the N+1 this exists to avoid.
 */
trait LoadsViewerReactions
{
    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @return Builder<covariant \Illuminate\Database\Eloquent\Model>
     */
    protected function withViewerReaction(Builder $query, User $viewer): Builder
    {
        return $query->with([
            'reactions' => fn ($relation) => $relation->where('user_id', $viewer->id),
        ]);
    }
}
