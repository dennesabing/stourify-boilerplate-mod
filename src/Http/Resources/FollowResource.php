<?php

declare(strict_types=1);

namespace Modules\Stourify\Http\Resources;

use App\Http\Resources\BaseResource;
use Illuminate\Http\Request;
use Modules\Stourify\Enums\FollowStatus;
use Modules\Stourify\Models\Follow;

/**
 * A follow edge.
 *
 * Both endpoints are exposed rather than only "the other person", because the
 * same resource serves the Followers list, the Following list and the pending
 * requests screen — each of which cares about a different side. A client that
 * only wants the counterpart can pick it; one that needs to know which way the
 * edge points still can.
 *
 * @property Follow $resource
 */
class FollowResource extends BaseResource
{
    /**
     * @return array<int, string>
     */
    public static function resourceAbilities(): array
    {
        return ['accept'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $follow = $this->resource;

        return [
            'uuid' => $follow->uuid,
            'status' => $follow->status->value,
            'is_pending' => $follow->status === FollowStatus::Pending,

            'follower' => new ExplorerResource($this->whenLoaded('follower')),
            'followee' => new ExplorerResource($this->whenLoaded('followee')),

            'accepted_at' => $follow->accepted_at?->toIso8601String(),
            'created_at' => $follow->created_at?->toIso8601String(),

            'can' => $this->resolvePermissions($follow),
        ];
    }
}
