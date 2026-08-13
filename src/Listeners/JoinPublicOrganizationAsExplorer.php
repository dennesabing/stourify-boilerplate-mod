<?php

declare(strict_types=1);

namespace Modules\Stourify\Listeners;

use App\Events\Domain\UserRegistered;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Modules\Stourify\Database\Seeders\StourifyExplorerBackfillSeeder;
use Modules\Stourify\Models\ExplorerProfile;
use Modules\Stourify\Support\ExplorerUsernameGenerator;
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

    /** How many times to re-roll a handle that lost a race to the unique index. */
    private const PROFILE_CREATE_ATTEMPTS = 5;

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

        self::ensureExplorerProfile($user, $organization);
    }

    /**
     * Give the user the explorer profile the app assumes they have.
     *
     * Membership alone was never enough. The Stourify half of someone's
     * identity — handle, bio, home city, interests — lives in
     * `sto_explorer_profiles`, and until STOURIFY-82 nothing on the
     * registration path created that row: 24 of 31 accounts on the dev database
     * had none, and a new user's own Profile tab told them so.
     *
     * It sits here, in the shared enrolment path, for the same reason the
     * membership does — so the listener and StourifyExplorerBackfillSeeder
     * cannot drift into doing different things.
     *
     * **Plain Eloquent rather than `CrudService`, deliberately.**
     * `CrudService::create()` opens with `Gate::authorize()`, which asks what
     * the *logged-in* user may do. Nobody is logged in during registration, so
     * the gate would deny and the sign-up itself would fail. `CrudService` is
     * the path for a write somebody requested; this is the system provisioning
     * a record on its own initiative, exactly like the membership row and the
     * role assignment above.
     */
    private static function ensureExplorerProfile(User $user, Organization $organization): void
    {
        $alreadyHasProfile = ExplorerProfile::query()
            ->withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->exists();

        if ($alreadyHasProfile) {
            return;
        }

        // The generator answers with a handle free at the moment it looked,
        // which is not the same as free when the insert lands — two people
        // registering under one name in the same instant are handed the same
        // answer. The unique index is what actually decides, so a collision is
        // an ordinary outcome to retry, not a failure to report.
        for ($attempt = 1; $attempt <= self::PROFILE_CREATE_ATTEMPTS; $attempt++) {
            $username = $attempt === 1
                ? ExplorerUsernameGenerator::forName($user->name)
                : ExplorerUsernameGenerator::random($user->name);

            try {
                ExplorerProfile::create([
                    'organization_id' => $organization->id,
                    'user_id' => $user->id,
                    'username' => $username,
                ]);

                return;
            } catch (UniqueConstraintViolationException) {
                // Somebody else took it between the check and the insert.
            }
        }

        // Losing this many races in a row is not a collision, it is a fault —
        // and registration must not fail over it, so it is reported rather than
        // thrown. The user lands on the "No profile yet / Set up profile" state,
        // which still works.
        Log::warning('Stourify: could not allocate an explorer handle; profile not created.', [
            'user_id' => $user->id,
        ]);
    }

    private function publicOrganization(): ?Organization
    {
        return Organization::query()
            ->where('slug', config('stourify.public_organization.slug'))
            ->first();
    }
}
