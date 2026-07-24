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

            // Present only when the viewer's own reaction was eager-loaded (the
            // read paths scope the `reactions` relation to the caller). Absent,
            // not false, otherwise — so a client can tell "not evaluated" from
            // "not liked" rather than rendering a hollow heart on a cold field.
            'is_liked' => $this->when(
                $post->relationLoaded('reactions'),
                fn (): bool => $post->reactions->contains('type', Post::LIKE_REACTION),
            ),

            'spot' => new SpotResource($this->whenLoaded('spot')),
            'author_uuid' => $this->whenLoaded('user', fn () => $post->user->uuid),

            'created_at' => $post->created_at?->toIso8601String(),
            'updated_at' => $post->updated_at?->toIso8601String(),

            'can' => $this->resolvePermissions($post),
        ];
    }
}
