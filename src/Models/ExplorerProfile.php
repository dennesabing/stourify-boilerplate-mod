<?php

declare(strict_types=1);

namespace Modules\Stourify\Models;

use App\Models\User;
use App\Traits\BelongsToOrganization;
use App\Traits\Cacheable;
use App\Traits\HasPermissionPrefix;
use App\Traits\HasUuid;
use App\Traits\OrganizationSearchable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Stourify\Database\Factories\ExplorerProfileFactory;

/**
 * The Stourify half of a user's identity — handle, bio, home city, interests.
 *
 * Deliberately separate from the boilerplate's core UserProfile: that one
 * holds platform data (mobile number, date of birth), this one holds domain
 * data, and domain data lives in the module.
 *
 * @use HasFactory<ExplorerProfileFactory>
 */
class ExplorerProfile extends Model
{
    use BelongsToOrganization, Cacheable, HasFactory, HasPermissionPrefix, HasUuid,
        OrganizationSearchable;

    protected $table = 'sto_explorer_profiles';

    /**
     * @var array<int, string>
     */
    protected array $invalidatesCachesOf = ['user'];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'organization_id',
        'user_id',
        'home_city_id',
        'username',
        'bio',
        'website',
        'interests',
        'is_private',
        'shows_location_on_spots',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'interests' => 'array',
            'is_private' => 'boolean',
            'shows_location_on_spots' => 'boolean',
        ];
    }

    /**
     * Throw away the cached lists that can hand out a spot's position, the
     * moment `shows_location_on_spots` stops being what they were built from.
     *
     * A shop puts its opening hours on a board outside. Change the hours inside
     * and the board keeps telling the street the old ones until somebody walks
     * out and repaints it. Every spot list in this module is that board: the
     * result is cached, so both halves of the location guarantee — the query
     * that drops a hidden contributor's spots out of a radius search, and the
     * resource that omits the two coordinate keys — only run on a cache miss.
     * Until this hook existed, turning the setting off took effect whenever the
     * cache happened to expire, which is not a promise anybody can rely on
     * (STOURIFY-244).
     *
     * This is the one setting in the module where being late is a safety
     * problem rather than a freshness one. Somebody hides their position
     * because they have a reason to; "it will be off within the hour" is not an
     * answer to that.
     */
    protected static function booted(): void
    {
        static::saved(function (self $profile): void {
            // Eloquent only works out what changed on an UPDATE, so
            // `wasChanged()` is false straight after an insert. The second
            // clause catches the contributor who publishes spots first and
            // fills in their profile — already switched off — afterwards.
            if ($profile->wasChanged('shows_location_on_spots')
                || ($profile->wasRecentlyCreated && $profile->shows_location_on_spots === false)) {
                self::forgetCachedSpotLocations();
            }
        });

        static::deleted(function (self $profile): void {
            // A spot whose contributor has no profile row at all shows its
            // coordinates — the default STOURIFY-185 chose, so that the change
            // did not strip the position off most of the catalogue on the day
            // it merged. Deleting a profile therefore makes positions MORE
            // visible, and a cache still holding the hidden answer is wrong in
            // the other direction: it hides a real place from the map.
            self::forgetCachedSpotLocations();
        });
    }

    /**
     * Clear every cached list whose rows can carry a spot's coordinates.
     *
     * Three families, and the second and third are the ones easy to miss.
     * `Post` and `WishlistItem` look like somebody else's business until you
     * notice what their cached rows contain: `PostResource` and
     * `WishlistItemResource` each nest a `SpotResource`, and `Spot` eager-loads
     * `contributorProfile` on every query (STOURIFY-185 put it on the model to
     * kill an N+1) — so a cached post carries a frozen copy of this very flag.
     *
     * Clearing by tag is what makes this affordable. Every entry written by
     * `Spot::getCachedList(...)` carries the tag `Spot:list` whoever it was
     * built for, so one call reaches every viewer's entry at once. Working out
     * *which* entries mention this contributor is not a question a tag store
     * can answer, and it does not need to be: somebody opening Settings and
     * tapping a switch is a rare event, and a rare write is the cheapest place
     * to hang work off.
     *
     * `Review` and `SpotAbout` are deliberately absent. Both eager-load `spot`,
     * but neither resource renders it, so neither can leak a position.
     */
    private static function forgetCachedSpotLocations(): void
    {
        Spot::clearListCache();
        Post::clearListCache();
        WishlistItem::clearListCache();
    }

    protected static function newFactory(): ExplorerProfileFactory
    {
        return ExplorerProfileFactory::new();
    }

    public static function morphAlias(): string
    {
        return 'stourify_explorer_profile';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function homeCity(): BelongsTo
    {
        return $this->belongsTo(City::class, 'home_city_id');
    }

    public function searchableAs(): string
    {
        return 'sto_explorer_profiles';
    }

    /**
     * People search matches the handle and the bio — the two things a person
     * types when looking for someone. Email is never indexed: it lives on
     * `User`, not here, and must not become searchable through the back door.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'organization_id' => $this->organization_id,
            'user_id' => $this->user_id,
            'username' => $this->username,
            'bio' => $this->bio,
        ];
    }
}
