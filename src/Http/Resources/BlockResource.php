<?php

declare(strict_types=1);

namespace Modules\Stourify\Http\Resources;

use App\Http\Resources\BaseResource;
use Illuminate\Http\Request;
use Modules\Stourify\Models\Block;

/**
 * A block, as its author sees it.
 *
 * Only `blocked` is exposed — never `blocker`. This resource is only ever
 * rendered for the person who created the row, so the blocker is the caller
 * and repeating them buys nothing; more to the point, a resource that could
 * name a blocker is a resource that could one day be returned to the blocked
 * party, who is never told. `FollowResource` exposes both ends because a
 * follow is mutual knowledge. A block is not.
 *
 * @property Block $resource
 */
class BlockResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $block = $this->resource;

        return [
            'uuid' => $block->uuid,
            'blocked' => new ExplorerResource($this->whenLoaded('blocked')),
            'created_at' => $block->created_at?->toIso8601String(),

            'can' => $this->resolvePermissions($block),
        ];
    }
}
