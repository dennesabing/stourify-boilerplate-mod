<?php

declare(strict_types=1);

namespace Modules\Stourify\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Stourify\Models\ExplorerProfile;

/**
 * A person as a search hit — the compact card the People results render.
 *
 * The profile-sourced counterpart to ExplorerResource (which is User-sourced,
 * used by the follow graph). This one is keyed on the ExplorerProfile because
 * people search runs against the profile index, where the handle lives. It
 * carries no follower counts — a search list does not need them and computing
 * them per hit would be a query fan-out — and no email, ever.
 *
 * @property ExplorerProfile $resource
 */
class PersonResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $profile = $this->resource;

        return [
            'uuid' => $profile->uuid,
            'user_uuid' => $this->whenLoaded('user', fn () => $profile->user?->uuid),
            'username' => $profile->username,
            'name' => $this->whenLoaded('user', fn () => $profile->user?->name),
            'bio' => $profile->bio,
            'is_private' => (bool) $profile->is_private,
        ];
    }
}
