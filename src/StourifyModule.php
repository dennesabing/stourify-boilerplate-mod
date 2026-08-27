<?php

declare(strict_types=1);

namespace Modules\Stourify;

use App\Contracts\Module;
use App\Support\InjectedContent;
use App\Support\InjectedFormField;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Http\Request;
use Modules\Stourify\Database\Seeders\StourifyDemoContentSeeder;
use Modules\Stourify\Database\Seeders\StourifyExplorerBackfillSeeder;
use Modules\Stourify\Database\Seeders\StourifyPublicOrganizationSeeder;
use Modules\Stourify\Models\Block;
use Modules\Stourify\Models\City;
use Modules\Stourify\Models\ExplorerProfile;
use Modules\Stourify\Models\Follow;
use Modules\Stourify\Models\Post;
use Modules\Stourify\Models\Report;
use Modules\Stourify\Models\Review;
use Modules\Stourify\Models\Spot;
use Modules\Stourify\Models\SpotAbout;
use Modules\Stourify\Models\WishlistItem;

/**
 * Stourify — the local spot discovery domain.
 *
 * Publishes its permissions, searchable models and seeders; the boilerplate
 * discovers them. Nothing in saas-boilerplate knows this module exists.
 */
class StourifyModule implements Module
{
    public function name(): string
    {
        return 'stourify';
    }

    /**
     * @return array<int, string>
     */
    public function permissions(): array
    {
        return [
            // Spots — community-contributed places.
            'stourify.spots.view',
            'stourify.spots.create',
            'stourify.spots.update',
            'stourify.spots.delete',
            'stourify.spots.manage',   // moderator: edit/delete any spot, verify

            // About entries — community-written notes on a spot.
            'stourify.spot_abouts.view',
            'stourify.spot_abouts.create',
            'stourify.spot_abouts.update',
            'stourify.spot_abouts.delete',
            'stourify.spot_abouts.manage',

            // Posts — photo shares attached to a spot.
            'stourify.posts.view',
            'stourify.posts.create',
            'stourify.posts.update',
            'stourify.posts.delete',
            'stourify.posts.manage',

            // Reviews — rating + text on a spot.
            'stourify.reviews.view',
            'stourify.reviews.create',
            'stourify.reviews.update',
            'stourify.reviews.delete',
            'stourify.reviews.manage',

            // Social graph and saved places.
            'stourify.follows.manage',
            'stourify.wishlist.manage',

            // Cities — reference data, curated not user-generated.
            'stourify.cities.view',
            'stourify.cities.manage',

            // Moderation queue.
            'stourify.reports.create',
            'stourify.reports.manage',
        ];
    }

    /**
     * The permissions every explorer holds — the consumer role's grant.
     *
     * The module's own `stourify.*` permissions plus the *discovered* attachable
     * permissions on the Post, Spot, Review and SpotAbout hosts — reactions
     * (`posts.reactions.*`, `reviews.reactions.*`, `spot_abouts.reactions.*`) so
     * an explorer can like a post, mark a review helpful and endorse an About
     * entry, and media (`posts.media.*`, `spots.media.*`) so they can put photos
     * on what they publish. Moderator-only abilities
     * (`.manage`, `cities.manage`, `reports.manage`) are deliberately absent —
     * those belong to a moderator role, not the default consumer.
     *
     * The media grants are `view` + `create` only. An uploader may already edit
     * and remove their own media through MediaPolicy's `uploaded_by_id`
     * ownership rule, so `posts.media.update` / `.delete` would buy nothing
     * except reach over *other people's* files. The About-entry reaction grant
     * DOES include `delete`, because unliking is how you take back your own
     * like — ReactionPolicy scopes that ability to the caller's own row.
     *
     * The comment grants — `posts.comments.*` and `spot_abouts.comments.*` —
     * are `view` + `create` for the same reason as media: `CommentPolicy`
     * already lets somebody edit and remove their own comment through its
     * ownership rule, so `update` / `.delete` on either host would buy nothing
     * except reach over *other people's* replies, which is a moderator's
     * ability rather than an explorer's.
     *
     * The two hosts are listed together deliberately. `posts.comments.*` was
     * absent for months while the About-entry pair was present, so the app
     * offered a composer on a post's thread that answered 403 to every ordinary
     * user (STOURIFY-154) — the same defect STOURIFY-22 fixed for media. A
     * discovered permission is only half a feature; the grant is the other half,
     * and nothing in the platform will point out that it is missing.
     *
     * A role grant is not scoped to a host instance — holding
     * `posts.media.create` says "explorers may attach media to posts", not "only
     * to their own". StourifyMediaPolicy supplies the missing half by requiring
     * write rights on the host itself; neither piece is sufficient alone
     * (STOURIFY-22).
     *
     * @var list<string>
     */
    public const EXPLORER_PERMISSIONS = [
        'stourify.spots.view',
        'stourify.spots.create',
        'stourify.spots.update',
        'stourify.spots.delete',
        'stourify.spot_abouts.view',
        'stourify.spot_abouts.create',
        'stourify.spot_abouts.update',
        'stourify.spot_abouts.delete',
        'stourify.posts.view',
        'stourify.posts.create',
        'stourify.posts.update',
        'stourify.posts.delete',
        'stourify.reviews.view',
        'stourify.reviews.create',
        'stourify.reviews.update',
        'stourify.reviews.delete',
        'stourify.follows.manage',
        'stourify.wishlist.manage',
        'stourify.cities.view',
        'stourify.reports.create',
        'posts.reactions.view',
        'posts.reactions.create',
        'spot_abouts.reactions.view',
        'spot_abouts.reactions.create',
        'spot_abouts.reactions.delete',
        'spot_abouts.comments.view',
        'spot_abouts.comments.create',
        'posts.comments.view',
        'posts.comments.create',
        'reviews.reactions.view',
        'reviews.reactions.create',
        'posts.media.view',
        'posts.media.create',
        'spots.media.view',
        'spots.media.create',
    ];

    /**
     * The `explorer` role — the consumer role in the `Stourify Public`
     * organization. Org-scoped (a user holds it within that org), inheriting
     * the platform's base `user` role. Merged into `config('roles')` and synced
     * by the RoleSeeder.
     *
     * @return array<string, array<string, mixed>>
     */
    public function roles(): array
    {
        return [
            'explorer' => [
                'global' => false,
                'description' => 'A Stourify explorer — the consumer role within the public organization.',
                'inherits' => 'user',
                'permissions' => self::EXPLORER_PERMISSIONS,
            ],
        ];
    }

    /**
     * @return array<int, class-string<Model>>
     */
    public function searchableModels(): array
    {
        return [
            Spot::class,
            City::class,
            ExplorerProfile::class,
        ];
    }

    /**
     * The classes PHP is allowed to rebuild when this module's cached values
     * are read back.
     *
     * A cache is a coat check: you hand an object over, and later a ticket
     * brings it back. PHP adds a rule to that — it rebuilds a stored object
     * only if the object's class is on a guest list, so that whoever can write
     * into the cache cannot name any class in `vendor/` and have PHP run that
     * class's start-up code on the way out.
     *
     * The platform composes the guest list by asking every switched-on module
     * this question and merging the answers, which is why no `Modules\…` name
     * appears anywhere in `saas-boilerplate`. It is an optional method found by
     * name rather than a method on the `Module` interface, so adding it here
     * obliges no other module to change.
     *
     * **A missing name fails silently, which is the whole reason this exists.**
     * PHP does not throw when it refuses a class. It returns a
     * `__PHP_Incomplete_Class`, which looks like an object, survives every
     * `try`/`catch` on the way out, and explodes later in unrelated code on the
     * first property read. Until this method was declared, `GET /api/v1/spots`
     * answered its first request from the database and then returned a 500 on
     * every cached request after it (STOURIFY-216).
     *
     * Only models that actually use the `Cacheable` trait belong here.
     * `SyncTombstone` is deliberately absent: it is write-once and read by
     * cursor, so nothing ever caches one. `tests/Feature/SerializableCacheClassesTest.php`
     * fails in both directions — a cacheable model missing from this list, and
     * a name here that no longer earns its place.
     *
     * @return array<int, class-string<Model>>
     */
    public function serializableCacheClasses(): array
    {
        return [
            Block::class,
            City::class,
            ExplorerProfile::class,
            Follow::class,
            Post::class,
            Report::class,
            Review::class,
            Spot::class,
            SpotAbout::class,
            WishlistItem::class,
        ];
    }

    /**
     * @return array<int, class-string<Seeder>>
     */
    public function seeders(): array
    {
        return [
            // Order matters: the org must exist before users are enrolled into it.
            StourifyPublicOrganizationSeeder::class,
            StourifyExplorerBackfillSeeder::class,
            // Content must land in the public organization or no explorer can
            // see it — organization scoping hides it with no error at all.
            StourifyDemoContentSeeder::class,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function navigationItems(): array
    {
        return [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function settingsGroups(): array
    {
        return [];
    }

    /**
     * @return array<int, string>
     */
    public function webhookEvents(): array
    {
        return [];
    }

    /**
     * @return array<int, string>
     */
    public function quotaKeys(): array
    {
        return [];
    }

    /**
     * @return array<int, class-string>
     */
    public function importExportHandlers(): array
    {
        return [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function organizationTabs(): array
    {
        return [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function userSettingsTabs(): array
    {
        return [];
    }

    /**
     * @return array<int, string>
     */
    public function headerComponents(Request $request): array
    {
        return [];
    }

    /**
     * @return array<int, InjectedContent>
     */
    public function injectedContent(): array
    {
        return [];
    }

    /**
     * @return array<int, InjectedFormField>
     */
    public function injectedFormFields(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function inertiaSharedProps(Request $request): array
    {
        return [];
    }
}
