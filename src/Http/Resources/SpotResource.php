<?php

declare(strict_types=1);

namespace Modules\Stourify\Http\Resources;

use App\Http\Resources\BaseResource;
use Illuminate\Http\Request;
use Modules\Stourify\Models\Spot;
use Modules\Stourify\Support\Hashtags\RendersTags;

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
    use RendersTags;

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

            /*
             * A contributor can hide the position of their spots
             * (`shows_location_on_spots`), and when they do the keys are ABSENT
             * rather than blurred or nulled (STOURIFY-185).
             *
             * Rounding to a coarse grid was the tempting alternative — every
             * client keeps working and a map still has something to draw. It
             * lost because it is a lie the client cannot detect: the response
             * still looks like a position, so the app renders a pin somewhere
             * plausible and wrong while the user believes it is hidden. Absence
             * is the only version a client can recognise and render honestly.
             *
             * `distance_km` goes with them. It is the same fact expressed as a
             * radius, and leaving it behind would hand back what the two lines
             * above just withheld.
             */
            $this->mergeWhen(! $spot->locationHiddenFrom($request->user()), fn (): array => [
                'latitude' => $spot->latitude,
                'longitude' => $spot->longitude,
                ...(isset($spot->distance_km) ? ['distance_km' => round((float) $spot->distance_km, 3)] : []),
            ]),

            'address' => $spot->address,

            'categories' => $spot->categories ?? [],
            'hours' => $spot->hours,

            'status' => $spot->status->value,
            'is_verified' => $spot->is_verified,

            'rating_average' => (float) $spot->rating_average,
            'reviews_count' => (int) $spot->reviews_count,
            'saves_count' => (int) $spot->saves_count,

            'city' => new CityResource($this->whenLoaded('city')),
            'contributor_uuid' => $this->whenLoaded('user', fn () => $spot->user->uuid),

            // The photo gallery's data source. Always an array, never null —
            // an unattached spot still owes the client "no photos", not the
            // absence of the key. `media` must be eager-loaded by the caller
            // (see SpotApiController) so this costs one query for the whole
            // page, not one per row.
            'media' => $this->whenLoaded('media', fn (): array => $spot->getMedia('attachments')
                ->map(fn ($media): array => [
                    'uuid' => $media->uuid,
                    'url' => $media->getUrl(),
                    'thumb_url' => $media->hasGeneratedConversion('thumb') ? $media->getUrl('thumb') : null,
                ])->all(), []),

            // The hashtags the author typed in the text, ready to render as
            // links. Only hashtags — a tag an administrator filed this under
            // is a different surface with a different audience. Present only
            // when eager-loaded, like `media` above and for the same reason:
            // a page of 25 costs one query, not 25.
            'tags' => $this->whenLoaded('tags', fn (): array => $this->hashtagsOf($spot), []),

            'created_at' => $spot->created_at?->toIso8601String(),
            'updated_at' => $spot->updated_at?->toIso8601String(),

            'can' => $this->resolvePermissions($spot),
        ];
    }
}
