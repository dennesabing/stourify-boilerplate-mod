<?php

declare(strict_types=1);

namespace Modules\Stourify\Http\Resources;

use App\Http\Resources\BaseResource;
use Illuminate\Http\Request;
use Modules\Stourify\Models\ExplorerProfile;

/**
 * An explorer's public identity — the profile header.
 *
 * A profile is public even when the account is private: the Instagram model,
 * where a private account still shows its name, bio and counts, but gates its
 * *content*. So there is no visibility test here; `is_private` is exposed
 * precisely so a client can render "Requested" versus "Following".
 *
 * The three counts are computed on read rather than read from the denormalized
 * columns on `sto_explorer_profiles`. Keeping those columns truthful needs
 * `Follow` and `Spot` observers plus an initial-compute path for follows that
 * predate the profile — a drift-prone amount of machinery for three indexed
 * `COUNT`s on a single-record read. The columns remain for a later pass if the
 * header ever becomes hot. The counts are attached to the model as
 * `*_computed` withCount-style aliases by the controller.
 *
 * `shows_location_on_spots` is a privacy setting, shown only to the owner.
 *
 * @property ExplorerProfile $resource
 */
class ProfileResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $profile = $this->resource;
        $isOwner = $request->user() !== null && $request->user()->id === $profile->user_id;

        return [
            'uuid' => $profile->uuid,
            'user_uuid' => $this->whenLoaded('user', fn () => $profile->user->uuid),

            'username' => $profile->username,
            'bio' => $profile->bio,
            'website' => $profile->website,
            'interests' => $profile->interests ?? [],

            'home_city' => new CityResource($this->whenLoaded('homeCity')),

            'is_private' => (bool) $profile->is_private,
            'shows_location_on_spots' => $this->when(
                $isOwner, fn (): bool => (bool) $profile->shows_location_on_spots
            ),

            'counts' => [
                'spots' => (int) ($profile->spots_computed ?? 0),
                'followers' => (int) ($profile->followers_computed ?? 0),
                'following' => (int) ($profile->following_computed ?? 0),
            ],

            'created_at' => $profile->created_at?->toIso8601String(),

            'can' => $this->resolvePermissions($profile),
        ];
    }
}
