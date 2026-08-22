<?php

declare(strict_types=1);

namespace Modules\Stourify\Http\Resources;

use App\Http\Resources\BaseResource;
use Illuminate\Http\Request;
use Modules\Stourify\Models\SpotAbout;

/**
 * @property SpotAbout $resource
 */
class SpotAboutResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $about = $this->resource;

        return [
            'uuid' => $about->uuid,
            'body' => $about->body,

            // The card asks for "information of who added and datetime", and
            // this is that: the spot it belongs to, the person, and the moment.
            'spot_uuid' => $this->whenLoaded('spot', fn () => $about->spot->uuid),
            'author' => $this->whenLoaded('user', fn (): array => [
                'uuid' => $about->user->uuid,
                'name' => $about->user->name,
                'username' => $about->user->relationLoaded('stourifyProfile')
                    ? $about->user->stourifyProfile?->username
                    : null,
                'avatar_url' => $about->user->getFirstMediaUrl('avatar', 'medium') ?: null,
            ]),

            'likes_count' => (int) $about->likes_count,

            // How many people replied to this note. Unlike `likes_count` this is
            // not a stored column — it is counted as part of the same query that
            // fetched the page, so there is no second number anybody has to keep
            // truthful. Absent when the read path did not ask for it, for the
            // same reason `is_liked` is: a client can then tell "not counted"
            // from "nobody has replied" instead of rendering a confident zero.
            'comments_count' => $this->whenCounted('comments', fn (): int => (int) $about->comments_count),

            // Present only when the viewer's own reaction was eager-loaded — the
            // read paths scope the `reactions` relation to the caller. Absent,
            // not false, otherwise, so a client can tell "not evaluated" from
            // "not liked" rather than rendering a hollow heart on a cold field.
            'is_liked' => $this->when(
                $about->relationLoaded('reactions'),
                fn (): bool => $about->reactions->contains('type', SpotAbout::LIKE_REACTION),
            ),

            'created_at' => $about->created_at?->toIso8601String(),
            'updated_at' => $about->updated_at?->toIso8601String(),

            'can' => $this->resolvePermissions($about),
        ];
    }
}
