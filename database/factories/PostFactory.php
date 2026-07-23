<?php

declare(strict_types=1);

namespace Modules\Stourify\Database\Factories;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Stourify\Enums\PostVisibility;
use Modules\Stourify\Models\Post;
use Modules\Stourify\Models\Spot;

/**
 * @extends Factory<Post>
 */
class PostFactory extends Factory
{
    protected $model = Post::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'user_id' => User::factory(),
            'spot_id' => Spot::factory(),
            'caption' => fake()->sentence(),
            'visibility' => PostVisibility::Public,
            'published_at' => now(),
        ];
    }

    /**
     * Created offline, media not yet uploaded — invisible in the feed.
     */
    public function pendingUpload(): static
    {
        return $this->state(fn (): array => ['published_at' => null]);
    }

    public function followersOnly(): static
    {
        return $this->state(fn (): array => ['visibility' => PostVisibility::Followers]);
    }
}
