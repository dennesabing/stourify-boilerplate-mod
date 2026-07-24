<?php

declare(strict_types=1);

namespace Modules\Stourify\Database\Seeders;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Seeder;
use Modules\Stourify\Listeners\JoinPublicOrganizationAsExplorer;

/**
 * Enrols existing users as explorers of the Stourify Public organization.
 *
 * The registration listener enrols users created *after* it is wired, but
 * accounts already in the database — the platform's seeded users, and anyone
 * who registered before this shipped — never fired `UserRegistered` (seeders
 * use a non-registration source). This seeder backfills them, so a seeded
 * login can exercise the app immediately.
 *
 * Runs after StourifyPublicOrganizationSeeder (the org must exist first) and
 * shares its one enrolment path with the listener, so the membership + role
 * assignment cannot drift. Idempotent: a user already enrolled is skipped.
 */
class StourifyExplorerBackfillSeeder extends Seeder
{
    public function run(): void
    {
        $organization = Organization::query()
            ->where('slug', config('stourify.public_organization.slug'))
            ->first();

        if ($organization === null) {
            return;
        }

        User::query()->chunkById(200, function ($users) use ($organization): void {
            foreach ($users as $user) {
                JoinPublicOrganizationAsExplorer::enrol($user, $organization);
            }
        });
    }
}
