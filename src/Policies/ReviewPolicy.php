<?php

declare(strict_types=1);

namespace Modules\Stourify\Policies;

use App\Enums\RoleEnum;
use App\Models\User;
use Modules\Stourify\Models\Review;
use Modules\Stourify\Policies\Concerns\ChecksModeratorAccess;

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
    private const MANAGE_PERMISSION = 'stourify.reviews.manage';

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
        return $this->holdsAnyRole($user, [RoleEnum::SUPER_ADMIN->value]);
    }
}
