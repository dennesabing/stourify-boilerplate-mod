<?php

declare(strict_types=1);

namespace Modules\Stourify\Support;

use App\Models\User;
use Illuminate\Support\Collection;
use Modules\Stourify\Http\Resources\ExplorerResource;
use Modules\Stourify\Models\ExplorerProfile;

/**
 * Attaches each user's ExplorerProfile without touching the boilerplate.
 *
 * `User` lives in `saas-boilerplate`, which must contain zero references to
 * any module (root CLAUDE.md, *Dependency Direction*), so there is no
 * `User::stourifyProfile()` relationship to eager-load and there must never
 * be one. Instead the module fetches the profiles it needs in a single query
 * and hangs them on the user instances with `setRelation()` — Eloquent stores
 * them in the relations bag regardless of whether a method declares them, and
 * `relationLoaded()` then answers true.
 *
 * This is one query for the whole page, so it is not an N+1 dressed up; the
 * alternative — resolving the profile inside the resource — would be.
 *
 * @see ExplorerResource
 */
trait AttachesExplorerProfiles
{
    /**
     * @param  Collection<int, User>  $users
     * @return Collection<int, User>
     */
    protected function attachExplorerProfiles(Collection $users): Collection
    {
        $userIds = $users->pluck('id')->filter()->unique();

        if ($userIds->isEmpty()) {
            return $users;
        }

        $profiles = ExplorerProfile::query()
            ->whereIn('user_id', $userIds)
            ->get()
            ->keyBy('user_id');

        return $users->each(function (User $user) use ($profiles): void {
            $user->setRelation('stourifyProfile', $profiles->get($user->id));
        });
    }

    /**
     * Whether this explorer runs a private account.
     *
     * A user who has never opened the app has no ExplorerProfile row yet, and
     * an absent profile means a public account — the permissive default is
     * correct here because privacy is opt-in, and treating "no profile" as
     * private would make brand-new accounts silently unfollowable.
     */
    protected function isPrivateAccount(int $userId): bool
    {
        return (bool) ExplorerProfile::query()
            ->where('user_id', $userId)
            ->value('is_private');
    }
}
