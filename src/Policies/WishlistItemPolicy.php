<?php

declare(strict_types=1);

namespace Modules\Stourify\Policies;

use App\Enums\RoleEnum;
use App\Models\User;
use Modules\Stourify\Models\WishlistItem;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Authorization for wishlist items.
 *
 * A wishlist is private by nature — it is the list of places *you* mean to
 * visit, and there is no beta surface where anyone sees anyone else's. So this
 * is a flat owner-only policy: no "public" tier, no followers exception. The
 * list endpoint is additionally scoped to the caller in SQL, so ownership is
 * enforced twice — once on the record, once on the query.
 *
 * The module publishes a single `stourify.wishlist.manage` permission:
 * saving, editing a note and unsaving are one capability.
 */
class WishlistItemPolicy
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
        return $this->allows($user, 'stourify.wishlist.manage');
    }

    public function view(User $user, WishlistItem $item): bool
    {
        return $this->owns($user, $item) || $this->isModerator($user);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'stourify.wishlist.manage');
    }

    public function update(User $user, WishlistItem $item): bool
    {
        return $this->owns($user, $item)
            && $this->allows($user, 'stourify.wishlist.manage');
    }

    public function delete(User $user, WishlistItem $item): bool
    {
        return $this->owns($user, $item)
            && $this->allows($user, 'stourify.wishlist.manage');
    }

    private function owns(User $user, WishlistItem $item): bool
    {
        return $user->id === $item->user_id;
    }

    /**
     * Only platform admins, never org-scoped roles: a wishlist is personal, so
     * an org owner has no standing over it the way they do over org content.
     */
    private function isModerator(User $user): bool
    {
        return $user->hasAnyRole(self::OVERRIDE_ROLES);
    }

    private function allows(User $user, string $permission): bool
    {
        try {
            return $user->hasPermissionTo($permission);
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }
}
