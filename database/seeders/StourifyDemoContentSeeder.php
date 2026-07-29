<?php

declare(strict_types=1);

namespace Modules\Stourify\Database\Seeders;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Modules\Stourify\Models\City;
use Modules\Stourify\Models\Spot;

/**
 * Seeds browsable demo content INTO THE PUBLIC ORGANIZATION.
 *
 * Why this exists: consumer content is scoped to the single `Stourify Public`
 * organization (technical-spec §6), and a registered user is enrolled only
 * there (`JoinPublicOrganizationAsExplorer`). Content created in any other
 * organization is invisible to every real explorer — organization scoping
 * hides it completely, with no error and nothing in the UI to suggest why.
 *
 * That is not hypothetical. On 2026-07-29 a device run found three published
 * spots sitting in organization 1 (`XioApp`) while the freshly registered
 * account belonged to organization 6 (`Stourify Public`): search returned "No
 * spots found", the home-city step never populated, and the app looked empty
 * and broken to a new user. There was no demo-content seeder at all, so the
 * spots had been created ad hoc against whichever organization the developer
 * happened to be in.
 *
 * The M6 gate requires GenSan to carry 100+ spots before the first external
 * user is invited, and `milestones.md` calls an empty app fatal regardless of
 * quality. Seeding into the wrong organization would satisfy the count and
 * still ship an empty app, so the organization is resolved here rather than
 * inherited from context.
 *
 * Idempotent by contract, like every seeder in this module: `deploy.sh` runs
 * seeders on every deploy, so running this twice must change nothing.
 */
class StourifyDemoContentSeeder extends Seeder
{
    /**
     * General Santos is the beta's launch city (milestones.md, M6).
     *
     * @var list<array{title: string, description: string, latitude: float, longitude: float, categories: list<string>}>
     */
    private const SPOTS = [
        [
            'title' => 'Sunset Ridge Overlook',
            'description' => 'A short climb above the bay that locals treat as the default sunset spot.',
            'latitude' => 6.1268,
            'longitude' => 125.1892,
            'categories' => ['nature', 'adventure'],
        ],
        [
            'title' => 'Tuna Capital Food Crawl',
            'description' => 'Six stalls, one street, and the freshest tuna in the country.',
            'latitude' => 6.1120,
            'longitude' => 125.1715,
            'categories' => ['food'],
        ],
        [
            'title' => 'Brew & Bloom Cafe',
            'description' => 'Third-wave coffee in a converted family home, with a courtyard out back.',
            'latitude' => 6.1155,
            'longitude' => 125.1760,
            'categories' => ['food', 'culture'],
        ],
        [
            'title' => 'Hidden Cove',
            'description' => 'A pocket of grey sand reachable on foot at low tide.',
            'latitude' => 6.0921,
            'longitude' => 125.2044,
            'categories' => ['beach', 'nature'],
        ],
        [
            'title' => 'Sarangani Heritage Walk',
            'description' => 'A self-guided loop past the older civic buildings and the market arch.',
            'latitude' => 6.1189,
            'longitude' => 125.1701,
            'categories' => ['history', 'culture'],
        ],
        [
            'title' => 'Kalaklan Point',
            'description' => 'Windy headland with a clear line of sight to the fishing fleet.',
            'latitude' => 6.1402,
            'longitude' => 125.2113,
            'categories' => ['nature'],
        ],
    ];

    public function run(): void
    {
        $organization = $this->resolvePublicOrganization();

        if ($organization === null) {
            $this->command?->warn(
                'Stourify Public organization not found — run StourifyPublicOrganizationSeeder first. Skipping demo content.',
            );

            return;
        }

        $contributor = User::query()->find($organization->owner_id);

        if ($contributor === null) {
            $this->command?->warn('Public organization has no owner; skipping demo content.');

            return;
        }

        $city = City::firstOrCreate(
            ['organization_id' => $organization->id, 'slug' => 'general-santos'],
            [
                'name' => 'General Santos',
                'region' => 'Soccsksargen',
                // ISO-3166 alpha-2 — the column is `string('country', 2)`.
                'country' => 'PH',
                'latitude' => 6.1164,
                'longitude' => 125.1716,
                'is_featured' => true,
            ],
        );

        foreach (self::SPOTS as $spot) {
            Spot::firstOrCreate(
                ['organization_id' => $organization->id, 'slug' => Str::slug($spot['title'])],
                [
                    'user_id' => $contributor->id,
                    'city_id' => $city->id,
                    'title' => $spot['title'],
                    'description' => $spot['description'],
                    'latitude' => $spot['latitude'],
                    'longitude' => $spot['longitude'],
                    'categories' => $spot['categories'],
                    // Only published spots are discoverable; a draft would
                    // reproduce the very emptiness this seeder exists to fix.
                    'status' => 'published',
                    'is_verified' => true,
                ],
            );
        }
    }

    private function resolvePublicOrganization(): ?Organization
    {
        $slug = config('stourify.public_organization.slug');

        return Organization::query()->where('slug', $slug)->first();
    }
}
