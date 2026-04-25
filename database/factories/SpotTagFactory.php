<?php

namespace Modules\Stourify\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Stourify\Models\SpotTag;

class SpotTagFactory extends Factory
{
    protected $model = SpotTag::class;

    public function definition(): array
    {
        $name = fake()->unique()->word();
        return [
            'name' => ucfirst($name),
            'slug' => Str::slug($name),
        ];
    }
}
