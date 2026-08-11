<?php

declare(strict_types=1);

namespace Modules\Stourify\Http\Resources;

use App\Http\Resources\BaseResource;
use Illuminate\Http\Request;
use Modules\Stourify\Enums\FollowStatus;
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
 * `viewer` describes the CALLER's relationship to this profile, not the
 * profile itself, and it is the only part of this payload that differs per
 * reader. It exists because a Follow button cannot be rendered truthfully
 * without it: before STOURIFY-35 nothing in the platform told a client whether
 * it already followed the explorer it was looking at, so the button always
 * read "Follow" and unfollowing was impossible — the edge is addressed by its
 * own uuid, and the client had no way to learn it short of paging the whole
 * follow list.
 *
 * `follow_status` is deliberately three-valued (`null` / `pending` /
 * `active`) rather than a boolean pair. A pending request to a private account
 * renders "Requested", which is neither following nor not-following, and
 * collapsing it into `is_following` would make the button offer to send a
 * request that already exists.
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

            // The explorer's display name, from the platform account. Already
            // public on every post, review and search hit through their
            // `author`/`name` keys, so this exposes nothing new — but without
            // it the profile header was the ONE surface that could not show
            // it, and rendered the username twice instead: once as the name
            // and once as the handle (found on the live run for STOURIFY-35).
            'name' => $this->whenLoaded('user', fn () => $profile->user->name),

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

            'viewer' => [
                'is_self' => $isOwner,
                // Only an ACTIVE edge is following. A pending request is
                // reported through follow_status, never here.
                'is_following' => $profile->viewer_follow_status === FollowStatus::Active->value,
                'follow_status' => $profile->viewer_follow_status,
                'follow_uuid' => $profile->viewer_follow_uuid,
            ],

            'created_at' => $profile->created_at?->toIso8601String(),

            'can' => $this->resolvePermissions($profile),
        ];
    }
}
