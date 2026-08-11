<?php

declare(strict_types=1);

namespace Modules\Stourify;

use App\Events\Domain\UserDeleted;
use App\Events\Domain\UserRegistered;
use App\Models\Media;
use App\Models\Reaction;
use App\Providers\ModuleBaseServiceProvider;
use App\Registries\ModuleRegistry;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;
use Modules\Stourify\Listeners\JoinPublicOrganizationAsExplorer;
use Modules\Stourify\Listeners\RemoveExplorerContentOnUserDeleted;
use Modules\Stourify\Models\Block;
use Modules\Stourify\Models\City;
use Modules\Stourify\Models\ExplorerProfile;
use Modules\Stourify\Models\Follow;
use Modules\Stourify\Models\Post;
use Modules\Stourify\Models\Report;
use Modules\Stourify\Models\Review;
use Modules\Stourify\Models\Spot;
use Modules\Stourify\Models\WishlistItem;
use Modules\Stourify\Observers\ReactionCountObserver;
use Modules\Stourify\Observers\ReviewObserver;
use Modules\Stourify\Observers\SyncTombstoneObserver;
use Modules\Stourify\Policies\BlockPolicy;
use Modules\Stourify\Policies\ExplorerProfilePolicy;
use Modules\Stourify\Policies\FollowPolicy;
use Modules\Stourify\Policies\PostPolicy;
use Modules\Stourify\Policies\ReportPolicy;
use Modules\Stourify\Policies\ReviewPolicy;
use Modules\Stourify\Policies\SpotPolicy;
use Modules\Stourify\Policies\StourifyMediaPolicy;
use Modules\Stourify\Policies\WishlistItemPolicy;
use Modules\Stourify\Support\Sync\SyncRegistry;

/**
 * Wires the Stourify module: routes, migrations, policies, morph aliases.
 *
 * The base class no-ops entirely when MODULE_STOURIFY_ENABLED is not true,
 * so a disabled module loads nothing.
 */
class StourifyServiceProvider extends ModuleBaseServiceProvider
{
    /**
     * Models that can be a polymorphic target or subject — media, comments,
     * reactions and reports all persist a `*_type` string for these.
     *
     * Each declares its own alias via `morphAlias()`; this list only says
     * which models to collect, so the alias has exactly one declaration site
     * and cannot drift from the class it names.
     *
     * @var array<int, class-string>
     */
    private const MORPH_MODELS = [
        Spot::class,
        Post::class,
        Review::class,
        City::class,
        Follow::class,
        Block::class,
        WishlistItem::class,
        ExplorerProfile::class,
        Report::class,
    ];

    /**
     * Register the morph map.
     *
     * Without it those `*_type` columns store the FQCN, so moving or renaming
     * a class silently orphans every attachment already written. The alias is
     * the contract; the namespace is an implementation detail.
     */
    public function boot(): void
    {
        parent::boot();

        if (! app(ModuleRegistry::class)->isEnabled('stourify')) {
            return;
        }

        $map = [];

        foreach (self::MORPH_MODELS as $modelClass) {
            $map[$modelClass::morphAlias()] = $modelClass;
        }

        Relation::morphMap($map);

        // Keeps sto_spots.rating_average and reviews_count truthful regardless
        // of how a review was written — API, sync push, seeder or factory.
        Review::observe(ReviewObserver::class);

        // Keeps sto_posts.likes_count and sto_reviews.helpful_count truthful as
        // reactions come and go through the platform's reaction endpoints.
        Reaction::observe(ReactionCountObserver::class);

        // Every newly-registered user becomes an explorer of the public org —
        // membership is required to act on any of its content.
        Event::listen(UserRegistered::class, JoinPublicOrganizationAsExplorer::class);

        // An explorer who deletes their account has their content withdrawn at
        // once, rather than staying published until the platform's retention
        // job erases it. The boilerplate announces the deletion; deciding what
        // it means for sto_* tables is this module's job, not the platform's.
        Event::listen(UserDeleted::class, RemoveExplorerContentOnUserDeleted::class);

        // Records one tombstone per delete — hard (Follow, WishlistItem) and
        // soft (Spot, Review, City) alike — so the offline-sync delta can
        // report a removal. Every table SyncRegistry carries needs one; see
        // SyncTombstoneObserver.
        foreach (SyncRegistry::tables() as $table) {
            SyncRegistry::model($table)::observe(SyncTombstoneObserver::class);
        }
    }

    protected function moduleClass(): string
    {
        return StourifyModule::class;
    }

    protected function moduleBasePath(): string
    {
        return dirname(__DIR__);
    }

    /**
     * Policies land here as each resource gains its API surface in M1.
     *
     * Media is the platform's model, not the module's, and it is deliberately
     * re-pointed at StourifyMediaPolicy: a `posts.media.create` role grant is
     * not scoped to a host instance, so something has to require write rights
     * on the host before a photo lands on it. That subclass defers to
     * App\Policies\MediaPolicy for every host this module does not own, so the
     * override is confined to Stourify's own hosts (STOURIFY-22).
     *
     * @return array<class-string, class-string>
     */
    protected function policyMap(): array
    {
        return [
            Spot::class => SpotPolicy::class,
            Review::class => ReviewPolicy::class,
            Post::class => PostPolicy::class,
            Follow::class => FollowPolicy::class,
            WishlistItem::class => WishlistItemPolicy::class,
            ExplorerProfile::class => ExplorerProfilePolicy::class,
            Report::class => ReportPolicy::class,
            Block::class => BlockPolicy::class,
            Media::class => StourifyMediaPolicy::class,
        ];
    }
}
