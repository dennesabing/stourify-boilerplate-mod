<?php

declare(strict_types=1);

namespace Modules\Stourify\Models;

use App\Models\User;
use App\Traits\BelongsToOrganization;
use App\Traits\Cacheable;
use App\Traits\HasComments;
use App\Traits\HasOrganizationMedia;
use App\Traits\HasPermissionPrefix;
use App\Traits\HasReactions;
use App\Traits\HasTags;
use App\Traits\HasUuid;
use App\Traits\OrganizationSearchable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Stourify\Database\Factories\SpotFactory;
use Modules\Stourify\Enums\SpotStatus;
use Spatie\MediaLibrary\HasMedia;

/**
 * A place worth going to, contributed by an explorer.
 *
 * Photos, tags, comments and likes are core attachables — see
 * saas-boilerplate/docs/system-wide-docs/system-attachables.md. Writes to any
 * of them go through CrudService, never the relationship.
 *
 * @use HasFactory<SpotFactory>
 */
class Spot extends Model implements HasMedia
{
    use BelongsToOrganization, Cacheable, HasComments, HasFactory, HasOrganizationMedia,
        HasPermissionPrefix, HasReactions, HasTags, HasUuid, OrganizationSearchable, SoftDeletes;

    protected $table = 'sto_spots';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'organization_id',
        'user_id',
        'city_id',
        'title',
        'slug',
        'description',
        'latitude',
        'longitude',
        'address',
        'categories',
        'hours',
        'status',
        'owner_user_id',
        'is_verified',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'categories' => 'array',
            'hours' => 'array',
            'status' => SpotStatus::class,
            'is_verified' => 'boolean',
            'rating_average' => 'float',
        ];
    }

    /**
     * Module factories live outside Database\Factories, so HasFactory's
     * convention-based lookup cannot find them.
     */
    protected static function newFactory(): SpotFactory
    {
        return SpotFactory::new();
    }

    public static function morphAlias(): string
    {
        return 'stourify_spot';
    }

    /**
     * The explorer who contributed this spot.
     *
     * Distinct from `owner`: the contributor is whoever added the place, and
     * never changes. `owner_user_id` is set only when a business claims the
     * spot post-beta, and governs the commercial surface, not the content.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    public function wishlistItems(): HasMany
    {
        return $this->hasMany(WishlistItem::class);
    }

    /**
     * Spots visible in discovery surfaces.
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->whereIn('status', SpotStatus::discoverable());
    }

    /**
     * Spots within `$radiusKm` of a point, nearest first.
     *
     * Two deliberate choices, both about portability (spec §5 — no PostGIS):
     *
     * 1. A bounding box does the filtering, so the (latitude, longitude)
     *    composite index is usable. A trig-based distance predicate would
     *    force a full scan.
     * 2. Ordering uses *squared planar* distance with the longitude axis
     *    scaled by cos(latitude), computed in PHP from the query centre. It
     *    contains no trig and no square root, so it runs identically on
     *    MySQL 8 and SQLite — many SQLite builds ship without SIN/COS/SQRT.
     *    Squared distance is monotonic with true distance, so the ordering is
     *    exact even though the value is not a distance in kilometres.
     *
     * Accurate to well under a percent at city scale, which is all the beta
     * needs. Revisit if radii ever exceed a few hundred kilometres.
     */
    public function scopeNearby(Builder $query, float $latitude, float $longitude, float $radiusKm = 5.0): Builder
    {
        $kmPerDegreeLat = 111.32;
        $kmPerDegreeLng = $kmPerDegreeLat * max(cos(deg2rad($latitude)), 0.01);

        $latDelta = $radiusKm / $kmPerDegreeLat;
        $lngDelta = $radiusKm / $kmPerDegreeLng;

        return $query
            ->whereBetween('latitude', [$latitude - $latDelta, $latitude + $latDelta])
            ->whereBetween('longitude', [$longitude - $lngDelta, $longitude + $lngDelta])
            ->orderByRaw(
                '((latitude - ?) * ?) * ((latitude - ?) * ?) + ((longitude - ?) * ?) * ((longitude - ?) * ?) asc',
                [
                    $latitude, $kmPerDegreeLat, $latitude, $kmPerDegreeLat,
                    $longitude, $kmPerDegreeLng, $longitude, $kmPerDegreeLng,
                ]
            );
    }

    public function searchableAs(): string
    {
        return 'sto_spots';
    }

    /**
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'organization_id' => $this->organization_id,
            'title' => $this->title,
            'description' => $this->description,
            'address' => $this->address,
            'categories' => $this->categories,
            'city_id' => $this->city_id,
            'status' => $this->status?->value,
        ];
    }
}
