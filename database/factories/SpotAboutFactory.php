<?php

declare(strict_types=1);

namespace Modules\Stourify\Database\Factories;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Stourify\Models\Spot;
use Modules\Stourify\Models\SpotAbout;

/**
 * @extends Factory<SpotAbout>
 */
class SpotAboutFactory extends Factory
{
    protected $model = SpotAbout::class;

    /**
     * `likes_count` is not set here on purpose. It is not fillable, and its
     * only writer is ReactionCountObserver — a factory that seeded it would
     * make a counter bug look like a passing test.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'user_id' => User::factory(),
            'spot_id' => Spot::factory(),
            'body' => fake()->paragraph(),
        ];
    }
}
