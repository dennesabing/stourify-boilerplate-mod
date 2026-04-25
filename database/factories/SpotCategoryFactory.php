<?php

namespace Modules\Stourify\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Stourify\Models\SpotCategory;

class SpotCategoryFactory extends Factory
{
    protected $model = SpotCategory::class;

    public function definition(): array
    {
        $slug = fake()->unique()->company();
        $slug = \Illuminate\Support\Str::slug($slug);
        return [
            'name' => ucwords(str_replace('-', ' ', $slug)),
            'slug' => $slug,
            'icon' => null,
        ];
    }
}
