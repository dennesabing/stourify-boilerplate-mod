<?php

declare(strict_types=1);

namespace Modules\Stourify\Observers;

use Illuminate\Database\Eloquent\Model;
use Modules\Stourify\Models\Post;
use Modules\Stourify\Support\Hashtags\HashtagSynchronizer;

/**
 * Keeps a record's hashtags in step with the text it was written in.
 *
 * ## Why this is an observer and not code in a controller
 *
 * There is more than one road into the database. `POST /api/v1/spots` reaches
 * `SpotApiController`, but a spot written with **no signal** does not: the app
 * keeps it locally and sends it later through
 * `POST /api/v1/stourify/sync/push`, which reaches `SyncController` and never
 * touches the spot controller at all.
 *
 * A parse written into the controller would therefore tag spots created online,
 * skip spots created in a tunnel, and report nothing either way — a bug that
 * works whenever anybody checks it by hand. What every road has in common is
 * that it writes through `CrudService`, which is ordinary Eloquent underneath
 * and fires this event. {@see SyncTombstoneObserver} is registered for exactly
 * the same reason on the other side of the lifecycle.
 *
 * ## Why it checks whether the text changed
 *
 * `saved` fires on every write, including the one that moves a like counter.
 * Re-parsing there would put two queries on the hot path of tapping a heart and
 * could not change the answer, so the work is skipped unless the record is new
 * or its text actually moved.
 */
class HashtagObserver
{
    public function __construct(private readonly HashtagSynchronizer $synchronizer) {}

    public function saved(Model $model): void
    {
        $field = self::textFieldOf($model);

        if (! $model->wasRecentlyCreated && ! $model->wasChanged($field)) {
            return;
        }

        $this->synchronizer->sync($model, $model->getAttribute($field));
    }

    /**
     * Which of the record's columns the author writes their hashtags into.
     *
     * A post's is its caption; a spot's is its description. Kept here rather
     * than on the models because it is a fact about this feature, not about
     * what a post is.
     */
    private static function textFieldOf(Model $model): string
    {
        return $model instanceof Post ? 'caption' : 'description';
    }
}
