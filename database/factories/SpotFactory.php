<?php

namespace Modules\Stourify\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Stourify\Models\Spot;
use Modules\Stourify\Models\SpotCategory;

class SpotFactory extends Factory
{
    protected $model = Spot::class;

    public function definition(): array
    {
        $name = fake()->unique()->company();
        return [
            'category_id'  => SpotCategory::factory(),
            'created_by'   => \App\Models\User::factory(),
            'name'         => $name,
            'slug'         => Str::slug($name).'-'.Str::random(4),
            'description'  => fake()->paragraph(),
            'latitude'     => fake()->latitude(5, 13),
            'longitude'    => fake()->longitude(118, 126),
            'address'      => fake()->address(),
            'status'       => 'active',
            'verified_at'  => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(['status' => 'pending', 'verified_at' => null]);
    }
}
