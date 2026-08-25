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
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Stourify\Database\Factories\SpotFactory;
use Modules\Stourify\Enums\SpotStatus;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

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
     * Always loaded, because every rendered spot asks whether its contributor
     * hid the location and the answer lives on another table.
     *
     * This is on the model rather than on each caller's `with()` on purpose. A
     * spot is nested inside posts, reviews, wishlist items and the feed, and a
     * `locationHiddenFrom()` that has to fall back to a lazy query costs one
     * query per row — an N+1 the feed's own query-count test caught the moment
     * it appeared. Fixing it at six call sites would leave the seventh to
     * whoever writes it next; fixing it here cannot be forgotten.
     *
     * @var list<string>
     */
    protected $with = ['contributorProfile'];

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
     * `thumb` feeds the discovery grid and the gallery strip; `medium` is the
     * spot hero. Both are scoped to `attachments` — the only collection a
     * spot's photos ever land in (see HasOrganizationMedia).
     */
    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->width(400)
            ->height(400)
            ->sharpen(10)
            ->performOnCollections('attachments');

        $this->addMediaConversion('medium')
            ->width(1080)
            ->height(1080)
            ->sharpen(10)
            ->performOnCollections('attachments');
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

    /**
     * The contributor's explorer profile, joined on `user_id` rather than
     * through the user.
     *
     * It exists for one question — has this contributor hidden the location of
     * their spots? — and the shape is what keeps that question cheap. Reaching
     * it as `user.explorerProfile` would mean eager-loading two relations on
     * every list of spots to read one boolean; a direct `hasOne` on the shared
     * `user_id` reads it in one.
     */
    public function contributorProfile(): HasOne
    {
        return $this->hasOne(ExplorerProfile::class, 'user_id', 'user_id');
    }

    /**
     * Is this spot's position withheld from `$viewer`?
     *
     * `shows_location_on_spots` used to be a curtain rail with no curtain: the
     * column was stored, synced and returned to its owner, and read by nothing,
     * so every caller received exact coordinates regardless (STOURIFY-185).
     *
     * Three rules, and the last two matter as much as the first:
     *
     *   - A contributor with NO profile row shows their location. The flag
     *     defaults to `true`, and most spots predate any profile; if absence
     *     read as "hidden" this would have stripped coordinates from most of the
     *     catalogue the day it merged.
     *   - The contributor always sees their own.
     *   - A moderator always sees them. A report about a spot is frequently a
     *     report about WHERE it is, and a queue that cannot see the location
     *     cannot act on it. This reuses `viewAnyDraft`, the ability the policy
     *     already exposes for exactly this kind of elevated visibility, rather
     *     than inventing a second moderator test.
     */
    public function locationHiddenFrom(?User $viewer): bool
    {
        $profile = $this->relationLoaded('contributorProfile')
            ? $this->getRelation('contributorProfile')
            : $this->contributorProfile()->first();

        if ($profile === null || $profile->shows_location_on_spots !== false) {
            return false;
        }

        if ($viewer === null) {
            return true;
        }

        return $viewer->id !== $this->user_id && ! $viewer->can('viewAnyDraft', self::class);
    }

    /**
     * Drop spots whose contributor hid their location, unless `$viewer` is
     * entitled to see them.
     *
     * This is the half that is easy to leave out, and leaving it out makes the
     * rest decorative: membership of a radius result IS a position. A row that
     * carries no `latitude` but still answers "is this spot within 2 km of
     * here?" discloses the same fact in three requests instead of one.
     *
     * @param  Builder<Spot>  $query
     * @return Builder<Spot>
     */
    public function scopeWithLocationVisibleTo(Builder $query, ?User $viewer): Builder
    {
        if ($viewer !== null && $viewer->can('viewAnyDraft', self::class)) {
            return $query;
        }

        return $query->where(fn (Builder $scoped) => $scoped
            ->when($viewer !== null, fn (Builder $q) => $q->orWhere('user_id', $viewer->id))
            ->orWhereNotExists(fn ($sub) => $sub
                ->selectRaw('1')
                ->from('sto_explorer_profiles')
                ->whereColumn('sto_explorer_profiles.user_id', 'sto_spots.user_id')
                ->where('sto_explorer_profiles.shows_location_on_spots', false)));
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    /**
     * What visitors have written about this spot — the corkboard beside the
     * spot's own `description`. Many per spot, one author each.
     */
    public function abouts(): HasMany
    {
        return $this->hasMany(SpotAbout::class);
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

    /**
     * The one photo that stands for this spot in a list, as a URL — or `null`.
     *
     * This is not a stored column. It exists because the offline sync speaks in
     * flat rows of columns, and a spot's photos live in a separate table it does
     * not carry. Without it the app's own "My spots" list could only ever draw
     * grey rectangles: it reads the local database, and the local database had
     * no photo in it to read (STOURIFY-192).
     *
     * The thumbnail is preferred over the full image deliberately. A list draws
     * a 96-pixel square; the originals here run to two megabytes each, and a
     * list of twenty would pull forty megabytes over a phone connection to show
     * a column of thumbnails. Where no thumbnail was generated the full image is
     * better than a blank, so it falls back rather than giving up.
     *
     * Callers must eager-load `media` — `SyncRegistry::eagerLoad()` does, for
     * exactly this reason. Reading it off an unloaded model is a query per spot.
     */
    public function getCoverPhotoUrlAttribute(): ?string
    {
        $media = $this->getMedia('attachments')->first();

        if ($media === null) {
            return null;
        }

        // Hand the photo its own host back before asking for a URL.
        //
        // Storage paths here are organisation-scoped and built from the model
        // the photo hangs off (`SpacesPathGenerator`), so building a URL reads
        // `$media->model` -- and on a photo that arrived through an eager load,
        // that relation is not loaded and fetches the spot back from the
        // database. One query per photo, and each spot fetched that way then
        // pulls its own contributor profile, so it is two.
        //
        // We are that spot. Saying so costs nothing and removes both queries.
        // Measured on a delta of ten spots: 33 queries before, 13 after.
        $media->setRelation('model', $this);

        return $media->hasGeneratedConversion('thumb')
            ? $media->getUrl('thumb')
            : $media->getUrl();
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
