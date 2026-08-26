<?php

declare(strict_types=1);

namespace Modules\Stourify;

use App\Events\Domain\UserDeleted;
use App\Events\Domain\UserRegistered;
use App\Models\Media;
use App\Models\Reaction;
use App\Providers\ModuleBaseServiceProvider;
use App\Registries\LegalDocumentRegistry;
use App\Registries\ModuleRegistry;
use App\Support\LegalDocument;
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
use Modules\Stourify\Listeners\TouchSpotWhenItsPhotosChange;
use Spatie\MediaLibrary\Conversions\Events\ConversionHasBeenCompletedEvent;
use Spatie\MediaLibrary\MediaCollections\Events\MediaHasBeenAddedEvent;
use Modules\Stourify\Models\SpotAbout;
use Modules\Stourify\Models\WishlistItem;
use Modules\Stourify\Observers\HashtagObserver;
use Modules\Stourify\Observers\ReactionCountObserver;
use Modules\Stourify\Observers\ReviewObserver;
use Modules\Stourify\Observers\SyncTombstoneObserver;
use Modules\Stourify\Policies\BlockPolicy;
use Modules\Stourify\Policies\ExplorerProfilePolicy;
use Modules\Stourify\Policies\FollowPolicy;
use Modules\Stourify\Policies\PostPolicy;
use Modules\Stourify\Policies\ReportPolicy;
use Modules\Stourify\Policies\ReviewPolicy;
use Modules\Stourify\Policies\SpotAboutPolicy;
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
        SpotAbout::class,
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

        $this->registerLegalDocuments();

        // Keeps sto_spots.rating_average and reviews_count truthful regardless
        // of how a review was written — API, sync push, seeder or factory.
        Review::observe(ReviewObserver::class);

        // Keeps sto_posts.likes_count, sto_reviews.helpful_count and
        // sto_spot_abouts.likes_count truthful as reactions come and go
        // through the platform's reaction endpoints.
        Reaction::observe(ReactionCountObserver::class);

        // Turns the hashtags in a caption or a description into real, shared
        // tag rows — on every road into the database, which is the whole
        // reason it is an observer rather than controller code. See
        // HashtagObserver (STOURIFY-171).
        Post::observe(HashtagObserver::class);
        Spot::observe(HashtagObserver::class);

        // Every newly-registered user becomes an explorer of the public org —
        // membership is required to act on any of its content.
        Event::listen(UserRegistered::class, JoinPublicOrganizationAsExplorer::class);

        // An explorer who deletes their account has their content withdrawn at
        // once, rather than staying published until the platform's retention
        // job erases it. The boilerplate announces the deletion; deciding what
        // it means for sto_* tables is this module's job, not the platform's.
        Event::listen(UserDeleted::class, RemoveExplorerContentOnUserDeleted::class);

        // A spot's photos live in another table, and the offline sync only
        // resends a row whose `updated_at` moved -- so without this, a photo
        // attached seconds after the spot was created never reaches the device
        // at all. Not late: never. See TouchSpotWhenItsPhotosChange
        // (STOURIFY-208), which is why STOURIFY-192 did not work on a handset.
        Event::listen(MediaHasBeenAddedEvent::class, [TouchSpotWhenItsPhotosChange::class, 'onMediaAdded']);
        Event::listen(ConversionHasBeenCompletedEvent::class, [TouchSpotWhenItsPhotosChange::class, 'onConversionCompleted']);
        Media::deleted(fn (Media $media) => app(TouchSpotWhenItsPhotosChange::class)->onMediaDeleted($media));

        // Records one tombstone per delete — hard (Follow, WishlistItem) and
        // soft (Spot, Review, City) alike — so the offline-sync delta can
        // report a removal. Every table SyncRegistry carries needs one; see
        // SyncTombstoneObserver.
        foreach (SyncRegistry::tables() as $table) {
            SyncRegistry::model($table)::observe(SyncTombstoneObserver::class);
        }
    }

    /**
     * Publish the public legal documents into the platform's registry.
     *
     * The platform owns the URL shape (/privacy, /terms, /account-deletion and
     * /legal/{slug}) and the renderer; it has no idea what any product's policy
     * says. This module owns the words, which is why the bodies are markdown files
     * under resources/legal — a lawyer's revision should be a diff to prose.
     *
     * Google Play requires all three: a privacy-policy URL and a web-reachable
     * account-deletion URL for the listing, and terms for a user-generated-content
     * app. They must answer an unauthenticated GET, which is why the routes carry
     * no guard.
     *
     * `isPlaceholder: true` is what puts the visible "pending legal review" banner
     * on the page. It stays true until a lawyer has actually reviewed the text and
     * every [BRACKETED] value has been filled in — see STOURIFY-34.
     */
    private function registerLegalDocuments(): void
    {
        $dir = $this->moduleBasePath().'/resources/legal';
        $updated = '11 August 2026';

        app(LegalDocumentRegistry::class)->register(
            new LegalDocument(
                slug: 'privacy',
                title: 'Privacy Policy',
                path: $dir.'/privacy.md',
                updatedAt: $updated,
                isPlaceholder: true,
                summary: 'What Stourify collects, why, where it goes, and how to remove it.',
            ),
            new LegalDocument(
                slug: 'terms',
                title: 'Terms of Service',
                path: $dir.'/terms.md',
                updatedAt: $updated,
                isPlaceholder: true,
                summary: 'The rules for using Stourify and for the content you publish on it.',
            ),
            new LegalDocument(
                slug: 'account-deletion',
                title: 'Delete Your Account',
                path: $dir.'/account-deletion.md',
                updatedAt: $updated,
                isPlaceholder: true,
                summary: 'How to delete your Stourify account, and exactly what happens when you do.',
            ),
        );
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
            SpotAbout::class => SpotAboutPolicy::class,
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
