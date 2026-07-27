<?php

declare(strict_types=1);

namespace Modules\Stourify\Observers;

use Illuminate\Database\Eloquent\Model;
use Modules\Stourify\Models\Follow;
use Modules\Stourify\Models\SyncTombstone;
use Modules\Stourify\Support\Sync\SyncRegistry;

/**
 * Records one tombstone per delete, for every model the offline-sync delta
 * carries — see {@see SyncRegistry}.
 *
 * Registered on each synced model's `deleted` event (`StourifyServiceProvider`),
 * which Eloquent fires uniformly for a hard delete (`Follow`, `WishlistItem`)
 * and a soft delete (`Spot`, `Review`, `City`) alike — the same reasoning as
 * `ReviewObserver`'s recompute: one code path has to be right regardless of
 * how the delete arrived (API, sync push, an admin action).
 *
 * A `Follow` writes two rows, one under each party's `user_id`, so the edge's
 * removal reaches both the follower's and the followee's delta. A global
 * reference row (`City`) writes `user_id = null`, which `SyncController`
 * reads back for every caller.
 *
 * This writes to its own append-only log with a plain `create()`, not
 * `CrudService` — there is no user "intent" to authorize here, the same
 * reasoning `ReviewObserver` documents for its own raw write.
 */
class SyncTombstoneObserver
{
    public function deleted(Model $model): void
    {
        $alias = $model::morphAlias();
        $uuid = $model->getAttribute('uuid');
        $organizationId = $model->getAttribute('organization_id');
        $deletedAt = $model->getAttribute('deleted_at') ?? now();

        if ($model instanceof Follow) {
            $this->write($alias, $uuid, $model->follower_id, $organizationId, $deletedAt);
            $this->write($alias, $uuid, $model->followee_id, $organizationId, $deletedAt);

            return;
        }

        // City (global reference data) has no `user_id` column, so this reads
        // as `null` — deliberately, per SyncRegistry's global-scope handling.
        $userId = $model->getAttribute('user_id');

        $this->write($alias, $uuid, $userId, $organizationId, $deletedAt);
    }

    private function write(string $entityType, string $entityUuid, ?int $userId, ?int $organizationId, mixed $deletedAt): void
    {
        SyncTombstone::query()->create([
            'entity_type' => $entityType,
            'entity_uuid' => $entityUuid,
            'user_id' => $userId,
            'organization_id' => $organizationId,
            'deleted_at' => $deletedAt,
        ]);
    }
}
