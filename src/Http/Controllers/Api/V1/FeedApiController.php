<?php

declare(strict_types=1);

namespace Modules\Stourify\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\OrganizationContext;
use App\Traits\ApiResponses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Modules\Stourify\Http\Requests\FeedIndexRequest;
use Modules\Stourify\Http\Resources\PostResource;
use Modules\Stourify\Models\Post;
use Modules\Stourify\Support\AttachesExplorerProfiles;
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
 *
 * **A refusal says which refusal it is.** Two unrelated situations used to
 * share one sentence — "This action is unauthorized." — and the app could act
 * on neither: an account that belongs to no organization at all (never
 * enrolled, which is a provisioning fault) and an account that is properly
 * enrolled but may not read posts here. Both are still 403, because an empty
 * feed would be indistinguishable from a healthy new account who follows
 * nobody, and the fault would then surface nowhere at all. What changed is
 * that each refusal carries a `code` naming its cause — see STOURIFY-228.
 */
class FeedApiController extends Controller
{
    use ApiResponses, AttachesExplorerProfiles, LoadsViewerReactions;

    public function __construct(private readonly OrganizationContext $organizations) {}

    /**
     * A cursor page of the viewer's home feed.
     */
    public function index(FeedIndexRequest $request): AnonymousResourceCollection|JsonResponse
    {
        $user = $request->user();

        // The same question `authorize()` asked, of the same policy, with the
        // same answer — asked this way only so the refusal can carry a reason.
        // `authorize()` throws, and Laravel renders that throw as one fixed
        // sentence with nowhere to put one.
        if (Gate::forUser($user)->denies('viewAny', Post::class)) {
            return $this->refusal();
        }

        $limit = (int) ($request->validated('limit') ?? 20);

        $query = Post::query()
            ->visibleTo($user)
            ->whereNotNull('published_at')
            // `user.media` and `media` are eager-loaded here, not resolved
            // inside the resource, so rendering PostResource::author and
            // PostResource::media for a page of posts costs one query each
            // total rather than one per row.
            ->with(['spot', 'user.media', 'media', 'tags']);

        $posts = $this->withViewerReaction($query, $user)
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->cursorPaginate($limit)
            ->withQueryString();

        $this->attachExplorerProfiles(
            $posts->getCollection()->pluck('user')->filter()->unique('id')->values()
        );

        return PostResource::collection($posts);
    }

    /**
     * Say which of the two reasons this caller was refused for.
     *
     * The question is "did this request resolve an organization at all", asked
     * of the context singleton the organization middlewares fill in — not
     * whether the account's `current_organization_id` column happens to be
     * null. `SetCurrentOrganization` auto-selects an account's only
     * organization when that column is empty and writes the choice back, so a
     * null column on an enrolled account is a passing state rather than a
     * fault.
     */
    private function refusal(): JsonResponse
    {
        if (! $this->organizations->has()) {
            return $this->error(
                'This account is not linked to a Stourify organization yet, so it has no feed to show. '
                .'Signing out and back in usually fixes it; if it does not, the account needs to be set up again.',
                403,
                null,
                'NO_ORGANIZATION',
            );
        }

        return $this->error(
            'This account is not allowed to view posts in this organization.',
            403,
            null,
            'FEED_ACCESS_DENIED',
        );
    }
}
