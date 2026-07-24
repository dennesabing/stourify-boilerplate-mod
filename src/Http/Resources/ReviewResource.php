<?php

declare(strict_types=1);

namespace Modules\Stourify\Http\Resources;

use App\Http\Resources\BaseResource;
use Illuminate\Http\Request;
use Modules\Stourify\Models\Review;

/**
 * @property Review $resource
 */
class ReviewResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $review = $this->resource;

        return [
            'uuid' => $review->uuid,
            'rating' => $review->rating,
            'body' => $review->body,
            'helpful_count' => (int) $review->helpful_count,

            // Present only when the viewer's own reaction was eager-loaded —
            // whether the caller has already marked this review helpful. Absent
            // rather than false when not evaluated.
            'marked_helpful' => $this->when(
                $review->relationLoaded('reactions'),
                fn (): bool => $review->reactions->contains('type', Review::HELPFUL_REACTION),
            ),

            'spot_uuid' => $this->whenLoaded('spot', fn () => $review->spot->uuid),
            'author_uuid' => $this->whenLoaded('user', fn () => $review->user->uuid),

            'created_at' => $review->created_at?->toIso8601String(),
            'updated_at' => $review->updated_at?->toIso8601String(),

            'can' => $this->resolvePermissions($review),
        ];
    }
}
