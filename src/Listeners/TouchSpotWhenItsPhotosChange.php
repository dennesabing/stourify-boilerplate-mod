<?php

declare(strict_types=1);

namespace Modules\Stourify\Listeners;

use Modules\Stourify\Models\Spot;
use Spatie\MediaLibrary\Conversions\Events\ConversionHasBeenCompletedEvent;
use Spatie\MediaLibrary\MediaCollections\Events\MediaHasBeenAddedEvent;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Marks a spot as changed when its photos change, so the offline sync actually
 * delivers them.
 *
 * ## The bug this exists to prevent
 *
 * A spot row carries `cover_photo_url` to the device (STOURIFY-192). The sync
 * only sends down rows whose `updated_at` has moved since the device last
 * asked, and **attaching a photo does not touch the spot** — the photo lives in
 * a different table entirely.
 *
 * So the sequence that every single spot in this app goes through was fatal:
 * the create flow writes the spot, the device pulls it (no photo yet, correctly
 * empty), and the photos upload a second or two later without moving
 * `updated_at`. That row is then never sent again. Not "until the next sync" —
 * **never**, because there is no future moment at which it becomes newer than
 * the cursor.
 *
 * It is a filing cabinet where folders are re-copied only when their
 * "last changed" sticker moves. Someone slips a photograph into a folder
 * without touching the sticker, and no amount of re-copying ever picks it up.
 *
 * ## Why it listens for three things and not one
 *
 * **The conversion is the one that matters.** `cover_photo_url` prefers the
 * thumbnail, and the thumbnail is generated *after* the upload, on a queue. A
 * listener that fired only on attach would send a row whose thumbnail did not
 * exist yet, fall back to the full-size original, and then never send it
 * again — the identical bug one step further along, and harder to see because
 * a photo would appear, just the wrong (multi-megabyte) one.
 *
 * **Attach is the safety net.** A file with no conversions registered, or one
 * whose conversion fails, still has a usable original URL and still deserves to
 * reach the device.
 *
 * **Deleting is a change too.** Remove a spot's only photo and the device must
 * be told, or it goes on showing a picture of something that is no longer
 * there.
 *
 * Touching more than once is deliberate and cheap: it is one `UPDATE` of a
 * timestamp, and the alternative — reasoning about which single event is
 * guaranteed to be last — is exactly the kind of cleverness that produced the
 * original bug.
 */
class TouchSpotWhenItsPhotosChange
{
    public function onMediaAdded(MediaHasBeenAddedEvent $event): void
    {
        $this->touch($event->media);
    }

    public function onConversionCompleted(ConversionHasBeenCompletedEvent $event): void
    {
        $this->touch($event->media);
    }

    public function onMediaDeleted(Media $media): void
    {
        $this->touch($media);
    }

    /**
     * Move the host spot's `updated_at`, and nothing else.
     *
     * Anything that is not a spot is ignored rather than touched: `Spot` is the
     * only synced model carrying a photo to the device, so touching a post or
     * an avatar's owner would be churn with no reader.
     */
    private function touch(Media $media): void
    {
        $model = $media->model;

        if (! $model instanceof Spot) {
            return;
        }

        // `touch()` rather than `save()`: the spot's own columns have not
        // changed and must not be rewritten from a possibly-stale in-memory
        // copy. It still fires the model's `updated` event, which is what
        // invalidates the cached reads.
        $model->touch();
    }
}
