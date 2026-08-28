<?php

declare(strict_types=1);

namespace Modules\Stourify\Policies;

use App\Enums\RoleEnum;
use App\Models\User;
use Modules\Stourify\Enums\SpotStatus;
use Modules\Stourify\Models\Spot;
use Modules\Stourify\Support\AuthorizationMemo;

/**
 * Authorization for spots.
 *
 * Two tiers, in this order:
 *
 *   1. **Moderator** — `stourify.spots.manage`, or one of the platform's
 *      override roles. Sees and edits everything, including drafts and
 *      removed spots.
 *   2. **Contributor** — the explorer who created the spot (`user_id`).
 *      Edits and deletes their own, provided they still hold the matching
 *      `stourify.spots.*` permission.
 *
 * Everyone else sees only spots that are discoverable (`published`). A draft
 * is private to its author until it is published — that is what makes the
 * offline "create now, publish on reconnect" flow safe to expose in a shared
 * organization.
 *
 * Note that `view` is deliberately stricter than `viewAny`: holding
 * `stourify.spots.view` lets you list spots, but never reaches a draft
 * belonging to someone else.
 */
class SpotPolicy
{
    /**
     * Roles that bypass the module's permission checks entirely, matching the
     * platform's convention for attachables.
     *
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
        return $this->allows($user, 'stourify.spots.view');
    }

    public function view(User $user, Spot $spot): bool
    {
        if ($this->isModerator($user)) {
            return true;
        }

        if ($this->owns($user, $spot)) {
            return true;
        }

        return $this->isDiscoverable($spot)
            && $this->allows($user, 'stourify.spots.view');
    }

    /**
     * Whether this user may see *unpublished* spots belonging to other people.
     *
     * The list endpoint needs this as a query-level decision — it has to know
     * whether to constrain the SQL before rows are selected, which a per-model
     * `view()` check cannot do. Exposing it as its own ability keeps the rule
     * in the policy instead of duplicating the moderator test in a controller.
     */
    public function viewAnyDraft(User $user): bool
    {
        return $this->isModerator($user);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'stourify.spots.create');
    }

    public function update(User $user, Spot $spot): bool
    {
        if ($this->isModerator($user)) {
            return true;
        }

        return $this->owns($user, $spot)
            && $this->allows($user, 'stourify.spots.update');
    }

    public function delete(User $user, Spot $spot): bool
    {
        if ($this->isModerator($user)) {
            return true;
        }

        return $this->owns($user, $spot)
            && $this->allows($user, 'stourify.spots.delete');
    }

    /**
     * Restoring and hard-deleting are moderator-only. A contributor who
     * deleted their own spot does not get to reach back into the audit trail.
     */
    public function restore(User $user, Spot $spot): bool
    {
        return $this->isModerator($user);
    }

    public function forceDelete(User $user, Spot $spot): bool
    {
        return $user->hasAnyRole([RoleEnum::SUPER_ADMIN->value]);
    }

    /**
     * Marking a spot as verified is a trust signal shown in the UI, so it is
     * never self-service.
     */
    public function verify(User $user, Spot $spot): bool
    {
        return $this->isModerator($user);
    }

    private function owns(User $user, Spot $spot): bool
    {
        return $user->id === $spot->user_id;
    }

    private function isDiscoverable(Spot $spot): bool
    {
        return in_array($spot->status->value, SpotStatus::discoverable(), true);
    }

    /**
     * Both of these go through AuthorizationMemo, which answers a repeated
     * question from memory for the length of one request. A page that renders
     * many rows asks the same thing about the same viewer once per row, and
     * working it out is expensive — see that class for the measurement.
     *
     * A permission the module declares but that has not been synced into the
     * database yet must deny, not explode. The memo keeps that behaviour.
     */
    private function isModerator(User $user): bool
    {
        return AuthorizationMemo::holdsAnyRole($user, self::OVERRIDE_ROLES)
            || $this->allows($user, 'stourify.spots.manage');
    }

    private function allows(User $user, string $permission): bool
    {
        return AuthorizationMemo::permits($user, $permission);
    }
}
