<?php

declare(strict_types=1);

namespace Modules\Stourify;

use App\Providers\ModuleBaseServiceProvider;
use App\Registries\ModuleRegistry;
use Illuminate\Database\Eloquent\Relations\Relation;
use Modules\Stourify\Models\City;
use Modules\Stourify\Models\ExplorerProfile;
use Modules\Stourify\Models\Follow;
use Modules\Stourify\Models\Post;
use Modules\Stourify\Models\Report;
use Modules\Stourify\Models\Review;
use Modules\Stourify\Models\Spot;
use Modules\Stourify\Models\WishlistItem;
use Modules\Stourify\Observers\ReviewObserver;
use Modules\Stourify\Policies\FollowPolicy;
use Modules\Stourify\Policies\PostPolicy;
use Modules\Stourify\Policies\ReviewPolicy;
use Modules\Stourify\Policies\SpotPolicy;

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
     * @return array<class-string, class-string>
     */
    protected function policyMap(): array
    {
        return [
            Spot::class => SpotPolicy::class,
            Review::class => ReviewPolicy::class,
            Post::class => PostPolicy::class,
            Follow::class => FollowPolicy::class,
        ];
    }
}
