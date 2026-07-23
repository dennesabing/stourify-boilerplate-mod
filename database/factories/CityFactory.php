<?php

declare(strict_types=1);

namespace Modules\Stourify\Database\Factories;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Stourify\Models\City;

/**
 * @extends Factory<City>
 */
class CityFactory extends Factory
{
    protected $model = City::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->city();

        return [
            'organization_id' => Organization::factory(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'region' => fake()->state(),
            'country' => 'PH',
            'latitude' => fake()->latitude(5, 19),      // Philippine bounds
            'longitude' => fake()->longitude(117, 126),
            'is_featured' => false,
        ];
    }

    /**
     * General Santos City — the beta's launch market.
     */
    public function genSan(): static
    {
        return $this->state(fn (): array => [
            'name' => 'General Santos',
            'slug' => 'general-santos',
            'region' => 'Soccsksargen',
            'country' => 'PH',
            'latitude' => 6.1164,
            'longitude' => 125.1716,
            'is_featured' => true,
        ]);
    }
}
