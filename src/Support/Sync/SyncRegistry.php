<?php

declare(strict_types=1);

namespace Modules\Stourify\Support\Sync;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use Modules\Stourify\Models\City;
use Modules\Stourify\Models\ExplorerProfile;
use Modules\Stourify\Models\Follow;
use Modules\Stourify\Models\Review;
use Modules\Stourify\Models\Spot;
use Modules\Stourify\Models\WishlistItem;

/**
 * The single source of truth for the six tables the offline-sync delta/push
 * contract carries — see docs/superpowers/specs/2026-07-25-m2a-backend-sync-
 * contract-design.md §2. `SyncController`, `SyncSerializer` and the test suite
 * all read this instead of hardcoding the table list, so adding or retiring a
 * synced table has exactly one place to change the *set*.
 *
 * Per-table row shaping (FK resolution, slugs, defaults) still lives beside
 * each entity's own write path in `SyncController` — that logic is inherently
 * per-entity, the same way `SpotApiController::resolveRelations()` cannot be
 * generic either.
 */
final class SyncRegistry
{
    /**
     * @var array<string, array{model: class-string, pushable: bool}>
     */
    private const TABLES = [
        'sto_spots' => ['model' => Spot::class, 'pushable' => true],
        'sto_reviews' => ['model' => Review::class, 'pushable' => true],
        'sto_wishlist_items' => ['model' => WishlistItem::class, 'pushable' => true],
        'sto_follows' => ['model' => Follow::class, 'pushable' => true],
        'sto_explorer_profiles' => ['model' => ExplorerProfile::class, 'pushable' => true],
        // Curated reference data — the caller reads it, but never writes it.
        'sto_cities' => ['model' => City::class, 'pushable' => false],
    ];

    /**
     * @return list<string>
     */
    public static function tables(): array
    {
        return array_keys(self::TABLES);
    }

    public static function has(string $table): bool
    {
        return array_key_exists($table, self::TABLES);
    }

    /**
     * @return class-string
     */
    public static function model(string $table): string
    {
        return self::TABLES[$table]['model'] ?? throw new InvalidArgumentException("Unknown sync table: {$table}");
    }

    public static function isPushable(string $table): bool
    {
        return self::TABLES[$table]['pushable'] ?? false;
    }

    /**
     * Restrict a query for this table to what the caller's delta/push may see.
     *
     * Mirrors spec §2's scope column: owned tables are scoped to the caller,
     * a follow is scoped to either side of the edge, and cities are global
     * reference data everyone reads unfiltered.
     *
     * @param  Builder<*>  $query
     * @return Builder<*>
     */
    public static function scope(string $table, Builder $query, User $user): Builder
    {
        return match ($table) {
            'sto_spots', 'sto_reviews', 'sto_wishlist_items', 'sto_explorer_profiles' => $query->where('user_id', $user->id),
            'sto_follows' => $query->where(fn (Builder $edge) => $edge
                ->where('follower_id', $user->id)
                ->orWhere('followee_id', $user->id)),
            'sto_cities' => $query,
            default => throw new InvalidArgumentException("Unknown sync table: {$table}"),
        };
    }

    /**
     * Restrict a `sto_sync_tombstones` query to this table's tombstones for
     * the caller. Cities are global, so their tombstones carry `user_id = null`
     * rather than any one caller's id — see `SyncTombstoneObserver`.
     *
     * @param  Builder<*>  $query
     * @return Builder<*>
     */
    public static function tombstoneScope(string $table, Builder $query, User $user): Builder
    {
        return match ($table) {
            'sto_cities' => $query->whereNull('user_id'),
            'sto_spots', 'sto_reviews', 'sto_wishlist_items', 'sto_follows', 'sto_explorer_profiles' => $query->where('user_id', $user->id),
            default => throw new InvalidArgumentException("Unknown sync table: {$table}"),
        };
    }
}
