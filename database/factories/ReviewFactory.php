<?php

declare(strict_types=1);

namespace Modules\Stourify\Database\Factories;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Stourify\Models\Review;
use Modules\Stourify\Models\Spot;

/**
 * @extends Factory<Review>
 */
class ReviewFactory extends Factory
{
    protected $model = Review::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'user_id' => User::factory(),
            'spot_id' => Spot::factory(),
            'rating' => fake()->numberBetween(1, 5),
            'body' => fake()->paragraph(),
        ];
    }
}
