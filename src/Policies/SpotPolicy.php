<?php

namespace Modules\Stourify\Policies;

use App\Models\User;
use Modules\Stourify\Models\Spot;

class SpotPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Spot $spot): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Spot $spot): bool
    {
        return $user->id === $spot->created_by
            || $this->hasModeratePermission($user);
    }

    public function delete(User $user, Spot $spot): bool
    {
        return $user->id === $spot->created_by
            || $this->hasModeratePermission($user);
    }

    private function hasModeratePermission(User $user): bool
    {
        try {
            return $user->hasPermissionTo('spots.moderate');
        } catch (\Spatie\Permission\Exceptions\PermissionDoesNotExist) {
            return false;
        }
    }
}
