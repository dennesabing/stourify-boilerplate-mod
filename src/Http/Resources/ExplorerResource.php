<?php

declare(strict_types=1);

namespace Modules\Stourify\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Stourify\Support\AttachesExplorerProfiles;

/**
 * The public face of an explorer — what a followers list, a spot byline or a
 * post author slot renders.
 *
 * Deliberately does NOT extend BaseResource: there is no `can` index here
 * because the abilities that matter are on the *edge*, not the person, and a
 * `can` block on a user would invite a client to treat it as authorization
 * over that account.
 *
 * Email is never exposed. The handle and bio come from the module's
 * ExplorerProfile, which may not exist yet for a freshly registered user, so
 * every profile-sourced field degrades to null rather than assuming the row.
 *
 * The `stourifyProfile` relation is not declared on `User` — the boilerplate
 * is module-agnostic and must stay that way. It is attached from the module
 * side in one query per page; see AttachesExplorerProfiles.
 *
 * @see AttachesExplorerProfiles
 *
 * @property User $resource
 */
class ExplorerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $this->resource;
        $profile = $user->relationLoaded('stourifyProfile') ? $user->stourifyProfile : null;

        return [
            'uuid' => $user->uuid,
            'name' => $user->name,

            'username' => $profile?->username,
            'bio' => $profile?->bio,
            'is_private' => (bool) ($profile?->is_private ?? false),
        ];
    }
}
