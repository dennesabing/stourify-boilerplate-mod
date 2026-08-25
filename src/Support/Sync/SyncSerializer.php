<?php

declare(strict_types=1);

namespace Modules\Stourify\Support\Sync;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Flattens a synced model into the wire row `offline-sync-core` expects: an
 * explicit column allowlist, raw integer `id`/FKs (the client stores these as
 * `server_id` and resolves relations by them — see the package's README §5),
 * JSON columns as arrays, and timestamps as ISO8601 strings.
 *
 * Deliberately not an API Resource: a resource's `can` key and uuid-keyed
 * relations are a *client-facing read* shape, not the *storage* shape the
 * local WatermelonDB row mirrors. Emitting either here would leak into the
 * client's local schema.
 */
final class SyncSerializer
{
    /**
     * The syncable column allowlist per table — spec §3. Nothing outside this
     * list is ever emitted, and this is the only place a new synced column is
     * declared.
     *
     * @var array<string, list<string>>
     */
    private const COLUMNS = [
        'sto_spots' => [
            'id', 'uuid', 'organization_id', 'user_id', 'city_id', 'owner_user_id',
            'title', 'slug', 'description', 'latitude', 'longitude', 'address',
            'categories', 'hours', 'status', 'is_verified',
            'rating_average', 'reviews_count', 'saves_count',
            // Not a stored column -- an accessor on the model. The delta speaks
            // in flat rows and a spot's photos live in another table, so without
            // this the app's own list of its spots has no picture to draw
            // (STOURIFY-192). Requires `media` eager-loaded; `SyncRegistry::eagerLoad()`
            // does it, and reading it off an unloaded model is a query per spot.
            'cover_photo_url',
            'created_at', 'updated_at', 'deleted_at',
        ],
        'sto_reviews' => [
            'id', 'uuid', 'organization_id', 'user_id', 'spot_id',
            'rating', 'body', 'helpful_count',
            'created_at', 'updated_at', 'deleted_at',
        ],
        'sto_wishlist_items' => [
            'id', 'uuid', 'organization_id', 'user_id', 'spot_id', 'city_id',
            'note', 'is_downloaded_offline',
            'created_at', 'updated_at',
        ],
        'sto_follows' => [
            'id', 'uuid', 'organization_id', 'follower_id', 'followee_id',
            'status', 'accepted_at',
            'created_at', 'updated_at',
        ],
        'sto_explorer_profiles' => [
            'id', 'uuid', 'organization_id', 'user_id', 'home_city_id',
            'username', 'bio', 'website', 'interests',
            'spots_count', 'followers_count', 'following_count',
            'is_private', 'shows_location_on_spots',
            'created_at', 'updated_at',
        ],
        'sto_cities' => [
            'id', 'uuid', 'organization_id',
            'name', 'slug', 'region', 'country', 'latitude', 'longitude',
            'spot_count', 'is_featured',
            'created_at', 'updated_at', 'deleted_at',
        ],
    ];

    /**
     * Columns the client resolves numeric relations by — forced to `int` here
     * regardless of what the PDO driver hands back, since MySQL can return an
     * integer column as a numeric string.
     *
     * @var list<string>
     */
    private const INTEGER_COLUMNS = [
        'id', 'organization_id', 'user_id', 'city_id', 'owner_user_id',
        'spot_id', 'follower_id', 'followee_id', 'home_city_id',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function forTable(string $table, Model $model): array
    {
        $columns = self::COLUMNS[$table] ?? throw new InvalidArgumentException("Unknown sync table: {$table}");

        $row = [];

        foreach ($columns as $column) {
            $row[$column] = self::normalize($column, $model->getAttribute($column));
        }

        return $row;
    }

    private static function normalize(string $column, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (in_array($column, self::INTEGER_COLUMNS, true)) {
            return (int) $value;
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->toIso8601String();
        }

        return $value;
    }
}
