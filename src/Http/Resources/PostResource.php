<?php

declare(strict_types=1);

namespace Modules\Stourify\Http\Resources;

use App\Http\Resources\BaseResource;
use Illuminate\Http\Request;
use Modules\Stourify\Models\Post;

/**
 * @property Post $resource
 */
class PostResource extends BaseResource
{
    /**
     * @return array<int, string>
     */
    public static function resourceAbilities(): array
    {
        return ['publish'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $post = $this->resource;

        return [
            'uuid' => $post->uuid,
            'caption' => $post->caption,
            'visibility' => $post->visibility->value,

            'is_published' => $post->published_at !== null,
            'published_at' => $post->published_at?->toIso8601String(),

            'likes_count' => (int) $post->likes_count,
            'comments_count' => (int) $post->comments_count,

            'spot' => new SpotResource($this->whenLoaded('spot')),
            'author_uuid' => $this->whenLoaded('user', fn () => $post->user->uuid),

            'created_at' => $post->created_at?->toIso8601String(),
            'updated_at' => $post->updated_at?->toIso8601String(),

            'can' => $this->resolvePermissions($post),
        ];
    }
}
