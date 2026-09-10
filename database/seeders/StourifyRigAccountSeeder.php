<?php

declare(strict_types=1);

namespace Modules\Stourify\Database\Seeders;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Modules\Stourify\Listeners\JoinPublicOrganizationAsExplorer;

/**
 * Provisions the one ordinary explorer account that live test runs sign in with.
 *
 * Why this exists: until STOURIFY-253 the emulator's only session belonged to a
 * fixture with no organization, created on purpose to photograph a refusal and
 * then left signed in. Every organization-scoped endpoint answered it 403, and
 * because no login for any other account was written down anywhere, signing it
 * out was a one-way door. One login was serving two opposite purposes. This
 * gives the rig its own login instead of repurposing the old one.
 *
 * The credentials come from config('stourify.rig_account'), which reads the
 * gitignored saas-boilerplate/.env, so the password lives in exactly one place
 * and never in this file. Unset, the seeder does nothing. That makes it safe in
 * Module::seeders(), which `php artisan modules:seed` runs on every deploy.
 *
 * Deliberately NOT the backfill: StourifyExplorerBackfillSeeder enrols every
 * user on the database. This one touches a single row, so it can run against
 * the shared dev database without re-enrolling the no-organization fixtures
 * other cards depend on.
 *
 * Plain Eloquent rather than CrudService, for the reason
 * JoinPublicOrganizationAsExplorer gives: nobody is logged in, so there is no
 * one for a gate to authorize. This is the system provisioning a record, not a
 * write somebody requested.
 */
class StourifyRigAccountSeeder extends Seeder
{
    public function run(): void
    {
        $config = (array) config('stourify.rig_account');
        $email = $config['email'] ?? null;
        $password = $config['password'] ?? null;

        if (! is_string($email) || $email === '' || ! is_string($password) || $password === '') {
            $this->command?->info('StourifyRigAccountSeeder skipped: STOURIFY_RIG_EMAIL / STOURIFY_RIG_PASSWORD not set.');

            return;
        }

        // A login whose password sits in a developer's .env does not belong on a
        // live install, whatever the configuration says.
        if (app()->environment('production')) {
            $this->command?->warn('StourifyRigAccountSeeder refused: it never runs in production.');

            return;
        }

        $organization = Organization::query()
            ->where('slug', config('stourify.public_organization.slug'))
            ->first();

        if ($organization === null) {
            $this->command?->warn('StourifyRigAccountSeeder skipped: the public organization is not provisioned yet.');

            return;
        }

        $rig = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => (string) ($config['name'] ?? 'Stourify Rig'),
                'password' => $password,
                'email_verified_at' => now(),
                'is_active' => true,
            ],
        );

        // The .env is the source of truth for this password: if somebody changes
        // it there, the next run makes the account agree. Only writes when they
        // differ, so a re-run is otherwise a no-op on the row.
        if (! Hash::check($password, (string) $rig->password)) {
            $rig->forceFill(['password' => $password])->save();
        }

        JoinPublicOrganizationAsExplorer::enrol($rig, $organization);

        // The address is safe to print; the password never is.
        $this->command?->info("Rig account ready: {$email}");
    }
}
