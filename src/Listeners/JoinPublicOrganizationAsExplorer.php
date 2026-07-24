<?php

declare(strict_types=1);

namespace Modules\Stourify\Listeners;

use App\Events\Domain\UserRegistered;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Modules\Stourify\Database\Seeders\StourifyExplorerBackfillSeeder;
use Spatie\Permission\Models\Role;

/**
 * Makes every newly-registered user an explorer in the Stourify Public org.
 *
 * Stourify is a single-organization consumer app: all content belongs to the
 * one `Stourify Public` organization, and `SetOrganizationFromHeader` requires
 * the caller to be a *member* of the org whose UUID they send — permissions
 * alone are not enough. So a registered user who is not a member of the public
 * org can do nothing at all. This listener closes that gap the moment they
 * register.
 *
 * It fires on `UserRegistered`, which `UserService::createUser()` dispatches
 * only when the source is `registration` — so factories and seeders (a
 * different source) do not trigger it; existing accounts are backfilled by
 * StourifyExplorerBackfillSeeder instead.
 *
 * Idempotent and defensive: a user already in the org is left alone, and if the
 * public org has not been provisioned yet (early in a fresh boot) the listener
 * no-ops rather than throwing during someone's sign-up.
 *
 * @see StourifyExplorerBackfillSeeder
 */
class JoinPublicOrganizationAsExplorer
{
    public const ROLE = 'explorer';

    public function handle(UserRegistered $event): void
    {
        $organization = $this->publicOrganization();

        if ($organization === null) {
            Log::warning('Stourify: public organization not provisioned; new explorer not enrolled.', [
                'user_id' => $event->user->id,
            ]);

            return;
        }

        $this->enrol($event->user, $organization);
    }

    /**
     * Enrol a user as an explorer of the public org. Public + static so the
     * backfill seeder shares exactly one enrolment path.
     */
    public static function enrol(User $user, Organization $organization): void
    {
        $alreadyMember = $organization->members()
            ->where('users.id', $user->id)
            ->exists();

        if (! $alreadyMember) {
            $organization->members()->attach($user->id, [
                'role' => self::ROLE,
                'is_personal' => false,
                'joined_at' => now(),
            ]);
        }

        // A brand-new explorer has no current organization; point them at the
        // public one so their first requests resolve a tenant. An existing
        // choice is left untouched.
        if ($user->current_organization_id === null) {
            $user->forceFill(['current_organization_id' => $organization->id])->save();
        }

        if (Role::where('name', self::ROLE)->exists()) {
            // Org-scoped roles live under the Spatie team = the organization.
            setPermissionsTeamId($organization->id);
            $user->assignRole(self::ROLE);
            $user->syncPermissionsFromRoles();
        }
    }

    private function publicOrganization(): ?Organization
    {
        return Organization::query()
            ->where('slug', config('stourify.public_organization.slug'))
            ->first();
    }
}
