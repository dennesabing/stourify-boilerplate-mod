<?php

declare(strict_types=1);

namespace Modules\Stourify\Support\Hashtags;

use App\Models\Tag;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * The `tags` array an API resource emits, in one place so a post and a spot
 * cannot describe the same thing two different ways.
 *
 * Only hashtags are shown. A record can also carry tags an administrator
 * attached from the admin panel, and those belong to a different surface with
 * a different audience — a reader tapping a word in a caption expects to land
 * on that word, not on an internal label somebody filed the post under.
 */
trait RendersTags
{
    /**
     * @return list<array{slug: string, name: string}>
     */
    protected function hashtagsOf(Model $record): array
    {
        /** @var Collection<int, Tag> $tags */
        $tags = $record->getRelation('tags');

        return $tags
            ->filter(fn (Tag $tag): bool => $tag->type === HashtagParser::TAG_TYPE)
            ->map(fn (Tag $tag): array => ['slug' => (string) $tag->slug, 'name' => (string) $tag->name])
            ->values()
            ->all();
    }
}
