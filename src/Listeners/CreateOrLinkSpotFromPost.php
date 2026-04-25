<?php

namespace Modules\Stourify\Listeners;

use Illuminate\Support\Str;
use Modules\Social\Events\PostCreated;
use Modules\Stourify\Models\Spot;

class CreateOrLinkSpotFromPost
{
    public function handle(PostCreated $event): void
    {
        $post     = $event->post;
        $postData = $event->postData;

        // If post already has a spot_id, nothing to do
        if ($post->spot_id !== null) {
            return;
        }

        // If no location data provided, skip
        if (empty($postData['spot_name']) || empty($postData['spot_latitude']) || empty($postData['spot_longitude'])) {
            return;
        }

        $name = $postData['spot_name'];
        $lat  = round((float) $postData['spot_latitude'], 4);
        $lng  = round((float) $postData['spot_longitude'], 4);

        try {
            $spot = Spot::firstOrCreate(
                ['name' => $name, 'latitude' => $lat, 'longitude' => $lng],
                [
                    'created_by' => $post->user_id,
                    'slug'       => Str::slug($name).'-'.Str::random(8),
                    'status'     => 'active',
                ]
            );
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            $spot = Spot::where('name', $name)
                ->where('latitude', $lat)
                ->where('longitude', $lng)
                ->firstOrFail();
        }

        $post->updateQuietly(['spot_id' => $spot->id]);
        $post->clearCache();
    }
}
