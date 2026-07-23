<?php

declare(strict_types=1);

namespace Modules\Stourify\Policies;

use App\Enums\RoleEnum;
use App\Models\User;
use Modules\Stourify\Enums\FollowStatus;
use Modules\Stourify\Enums\PostVisibility;
use Modules\Stourify\Models\Follow;
use Modules\Stourify\Models\Post;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Authorization for posts.
 *
 * Posts carry the module's only per-record audience rule, and it has two
 * independent halves that must *both* pass for a stranger to see a post:
 *
 *   1. **Published** — `published_at` is set. A post created offline exists
 *      before its photos finish uploading; until then it is the author's
 *      alone, however permissive its visibility says it is.
 *   2. **Visibility** — `public` is everyone, `followers` is the author's
 *      accepted followers, `private` is the author only.
 *
 * Getting this wrong in either direction is a privacy bug, so the two are
 * checked separately rather than collapsed into one condition.
 *
 * The follow test asks whether *the viewer follows the author* — the edge
 * direction people reverse. `follower_id` is the viewer.
 */
class PostPolicy
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
        return $this->allows($user, 'stourify.posts.view');
    }

    public function view(User $user, Post $post): bool
    {
        if ($this->isModerator($user) || $user->id === $post->user_id) {
            return true;
        }

        if (! $this->allows($user, 'stourify.posts.view')) {
            return false;
        }

        if ($post->published_at === null) {
            return false;
        }

        return match ($post->visibility) {
            PostVisibility::Public => true,
            PostVisibility::Followers => $this->follows($user, $post->user_id),
            PostVisibility::Private => false,
        };
    }

    /**
     * Whether this user may see other people's unpublished or restricted posts
     * in a *list*. Query-level, for the same reason SpotPolicy::viewAnyDraft()
     * exists — a per-model check cannot constrain a paginated query.
     */
    public function viewAnyRestricted(User $user): bool
    {
        return $this->isModerator($user);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'stourify.posts.create');
    }

    public function update(User $user, Post $post): bool
    {
        if ($this->isModerator($user)) {
            return true;
        }

        return $user->id === $post->user_id
            && $this->allows($user, 'stourify.posts.update');
    }

    public function delete(User $user, Post $post): bool
    {
        if ($this->isModerator($user)) {
            return true;
        }

        return $user->id === $post->user_id
            && $this->allows($user, 'stourify.posts.delete');
    }

    /**
     * Publishing is the author's own act — it is what they do when the queued
     * photos finish uploading. A moderator may publish on their behalf, but
     * nobody else can push someone's draft into the feed.
     */
    public function publish(User $user, Post $post): bool
    {
        return $this->update($user, $post);
    }

    public function restore(User $user, Post $post): bool
    {
        return $this->isModerator($user);
    }

    public function forceDelete(User $user, Post $post): bool
    {
        return $user->hasAnyRole([RoleEnum::SUPER_ADMIN->value]);
    }

    /**
     * Does $user follow $authorId, with the follow actually accepted?
     *
     * A `pending` request against a private account is not a follow, so it
     * must not unlock followers-only content.
     */
    private function follows(User $user, int $authorId): bool
    {
        return Follow::query()
            ->where('follower_id', $user->id)
            ->where('followee_id', $authorId)
            ->where('status', FollowStatus::Active->value)
            ->exists();
    }

    private function isModerator(User $user): bool
    {
        return $user->hasAnyRole(self::OVERRIDE_ROLES)
            || $this->allows($user, 'stourify.posts.manage');
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
