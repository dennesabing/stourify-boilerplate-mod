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

            'spot_uuid' => $this->whenLoaded('spot', fn () => $review->spot->uuid),
            'author_uuid' => $this->whenLoaded('user', fn () => $review->user->uuid),

            'created_at' => $review->created_at?->toIso8601String(),
            'updated_at' => $review->updated_at?->toIso8601String(),

            'can' => $this->resolvePermissions($review),
        ];
    }
}
