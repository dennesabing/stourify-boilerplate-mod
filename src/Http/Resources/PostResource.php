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

            // author_uuid is kept for backward compatibility — mobile already
            // consumes it. `author` is the full identity a feed row's header
            // renders (name, handle, avatar) in one payload, so a client never
            // has to round-trip to GET /profiles/{user} per post.
            'author_uuid' => $this->whenLoaded('user', fn () => $post->user->uuid),
            'author' => $this->whenLoaded('user', fn (): array => [
                'uuid' => $post->user->uuid,
                'name' => $post->user->name,
                'username' => $post->user->relationLoaded('stourifyProfile')
                    ? $post->user->stourifyProfile?->username
                    : null,
                'avatar_url' => $post->user->getFirstMediaUrl('avatar', 'medium') ?: null,
            ]),

            'created_at' => $post->created_at?->toIso8601String(),
            'updated_at' => $post->updated_at?->toIso8601String(),

            'can' => $this->resolvePermissions($post),
        ];
    }
}
