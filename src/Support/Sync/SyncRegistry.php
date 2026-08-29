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
     * Relations a delta query for this table must load up front.
     *
     * A delta fetches every changed row at once and then serialises them one by
     * one. Anything a serialised row reads from a RELATION is therefore a query
     * per row unless it was loaded in advance — a hundred spots become a hundred
     * and one queries, and the cost lands on the sync path, which is the least
     * observed part of the product.
     *
     * `sto_spots` carries `cover_photo_url`, which reads the spot's media, so
     * that is what this exists for (STOURIFY-192). Everything else loads nothing
     * and gets an empty list.
     *
     * @return list<string>
     */
    public static function eagerLoad(string $table): array
    {
        return match ($table) {
            'sto_spots' => ['media'],
            default => [],
        };
    }

    /**
     * Restrict a query for this table to what the caller's delta/push may see.
     *
     * Mirrors spec §2's scope column: owned tables are scoped to the caller,
     * a follow is scoped to either side of the edge, and cities are global
     * reference data everyone reads unfiltered.
     *
     * **This is also a privacy control, and it does not look like one.**
     * `sto_spots` being scoped to `user_id` is what keeps the promise a
     * contributor makes when they turn off `shows_location_on_spots`: the only
     * device a spot's coordinates are ever synced to belongs to the person who
     * put the spot there, and that person is exactly who is entitled to see
     * them (`Spot::locationHiddenFrom()`). Every REST path withholds a hidden
     * position (STOURIFY-185); the delta withholds the whole row, which is
     * stronger. `SyncSerializer` therefore lists `latitude` and `longitude`
     * unconditionally and is right to — it is handed rows, it does not choose
     * them.
     *
     * So a widening here is not a synchronisation change. Shipping the spots of
     * people you follow, so they can be read offline, is an ordinary thing this
     * product may eventually want, and it would reopen a leak three cards were
     * spent closing. If you widen it, two things must change with it, IN THIS
     * ORDER (STOURIFY-187):
     *
     *   1. The device's WatermelonDB schema (`mobile/src/db/schema.ts`) has to
     *      accept a spot with no coordinates — `latitude` and `longitude` become
     *      `isOptional`. This comes first.
     *   2. Only then may `SyncSerializer` omit the two keys for a spot whose
     *      contributor hid its position, which needs `contributorProfile`
     *      eager-loaded in `eagerLoad()` above or it is a query per row.
     *
     * The reverse order is the trap, because it fails silently: WatermelonDB
     * does not reject a `null` in a non-optional number column, it stores `0`,
     * and the app then draws a pin at (0, 0) in the Atlantic.
     *
     * `SpotLocationPrivacyTest` §*Path 4* is the tripwire on all of the above.
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
