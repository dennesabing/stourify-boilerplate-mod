<?php

declare(strict_types=1);

namespace Modules\Stourify\Policies;

use App\Enums\RoleEnum;
use App\Models\User;
use Modules\Stourify\Models\Follow;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Authorization for follow edges.
 *
 * A follow has two parties and they do not have the same rights, which is the
 * whole shape of this policy:
 *
 *   - The **follower** may withdraw the edge (unfollow, or cancel a pending
 *     request). They may never accept their own request — that would make
 *     private accounts meaningless.
 *   - The **followee** may accept or reject a pending request, and may remove
 *     an existing follower.
 *
 * `delete` therefore admits *both* parties, while `accept` — and `update`,
 * which is the same rule — admits only the followee. Collapsing `delete` into
 * the accept rule is the obvious mistake here.
 *
 * The module publishes a single `stourify.follows.manage` permission rather
 * than a create/update/delete triple: following is one capability, and an
 * explorer who may follow may unfollow.
 */
class FollowPolicy
{
    /**
     * @var list<string>
     */
    private const OVERRIDE_ROLES = [
        RoleEnum::ORG_OWNER->value,
        RoleEnum::ORG_ADMIN->value,
        RoleEnum::SUPER_ADMIN->value,
        RoleEnum::SITE_ADMIN->value,
    ];

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'stourify.follows.manage');
    }

    public function view(User $user, Follow $follow): bool
    {
        return $this->isModerator($user)
            || $user->id === $follow->follower_id
            || $user->id === $follow->followee_id;
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'stourify.follows.manage');
    }

    /**
     * Either party may end the relationship: the follower unfollows, the
     * followee removes a follower or rejects a request.
     */
    public function delete(User $user, Follow $follow): bool
    {
        return $this->isModerator($user)
            || $user->id === $follow->follower_id
            || $user->id === $follow->followee_id;
    }

    /**
     * Only the followee accepts. A follower who could accept their own pending
     * request would walk straight past a private account's gate.
     */
    public function accept(User $user, Follow $follow): bool
    {
        return $user->id === $follow->followee_id;
    }

    /**
     * A follow edge has exactly one legal mutation — the followee accepting a
     * pending request — so `update` and `accept` are deliberately the same
     * rule rather than `update` being denied outright.
     *
     * They cannot diverge: writes go through `CrudService`, which authorizes
     * `update`, so denying it here while allowing `accept` would make the
     * accept endpoint permanently 403 (which is exactly what it did before
     * this was reconciled). Everything else about an edge is create or delete.
     */
    public function update(User $user, Follow $follow): bool
    {
        return $this->accept($user, $follow);
    }

    /**
     * The follow graph has no moderator permission of its own — the module
     * publishes only `stourify.follows.manage`, which is the *participant*
     * capability. Oversight comes from the platform override roles alone,
     * rather than inventing a permission nothing else references.
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
