<?php

declare(strict_types=1);

namespace Modules\Stourify\Database\Seeders;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Provisions the single system organization every piece of consumer content
 * belongs to (see docs/mobile-delivery/technical-spec.md §6).
 *
 * Idempotent by contract — the Module::seeders() docblock requires it, and
 * deploy.sh runs seeders on every deploy. Running this twice changes nothing.
 */
class StourifyPublicOrganizationSeeder extends Seeder
{
    public function run(): void
    {
        $config = config('stourify.public_organization');

        $owner = $this->resolveSystemUser($config['system_user_email']);

        Organization::firstOrCreate(
            ['slug' => $config['slug']],
            [
                'name' => $config['name'],
                'owner_id' => $owner->id,
                'is_personal' => false,
                'is_active' => true,
            ],
        );
    }

    /**
     * The organizations table requires an owner. Prefer an existing admin so
     * a real deployment does not accumulate a synthetic account; fall back to
     * a locked system user on a fresh database.
     *
     * The fallback password is random and discarded — the account is not a
     * login path. Never seed a known credential.
     */
    private function resolveSystemUser(string $email): User
    {
        $existing = User::query()->where('email', $email)->first();

        if ($existing !== null) {
            return $existing;
        }

        $firstUser = User::query()->oldest('id')->first();

        if ($firstUser !== null) {
            return $firstUser;
        }

        return User::create([
            'name' => 'Stourify System',
            'email' => $email,
            'password' => Hash::make(Str::random(48)),
            'email_verified_at' => now(),
        ]);
    }
}
