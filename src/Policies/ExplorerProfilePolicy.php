<?php

declare(strict_types=1);

namespace Modules\Stourify\Policies;

use App\Enums\RoleEnum;
use App\Models\User;
use Modules\Stourify\Models\ExplorerProfile;

/**
 * Authorization for explorer profiles.
 *
 * A profile is your own identity, so this is ownership-based rather than
 * permission-gated: there is no grantable "manage profiles" capability, and
 * the module publishes none. Every authenticated explorer may read any
 * profile header (they are public, per the Instagram model — the account's
 * *content* is what privacy gates, not the header) and may edit exactly one:
 * their own.
 *
 * Platform admins can edit any profile for moderation — a wrong username or an
 * abusive bio has to be reachable — but org-scoped roles cannot: a profile is
 * personal, not org content.
 */
class ExplorerProfilePolicy
{
    /**
     * @var list<string>
     */
    private const OVERRIDE_ROLES = [
        RoleEnum::SUPER_ADMIN->value,
        RoleEnum::SITE_ADMIN->value,
    ];

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ExplorerProfile $profile): bool
    {
        return true;
    }

    /**
     * Any authenticated explorer may create their own profile — ownership
     * cannot be checked before the row exists, and the controller always sets
     * `user_id` to the caller, so there is nobody else's profile to create.
     * The `create` ability exists only because `CrudService::create()`
     * authorizes it; without this method it would deny by default.
     */
    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, ExplorerProfile $profile): bool
    {
        return $user->id === $profile->user_id
            || $user->hasAnyRole(self::OVERRIDE_ROLES);
    }

    public function delete(User $user, ExplorerProfile $profile): bool
    {
        return $user->id === $profile->user_id
            || $user->hasAnyRole(self::OVERRIDE_ROLES);
    }
}
