<?php

declare(strict_types=1);

namespace Modules\Stourify\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per delete, read back by `SyncController::delta()` as the `deleted`
 * half of each table's delta.
 *
 * Deliberately a bare model: no `HasUuid` (nothing references a tombstone by
 * its own identity), no `Cacheable` (write-once, read by cursor — caching a
 * per-user, per-cursor query would never hit), and no `BelongsToOrganization`
 * (an internal append-only log, not a domain entity a caller queries
 * directly) — every caller of this model filters `organization_id` and
 * `user_id` explicitly instead. See the migration and `SyncTombstoneObserver`.
 */
class SyncTombstone extends Model
{
    protected $table = 'sto_sync_tombstones';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'entity_type',
        'entity_uuid',
        'user_id',
        'organization_id',
        'deleted_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'deleted_at' => 'datetime',
        ];
    }
}
