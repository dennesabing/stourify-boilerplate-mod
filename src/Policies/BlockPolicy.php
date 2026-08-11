<?php

declare(strict_types=1);

namespace Modules\Stourify\Policies;

use App\Enums\RoleEnum;
use App\Models\User;
use Modules\Stourify\Models\Block;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Authorization for blocks.
 *
 * Blocking rides on `stourify.follows.manage` rather than a permission of its
 * own. Blocking *is* an operation on the follow graph — it severs the edges
 * between two people and forbids new ones — and the module already publishes
 * one participant capability for that graph rather than a create/update/delete
 * triple (see `FollowPolicy`). A separate `stourify.blocks.manage` would be
 * granted to every explorer without exception, which is another way of saying
 * it would carry no information.
 *
 * Only the blocker may lift a block. The blocked party has no ability here at
 * all — not `view`, not `delete` — because every one of them would be a way to
 * discover that they had been blocked, which they are never told.
 */
class BlockPolicy
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

    /**
     * Listing blocks means listing *your own* — the controller constrains the
     * query to the caller. There is no queue of everyone's blocks to gate.
     */
    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'stourify.follows.manage');
    }

    public function view(User $user, Block $block): bool
    {
        return $user->id === $block->blocker_id || $this->isModerator($user);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'stourify.follows.manage');
    }

    /**
     * The blocker alone, plus platform oversight. Notably NOT the blocked
     * party: letting them delete the row would both undo a safety decision
     * that was not theirs and tell them it existed.
     */
    public function delete(User $user, Block $block): bool
    {
        return $user->id === $block->blocker_id || $this->isModerator($user);
    }

    /**
     * A block has no mutable field — it is created or removed. `update` is
     * denied outright rather than aliased to anything.
     */
    public function update(User $user, Block $block): bool
    {
        return false;
    }

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
