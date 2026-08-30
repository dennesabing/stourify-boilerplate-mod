<?php

declare(strict_types=1);

namespace Modules\Stourify\Policies;

use App\Enums\RoleEnum;
use App\Models\User;
use Modules\Stourify\Models\SpotAbout;
use Modules\Stourify\Policies\Concerns\ChecksModeratorAccess;

/**
 * Authorization for About entries.
 *
 * Deliberately simpler than PostPolicy, and the difference is the point. A post
 * has an audience rule — public, followers-only, private — because a post is
 * one person's content that they choose who to show. An About entry is a
 * contribution to a *shared* description of a place: if you can see the spot,
 * you can see what people have written about it. There is no per-entry
 * visibility to get wrong, so none is invented.
 *
 * What remains is the ownership rule. Anyone may add an entry; only its author
 * may change it. A moderator — a platform administrator, or a holder of
 * `stourify.spot_abouts.manage` — may edit or remove anybody's, which is how a
 * bad entry comes off the board.
 */
class SpotAboutPolicy
{
    use ChecksModeratorAccess;

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
     * The module permission that also confers moderator standing here.
     */
    private const MANAGE_PERMISSION = 'stourify.spot_abouts.manage';

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'stourify.spot_abouts.view');
    }

    public function view(User $user, SpotAbout $about): bool
    {
        return $this->isModerator($user)
            || $user->id === $about->user_id
            || $this->allows($user, 'stourify.spot_abouts.view');
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'stourify.spot_abouts.create');
    }

    public function update(User $user, SpotAbout $about): bool
    {
        if ($this->isModerator($user)) {
            return true;
        }

        return $user->id === $about->user_id
            && $this->allows($user, 'stourify.spot_abouts.update');
    }

    public function delete(User $user, SpotAbout $about): bool
    {
        if ($this->isModerator($user)) {
            return true;
        }

        return $user->id === $about->user_id
            && $this->allows($user, 'stourify.spot_abouts.delete');
    }

    public function restore(User $user, SpotAbout $about): bool
    {
        return $this->isModerator($user);
    }

    public function forceDelete(User $user, SpotAbout $about): bool
    {
        return $this->holdsAnyRole($user, [RoleEnum::SUPER_ADMIN->value]);
    }
}
