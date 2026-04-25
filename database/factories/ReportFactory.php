<?php

namespace Modules\Stourify\Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Social\Models\Post;
use Modules\Stourify\Models\Report;

class ReportFactory extends Factory
{
    protected $model = Report::class;

    public function definition(): array
    {
        $post = Post::factory()->create();

        return [
            'reporter_id'     => User::factory(),
            'reportable_type' => Post::class,
            'reportable_id'   => $post->id,
            'reason'          => $this->faker->randomElement(['spam', 'inappropriate', 'harassment', 'other']),
            'status'          => 'pending',
        ];
    }
}
