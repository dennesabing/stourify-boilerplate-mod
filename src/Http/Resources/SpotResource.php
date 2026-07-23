<?php

declare(strict_types=1);

namespace Modules\Stourify\Http\Resources;

use App\Http\Resources\BaseResource;
use Illuminate\Http\Request;
use Modules\Stourify\Models\Spot;

/**
 * The wire shape of a spot.
 *
 * Extends BaseResource so every payload carries the `can` index the mobile
 * client uses to decide which affordances to render — see
 * saas-boilerplate/docs/system-wide-docs/system-routing.md.
 *
 * `distance_km` is present only on responses from the nearby endpoint, where
 * the scope computes it. Elsewhere it is omitted rather than sent as null, so
 * a client can tell "not applicable" from "zero".
 *
 * @property Spot $resource
 */
class SpotResource extends BaseResource
{
    /**
     * `verify` is a module-specific ability on top of the standard set.
     *
     * @return array<int, string>
     */
    public static function resourceAbilities(): array
    {
        return ['verify'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $spot = $this->resource;

        return [
            'uuid' => $spot->uuid,
            'title' => $spot->title,
            'slug' => $spot->slug,
            'description' => $spot->description,

            'latitude' => $spot->latitude,
            'longitude' => $spot->longitude,
            'address' => $spot->address,
            $this->mergeWhen(isset($spot->distance_km), fn (): array => [
                'distance_km' => round((float) $spot->distance_km, 3),
            ]),

            'categories' => $spot->categories ?? [],
            'hours' => $spot->hours,

            'status' => $spot->status->value,
            'is_verified' => $spot->is_verified,

            'rating_average' => (float) $spot->rating_average,
            'reviews_count' => (int) $spot->reviews_count,
            'saves_count' => (int) $spot->saves_count,

            'city' => new CityResource($this->whenLoaded('city')),
            'contributor_uuid' => $this->whenLoaded('user', fn () => $spot->user->uuid),

            'created_at' => $spot->created_at?->toIso8601String(),
            'updated_at' => $spot->updated_at?->toIso8601String(),

            'can' => $this->resolvePermissions($spot),
        ];
    }
}
