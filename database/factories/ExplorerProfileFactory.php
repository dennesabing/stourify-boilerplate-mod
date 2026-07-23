<?php

declare(strict_types=1);

namespace Modules\Stourify\Database\Factories;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Stourify\Models\ExplorerProfile;

/**
 * @extends Factory<ExplorerProfile>
 */
class ExplorerProfileFactory extends Factory
{
    protected $model = ExplorerProfile::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'user_id' => User::factory(),
            'home_city_id' => null,
            'username' => Str::lower(fake()->userName()).Str::lower(Str::random(4)),
            'bio' => fake()->sentence(),
            'website' => null,
            'interests' => fake()->randomElements(
                ['foodie', 'nature', 'coffee', 'historical', 'beaches', 'art', 'hikes', 'nightlife'],
                3,
            ),
            'is_private' => false,
            'shows_location_on_spots' => true,
        ];
    }

    public function private(): static
    {
        return $this->state(fn (): array => ['is_private' => true]);
    }
}
