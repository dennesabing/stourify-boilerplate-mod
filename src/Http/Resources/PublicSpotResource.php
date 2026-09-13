<?php

declare(strict_types=1);

namespace Modules\Stourify\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Stourify\Models\Spot;

/**
 * A spot as a stranger on the internet sees it — the public page's data
 * (STOURIFY-301).
 *
 * **An allow-list, not `SpotResource` with keys removed.** Everything here can be
 * read by anyone who has the link, so the safe way to write it is to name what
 * goes out and let everything else stay home by default. Deriving it from the
 * in-app resource would mean every field somebody later adds there — a
 * contributor's uuid, a moderation flag — appears here too, and nobody would be
 * looking at this file when it happened.
 *
 * Deliberately absent: the contributor (email, ids, handle), `status`, `can`,
 * `slug`, `hours`, the owner, and the save count.
 *
 * **Location is the stranger's view, always.** `locationHiddenFrom(null)` answers
 * for nobody in particular, so a contributor opening their own share link sees
 * what everyone else sees rather than being told a hidden position is visible.
 * When it is hidden the street `address` goes too: on a page anyone can open, a
 * street address IS the location. The city stays — it is a town, not a door.
 *
 * `media` and `city` must be eager-loaded by the caller.
 *
 * @property Spot $resource
 */
class PublicSpotResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $spot = $this->resource;

        return [
            'uuid' => $spot->uuid,
            'title' => $spot->title,
            'description' => $spot->description,
            'categories' => $spot->categories ?? [],
            'city' => $spot->city?->name,
            'is_verified' => $spot->is_verified,
            'rating_average' => (float) $spot->rating_average,
            'reviews_count' => (int) $spot->reviews_count,
            'image_url' => $this->imageUrl($spot),

            $this->mergeWhen(! $spot->locationHiddenFrom(null), fn (): array => [
                'latitude' => $spot->latitude,
                'longitude' => $spot->longitude,
                'address' => $spot->address,
            ]),

            'share_url' => $spot->publicShareUrl(),
        ];
    }

    /**
     * The one photo that stands for the spot on its page, sized for a phone
     * screen: the `medium` conversion when it exists, the original otherwise.
     */
    private function imageUrl(Spot $spot): ?string
    {
        $media = $spot->getMedia('attachments')->first();

        if ($media === null) {
            return null;
        }

        // Building a URL reads `$media->model`; we are that model, so say so
        // rather than let it fetch the spot back from the database.
        $media->setRelation('model', $spot);

        return $media->hasGeneratedConversion('medium')
            ? $media->getUrl('medium')
            : $media->getUrl();
    }
}
