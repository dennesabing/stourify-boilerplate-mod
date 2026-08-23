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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Stourify\Database\Factories\PostFactory;
use Modules\Stourify\Enums\FollowStatus;
use Modules\Stourify\Enums\PostVisibility;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * One explorer's visit to a spot — the unit the Home Feed renders.
 *
 * @use HasFactory<PostFactory>
 */
class Post extends Model implements HasMedia
{
    use BelongsToOrganization, Cacheable, HasComments, HasFactory, HasOrganizationMedia,
        HasPermissionPrefix, HasReactions, HasTags, HasUuid, SoftDeletes;

    /**
     * A post's only reaction is a "like". The platform's reaction subsystem
     * supports a richer set (love, haha, …); a post narrows it to one, so
     * `likes_count` is unambiguous and the UI is a single heart. Widening this
     * later is a deliberate product decision, not an accident of the default.
     */
    public const LIKE_REACTION = 'like';

    protected $table = 'sto_posts';

    /**
     * Busting a post's cache must also bust its spot's — the Spot Hub renders
     * the post's media in its gallery.
     *
     * @var array<string, string>
     */
    protected array $invalidatesRelationCachesOf = ['spot' => 'posts'];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'organization_id',
        'user_id',
        'spot_id',
        'caption',
        'visibility',
        'published_at',
        // The name the CLIENT put on the create request, so a retry after a
        // lost response is recognised rather than made into a second post.
        // Never read by anything that renders a post — see the migration that
        // adds the column, and PostApiController::store().
        'idempotency_key',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'visibility' => PostVisibility::class,
            'published_at' => 'datetime',
        ];
    }

    protected static function newFactory(): PostFactory
    {
        return PostFactory::new();
    }

    public static function morphAlias(): string
    {
        return 'stourify_post';
    }

    /**
     * `thumb` feeds the feed row and gallery strip; `medium` is the full-post
     * hero view. Same dimensions as `Spot` — a photo often flows from a post
     * into its spot's gallery and should not visibly downgrade in transit.
     * Both are scoped to `attachments` — the only collection a post's photos
     * ever land in (see HasOrganizationMedia).
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
     * A post accepts only the "like" reaction — see LIKE_REACTION. The base
     * trait would otherwise accept the whole configured set.
     *
     * @return list<string>
     */
    public function supportedReactions(): array
    {
        return [self::LIKE_REACTION];
    }

    /**
     * The explorer whose visit this post records.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function spot(): BelongsTo
    {
        return $this->belongsTo(Spot::class);
    }

    /**
     * Posts that have finished uploading their media and are publicly visible.
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->whereNotNull('published_at')
            ->where('visibility', PostVisibility::Public->value);
    }

    /**
     * Constrain a query to the posts a given viewer is entitled to see.
     *
     * This is the single definition of the post audience rule. The post index
     * and the home feed both call it, so the two enforcement surfaces cannot
     * drift — if they disagreed, whichever was more permissive would leak.
     * `PostPolicy::view()` encodes the same rule per-record; the policy tests
     * assert the two agree across every visibility combination.
     *
     * A viewer always sees their own posts, published or not. Beyond that:
     * published public posts, plus published followers-only posts by authors
     * this viewer *actively* follows — a pending request grants nothing, and
     * the follow edge is directional (`follower_id` is the viewer). Private
     * posts by other authors never appear, by construction: no branch admits
     * them.
     *
     * This intentionally carries NO moderator bypass. Seeing every restricted
     * post is a management concern the index layers on top via
     * `viewAnyRestricted`; a moderator's *feed* is still just their feed.
     *
     * Blocks cut across all of it, outside the audience branches rather than
     * inside them. A block is symmetric — it hides the blocked party's posts
     * from the blocker AND the blocker's from the blocked party — so it cannot
     * be expressed as a condition on visibility or on the follow edge, both of
     * which are directional. Stripping the authors first also means no later
     * branch can accidentally admit one back.
     *
     * @param  Builder<Post>  $query
     * @return Builder<Post>
     */
    public function scopeVisibleTo(Builder $query, User $viewer): Builder
    {
        $followedAuthorIds = Follow::query()
            ->where('follower_id', $viewer->id)
            ->where('status', FollowStatus::Active->value)
            ->pluck('followee_id');

        return $query
            ->whereNotIn('user_id', Block::hiddenUserIdsFor($viewer))
            ->where(fn (Builder $scoped) => $scoped
                ->where('user_id', $viewer->id)
                ->orWhere(fn (Builder $others) => $others
                    ->whereNotNull('published_at')
                    ->where(fn (Builder $audience) => $audience
                        ->where('visibility', PostVisibility::Public->value)
                        ->orWhere(fn (Builder $followers) => $followers
                            ->where('visibility', PostVisibility::Followers->value)
                            ->whereIn('user_id', $followedAuthorIds)))));
    }
}
