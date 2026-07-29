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
use Modules\Stourify\Models\City;
use Modules\Stourify\Models\ExplorerProfile;
use Modules\Stourify\Models\Spot;

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
     * The module's own `stourify.*` permissions plus the *discovered* reaction
     * permissions on the Post and Review hosts (`posts.reactions.*`,
     * `reviews.reactions.*`), so an explorer can like a post and mark a review
     * helpful. Moderator-only abilities (`.manage`, `cities.manage`,
     * `reports.manage`) are deliberately absent — those belong to a moderator
     * role, not the default consumer.
     *
     * @var list<string>
     */
    public const EXPLORER_PERMISSIONS = [
        'stourify.spots.view',
        'stourify.spots.create',
        'stourify.spots.update',
        'stourify.spots.delete',
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
        'reviews.reactions.view',
        'reviews.reactions.create',
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
