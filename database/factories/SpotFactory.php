<?php

declare(strict_types=1);

namespace Modules\Stourify\Database\Factories;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Stourify\Enums\SpotStatus;
use Modules\Stourify\Models\City;
use Modules\Stourify\Models\Spot;

/**
 * @extends Factory<Spot>
 */
class SpotFactory extends Factory
{
    protected $model = Spot::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = Str::title(fake()->words(3, true));

        return [
            'organization_id' => Organization::factory(),
            'user_id' => User::factory(),
            'city_id' => City::factory(),
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(4)),
            'description' => fake()->paragraph(),
            'latitude' => fake()->latitude(5, 19),
            'longitude' => fake()->longitude(117, 126),
            'address' => fake()->address(),
            'categories' => fake()->randomElements(
                ['nature', 'foodie', 'coast', 'heritage', 'viewpoint', 'coffee'],
                2,
            ),
            'hours' => null,
            'status' => SpotStatus::Published,
            'is_verified' => false,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (): array => ['status' => SpotStatus::Draft]);
    }

    /**
     * Place the spot within roughly a kilometre of a given point — used by
     * the nearby-scope tests, where distance ordering is the assertion.
     */
    public function near(float $latitude, float $longitude, float $offsetKm = 1.0): static
    {
        $delta = $offsetKm / 111.32;

        return $this->state(fn (): array => [
            'latitude' => $latitude + $delta,
            'longitude' => $longitude,
        ]);
    }
}
