<?php

declare(strict_types=1);

namespace Modules\Stourify\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Tag;
use App\Services\OrganizationContext;
use App\Traits\ApiResponses;
use Illuminate\Http\JsonResponse;
use Modules\Stourify\Http\Requests\TagShowRequest;
use Modules\Stourify\Http\Resources\TagResource;
use Modules\Stourify\Support\Hashtags\HashtagParser;

/**
 * Looking a hashtag up by the word itself.
 *
 * ## Why this endpoint exists at all
 *
 * A tag page has to be able to say three different things, and without this it
 * can only say two:
 *
 * | What is true | What the app should say |
 * |---|---|
 * | the tag exists, nothing carries it | *Nothing has been tagged #x yet* |
 * | no tag by that word exists | *No such tag* |
 * | the request failed | *Could not load — try again* |
 *
 * With only a listing to go on, the first and second are the same empty array,
 * and — worse — so is the third if the app treats a failure as "no results".
 * This project has already paid for that confusion six times over
 * (STOURIFY-85 to STOURIFY-90), which is why the distinction is made available
 * to the client rather than left for it to guess at.
 *
 * ## Why the path carries a slug and not a UUID
 *
 * Everything else in this module binds by UUID, because its subject has no
 * natural name. A hashtag does: the word **is** the identity. A link somebody
 * shares, a tap on a word in a caption the app is already holding, and a page
 * opened straight from a deep link all have the word and none of them has a
 * UUID — so binding by UUID would mean a round trip before the app could ask
 * for anything.
 */
class TagApiController extends Controller
{
    use ApiResponses;

    /**
     * One hashtag, by its slug. `404` when this organisation has no such word.
     */
    public function show(TagShowRequest $request, string $slug): JsonResponse
    {
        $organizationId = app(OrganizationContext::class)->id() ?? 0;

        $cacheKey = sprintf(
            'api:stourify:tags:show:org:%d:%s',
            $organizationId,
            hash('sha256', $slug),
        );

        // `getCachedList` rather than `getCached`: the latter is an instance
        // method that caches a record you already hold, and the whole question
        // here is whether the record exists at all.
        /** @var Tag|null $tag */
        $tag = Tag::getCachedList($cacheKey, fn (): ?Tag => Tag::query()
            ->where('slug', $slug)
            // A hashtag only. A tag an administrator created in the admin panel
            // lives in this same table under a null type, and it belongs to a
            // different surface with a different audience — surfacing it here
            // would leak the admin vocabulary into the product.
            ->where('type', HashtagParser::TAG_TYPE)
            // A word an administrator has taken down answers exactly as a word
            // nobody ever typed does. From where the reader stands the two are
            // the same fact -- there is nothing here to open -- and the app
            // already has a correct, tested sentence for it. Inventing a
            // separate state would mean writing copy that explains a
            // moderation decision to somebody who did not make it
            // (STOURIFY-174).
            ->notSuppressed()
            ->first());

        if ($tag === null) {
            return $this->error('No such tag.', 404);
        }

        return $this->success(new TagResource($tag));
    }
}
