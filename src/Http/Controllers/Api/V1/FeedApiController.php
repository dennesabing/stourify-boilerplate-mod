<?php

declare(strict_types=1);

namespace Modules\Stourify\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Stourify\Http\Requests\FeedIndexRequest;
use Modules\Stourify\Http\Resources\PostResource;
use Modules\Stourify\Models\Post;
use Modules\Stourify\Support\LoadsViewerReactions;

/**
 * The home feed — the ranked stream of posts on the Home tab.
 *
 * **Audience.** Exactly the posts the viewer is entitled to see, defined once
 * in `Post::scopeVisibleTo()` and shared with the post index so the two cannot
 * drift. The feed additionally shows only *published* posts — your own drafts
 * belong on your profile, not in your feed — which the top-level
 * `whereNotNull('published_at')` enforces on top of the audience scope. There
 * is no moderator bypass: a moderator's home feed is still their home feed.
 *
 * **Ranking.** Newest first (`published_at` desc, `id` desc as a stable
 * tiebreaker). This is a deliberate choice, not a placeholder for "real"
 * ranking: a cursor is only stable against a fixed, indexed, monotonic
 * ordering, and an engagement- or recency-decay score is none of those — under
 * such a score a cursor would skip or repeat posts as scores shifted between
 * pages. Personalized relevance ranking is an explicit post-beta concern (the
 * "For You" engine); recency is what ships correctly with cursors and the
 * client's offline page cache today.
 *
 * **No server cache.** Unlike every other list in the module this does not go
 * through `getCachedList()`, and that is intentional. The feed is personalized
 * (per follow graph) and cursor-keyed, so a server cache would be high
 * cardinality and would need busting on every new post across every viewer —
 * a thundering herd for no gain. The offline design puts feed persistence on
 * the client (React Query keeps the last N pages), not the server; see
 * technical-spec.md §7, "server-composed / ephemeral".
 */
class FeedApiController extends Controller
{
    use LoadsViewerReactions;

    /**
     * A cursor page of the viewer's home feed.
     */
    public function index(FeedIndexRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Post::class);

        $user = $request->user();
        $limit = (int) ($request->validated('limit') ?? 20);

        $query = Post::query()
            ->visibleTo($user)
            ->whereNotNull('published_at')
            ->with(['spot', 'user']);

        $posts = $this->withViewerReaction($query, $user)
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->cursorPaginate($limit)
            ->withQueryString();

        return PostResource::collection($posts);
    }
}
