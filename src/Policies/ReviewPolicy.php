<?php

declare(strict_types=1);

namespace Modules\Stourify\Policies;

use App\Enums\RoleEnum;
use App\Models\User;
use Modules\Stourify\Models\Review;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Authorization for reviews.
 *
 * Same two tiers as SpotPolicy — moderator, then author — but with one
 * difference that matters: a review has no draft state. Every review is
 * public the moment it is written, so `view` needs no visibility test and
 * the list endpoint needs no query-level scoping.
 *
 * Deliberately absent: any ability letting a spot's owner moderate reviews of
 * their own spot. That is the whole point of a review.
 *
 * @see SpotPolicy
 */
class ReviewPolicy
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
        return $this->allows($user, 'stourify.reviews.view');
    }

    public function view(User $user, Review $review): bool
    {
        return $this->isModerator($user)
            || $user->id === $review->user_id
            || $this->allows($user, 'stourify.reviews.view');
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'stourify.reviews.create');
    }

    public function update(User $user, Review $review): bool
    {
        if ($this->isModerator($user)) {
            return true;
        }

        return $user->id === $review->user_id
            && $this->allows($user, 'stourify.reviews.update');
    }

    public function delete(User $user, Review $review): bool
    {
        if ($this->isModerator($user)) {
            return true;
        }

        return $user->id === $review->user_id
            && $this->allows($user, 'stourify.reviews.delete');
    }

    public function restore(User $user, Review $review): bool
    {
        return $this->isModerator($user);
    }

    public function forceDelete(User $user, Review $review): bool
    {
        return $user->hasAnyRole([RoleEnum::SUPER_ADMIN->value]);
    }

    private function isModerator(User $user): bool
    {
        return $user->hasAnyRole(self::OVERRIDE_ROLES)
            || $this->allows($user, 'stourify.reviews.manage');
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
