<?php

declare(strict_types=1);

namespace Modules\Stourify\Database\Factories;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Stourify\Enums\FollowStatus;
use Modules\Stourify\Models\Follow;

/**
 * @extends Factory<Follow>
 */
class FollowFactory extends Factory
{
    protected $model = Follow::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'follower_id' => User::factory(),
            'followee_id' => User::factory(),
            'status' => FollowStatus::Active,
            'accepted_at' => now(),
        ];
    }

    /**
     * A follow request awaiting a private account's approval.
     */
    public function pending(): static
    {
        return $this->state(fn (): array => [
            'status' => FollowStatus::Pending,
            'accepted_at' => null,
        ]);
    }
}
