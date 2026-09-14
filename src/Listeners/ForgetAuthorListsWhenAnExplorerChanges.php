<?php

declare(strict_types=1);

namespace Modules\Stourify\Listeners;

use App\Models\Comment;
use App\Models\User;
use Modules\Stourify\Models\Follow;
use Modules\Stourify\Models\Post;
use Modules\Stourify\Models\Review;
use Modules\Stourify\Models\SpotAbout;
use Spatie\MediaLibrary\MediaCollections\Events\MediaHasBeenAddedEvent;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Throws away the cached lists that carry a copy of somebody's name or photo,
 * the moment either one changes.
 *
 * ## The bug this exists to prevent
 *
 * A page of posts is cached whole, and every post on it carries its author's
 * name and photo URL — copied in at the moment the page was built. A name
 * lives on the platform's `users` table and a photo in its `media` table, and
 * neither of those is a post, so changing one never touched the posts cache.
 * Rename yourself and your own Posts tab went on calling you by the old name
 * for up to the cache's hour (STOURIFY-307).
 *
 * It is a noticeboard of printed flyers with your name on them. Changing your
 * name at the front desk does not reprint the flyers; somebody has to take
 * them down.
 *
 * ## Why here, and why by listening
 *
 * The name and the photo are written by platform routes (`PUT /me`,
 * `POST|DELETE /me/avatar`), and the platform may not name a module class, so
 * those routes cannot clear this module's caches themselves. They do not have
 * to: saving a user and adding or deleting a media row are events the platform
 * already announces, and this module owns the copies, so it listens.
 * `TouchSpotWhenItsPhotosChange` listens to the same media events for the same
 * reason.
 *
 * ## Why it clears whole lists
 *
 * A tag flush cannot pick out the pages one author appears on, and a list of
 * pages keyed per viewer and per filter cannot be enumerated. Clearing the
 * lists is what a post save already does to the posts cache, so this adds no
 * new kind of cost — and a rename or a new photo is rare next to a new post.
 */
class ForgetAuthorListsWhenAnExplorerChanges
{
    /**
     * Every cached list whose rows render an author's name or photo:
     * `PostResource`, `CommentResource`, `ReviewResource` and
     * `SpotAboutResource` through `author`, and follow lists through
     * `ExplorerResource`.
     *
     * @var list<class-string>
     */
    private const LISTS_THAT_COPY_AN_AUTHOR = [
        Post::class,
        Comment::class,
        Review::class,
        SpotAbout::class,
        Follow::class,
    ];

    public function onUserUpdated(User $user): void
    {
        if ($user->wasChanged('name')) {
            $this->forget();
        }
    }

    public function onMediaAdded(MediaHasBeenAddedEvent $event): void
    {
        $this->forgetIfAvatar($event->media);
    }

    public function onMediaDeleted(Media $media): void
    {
        $this->forgetIfAvatar($media);
    }

    /**
     * Only a user's `avatar` collection is a photo any of these lists copies.
     *
     * Compared on the stored morph type rather than by loading `$media->model`:
     * it costs no query, and on a delete the owner may already be going too.
     */
    private function forgetIfAvatar(Media $media): void
    {
        if ($media->collection_name !== 'avatar' || $media->model_type !== (new User)->getMorphClass()) {
            return;
        }

        $this->forget();
    }

    private function forget(): void
    {
        foreach (self::LISTS_THAT_COPY_AN_AUTHOR as $model) {
            $model::clearListCache();
        }
    }
}
