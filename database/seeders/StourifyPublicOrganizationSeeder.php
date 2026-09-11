<?php

declare(strict_types=1);

namespace Modules\Stourify\Database\Seeders;

use App\Enums\RoleEnum;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Provisions the single system organization every piece of consumer content
 * belongs to (see docs/mobile-delivery/technical-spec.md §6).
 *
 * Idempotent by contract — the Module::seeders() docblock requires it, and
 * deploy.sh runs seeders on every deploy. Running this twice changes nothing.
 * An organization that already exists is left exactly as it is, owner
 * included: this only ever decides the owner of an organization it creates.
 */
class StourifyPublicOrganizationSeeder extends Seeder
{
    public function run(): void
    {
        $config = config('stourify.public_organization');

        $exists = Organization::withoutGlobalScopes()
            ->where('slug', $config['slug'])
            ->exists();

        if ($exists) {
            return;
        }

        Organization::create([
            'slug' => $config['slug'],
            'name' => $config['name'],
            'owner_id' => $this->resolveOwner($config['system_user_email'])->id,
            'is_personal' => false,
            'is_active' => true,
        ]);
    }

    /**
     * The organizations table requires an owner. In order of preference:
     *
     *  1. the system account, if an earlier run already created it;
     *  2. an active platform-wide admin (Super Admin or Site Admin on a role
     *     that belongs to no organization — the same test as
     *     User::isGlobalAdmin()), so a real deployment does not accumulate a
     *     synthetic account;
     *  3. a new system account that nobody can use.
     *
     * Never an ordinary user. The "oldest user" fallback this replaced made
     * whichever consumer signed up first the owner of the whole public
     * organization.
     */
    private function resolveOwner(string $email): User
    {
        $existing = User::query()->where('email', $email)->first();

        if ($existing !== null) {
            return $existing;
        }

        $admin = User::query()
            ->where('is_active', true)
            // Qualified: the role pivot carries its own organization_id (the
            // team the role was granted in), and it is the ROLE's that says
            // whether the role itself is platform-wide.
            ->whereHas('allRoles', fn (Builder $role): Builder => $role
                ->whereIn($role->qualifyColumn('name'), [RoleEnum::SUPER_ADMIN->value, RoleEnum::SITE_ADMIN->value])
                ->whereNull($role->qualifyColumn('organization_id')))
            ->oldest('id')
            ->first();

        return $admin ?? $this->createSystemUser($email);
    }

    /**
     * A caretaker account whose only job is to be the owner on record.
     *
     * Inactive, so both sign-in routes refuse it and the platform's password
     * reset skips it: nobody can sign in as the owner of Stourify Public, even
     * somebody who can read mail at this address. The password is random and
     * discarded on top of that. Never seed a known credential.
     */
    private function createSystemUser(string $email): User
    {
        return User::create([
            'name' => 'Stourify System',
            'email' => $email,
            'password' => Hash::make(Str::random(48)),
            'email_verified_at' => now(),
            'is_active' => false,
        ]);
    }
}
