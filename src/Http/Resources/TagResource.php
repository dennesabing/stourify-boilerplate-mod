<?php

declare(strict_types=1);

namespace Modules\Stourify\Http\Resources;

use App\Http\Resources\BaseResource;
use App\Models\Tag;
use Illuminate\Http\Request;

/**
 * A hashtag, as an explorer meets it: the word they typed and the word the app
 * shows them.
 *
 * Deliberately thin, and the omission worth explaining is the **count**. A tag
 * page could show "412 posts", and every comparable app does — but that number
 * is either a query per tag in a list of tags, or a column that has to be kept
 * up to date on every attach and detach and goes quietly wrong the first time
 * something bypasses the path that maintains it. Nobody has asked for it, so it
 * is not here (STOURIFY-172).
 *
 * `slug` is the matching form and the thing to put in a URL; `name` is the
 * spelling the first person to write the word used, which is why a tag page can
 * read `#StreetFood` rather than shouting the lowercased version at everybody.
 *
 * @property Tag $resource
 */
class TagResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $tag = $this->resource;

        return [
            'uuid' => $tag->uuid,
            'slug' => $tag->slug,
            'name' => $tag->name,
        ];
    }
}
