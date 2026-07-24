<?php

declare(strict_types=1);

namespace Modules\Stourify\Http\Resources;

use App\Http\Resources\BaseResource;
use Illuminate\Http\Request;
use Modules\Stourify\Models\WishlistItem;

/**
 * A saved spot.
 *
 * `city` is embedded because the Wishlist screen groups by it; the full spot
 * rides along so a saved card renders without a second fetch.
 *
 * @property WishlistItem $resource
 */
class WishlistItemResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $item = $this->resource;

        return [
            'uuid' => $item->uuid,
            'note' => $item->note,
            'is_downloaded_offline' => (bool) $item->is_downloaded_offline,

            'spot' => new SpotResource($this->whenLoaded('spot')),
            'city' => new CityResource($this->whenLoaded('city')),

            'created_at' => $item->created_at?->toIso8601String(),

            'can' => $this->resolvePermissions($item),
        ];
    }
}
