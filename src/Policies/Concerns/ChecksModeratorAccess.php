<?php

declare(strict_types=1);

namespace Modules\Stourify\Policies\Concerns;

use App\Models\User;
use Modules\Stourify\Support\AuthorizationMemo;

/**
 * The two questions every policy in this module asks about a viewer, asked in
 * one place instead of eight.
 *
 * A shop with eight tills does not keep eight separate copies of the price
 * list. It keeps one, because the day somebody corrects a price, eight copies
 * means seven tills still charging the old one — and nothing rings a bell.
 *
 * That is not a hypothetical here, it is this file's reason for existing.
 * Eight policies each carried their own copy of these two helpers. Under
 * STOURIFY-229 two of the copies were improved to stop re-deriving a viewer's
 * permissions for every row of a page, and the other six were simply left
 * behind — invisibly, because a copy that was never touched looks exactly like
 * a copy that did not need touching. STOURIFY-238 is the card that found them.
 *
 * **What the two questions are.**
 *
 *   - `allows()` — does this viewer hold this one permission?
 *   - `isModerator()` — may this viewer override the ordinary ownership rule,
 *     either by holding one of the policy's override roles or by holding its
 *     `…manage` permission?
 *
 * Both go through `AuthorizationMemo`, which answers a repeated question from
 * memory for the length of one request. Nothing here changes an answer; it
 * only stops the same question being asked ninety times while one page is
 * being built. `AuthorizationMemo`'s own documentation carries the important
 * part — exactly when a remembered answer stops being used, and the one path
 * it cannot see.
 *
 * **What a policy using this must declare**, because the helper reads both off
 * the policy itself:
 *
 *   - `OVERRIDE_ROLES` — the platform roles that override this policy's rules.
 *   - `MANAGE_PERMISSION` — the module permission that also grants moderator
 *     standing, or `null` where the policy deliberately has none. `null` is an
 *     answer, not an omission: blocks, follows and wishlist items are personal
 *     things with no moderation surface, and writing it out is what tells the
 *     next reader that was decided rather than forgotten.
 *
 * A policy that forgets either constant would fail at the moment somebody's
 * access is being decided, which is the worst imaginable time to find out — so
 * `PolicyPermissionCostTest` checks every policy using this trait declares
 * both, and a missing one is a red test rather than a surprise in production.
 */
trait ChecksModeratorAccess
{
    /**
     * May this viewer override the policy's ordinary ownership rules?
     */
    private function isModerator(User $user): bool
    {
        if ($this->holdsAnyRole($user, static::OVERRIDE_ROLES)) {
            return true;
        }

        return static::MANAGE_PERMISSION !== null
            && $this->allows($user, static::MANAGE_PERMISSION);
    }

    /**
     * Does this viewer hold this permission?
     *
     * A permission that has never been seeded is not a permission the viewer
     * holds. The permission library throws rather than answering no, so
     * without this the absence of a permission row would be a server error
     * instead of a refusal — `AuthorizationMemo::permits()` is where that is
     * caught, and it is caught there so all eight policies inherit it.
     */
    private function allows(User $user, string $permission): bool
    {
        return AuthorizationMemo::permits($user, $permission);
    }

    /**
     * Does this viewer hold at least one of these roles?
     *
     * @param  list<string>  $roles
     */
    private function holdsAnyRole(User $user, array $roles): bool
    {
        return AuthorizationMemo::holdsAnyRole($user, $roles);
    }
}
