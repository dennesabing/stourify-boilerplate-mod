<?php

declare(strict_types=1);

namespace Modules\Stourify\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Crud\CrudService;
use App\Services\OrganizationContext;
use App\Traits\ApiResponses;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Stourify\Enums\PostVisibility;
use Modules\Stourify\Http\Requests\PostIndexRequest;
use Modules\Stourify\Http\Requests\PostStoreRequest;
use Modules\Stourify\Http\Requests\PostUpdateRequest;
use Modules\Stourify\Http\Resources\PostResource;
use Modules\Stourify\Models\Post;
use Modules\Stourify\Models\Spot;
use Modules\Stourify\Policies\PostPolicy;
use Modules\Stourify\Support\AttachesExplorerProfiles;
use Modules\Stourify\Support\LoadsViewerReactions;

/**
 * Posts — one explorer's visit to a spot, and the unit the Home Feed renders.
 *
 * The audience rule is enforced in two places because it has to be:
 * `PostPolicy::view()` gates a single record, and `visibleTo()` below
 * constrains the *query* so a list cannot page through posts the viewer was
 * never entitled to. The two encode the same rule and are tested against each
 * other — if they ever disagree, the list is the one that leaks.
 *
 * @see PostPolicy
 */
class PostApiController extends Controller
{
    use ApiResponses, AttachesExplorerProfiles, LoadsViewerReactions;

    public function index(PostIndexRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Post::class);

        $user = $request->user();
        $filters = $request->validated();
        $perPage = (int) ($filters['per_page'] ?? 25);

        $cacheKey = sprintf(
            'api:stourify:posts:index:org:%d:user:%d:%s',
            app(OrganizationContext::class)->id() ?? 0,
            $user->id,
            hash('sha256', json_encode($filters) ?: ''),
        );

        $posts = Post::getCachedList($cacheKey, fn (): LengthAwarePaginator => $this
            ->withViewerReaction($this->visibleTo(Post::query(), $user), $user)
            // `user.media` and `media` are eager-loaded here, not resolved
            // inside the resource, so rendering PostResource::author and
            // PostResource::media for a page of posts costs one query each
            // total rather than one per row.
            ->with(['spot', 'user.media', 'media', 'tags'])
            ->when(! empty($filters['spot_uuid']), fn (Builder $q) => $q->whereHas(
                'spot', fn (Builder $spot) => $spot->where('uuid', $filters['spot_uuid'])
            ))
            ->when(! empty($filters['mine']), fn (Builder $q) => $q->where('user_id', $user->id))
            // Narrows an ALREADY-scoped query: `visibleTo()` above has run, so
            // this cannot surface another explorer's unpublished or
            // followers-only posts. It is a filter, never a permission.
            ->when(! empty($filters['user_uuid']), fn (Builder $q) => $q->whereHas(
                'user', fn (Builder $u) => $u->where('uuid', $filters['user_uuid'])
            ))
            ->orderBy($filters['sort'] ?? 'published_at', $filters['direction'] ?? 'desc')
            ->paginate($perPage));

        $this->attachExplorerProfiles(
            $posts->getCollection()->pluck('user')->filter()->unique('id')->values()
        );

        return PostResource::collection($posts);
    }

    public function show(Request $request, Post $post): JsonResponse
    {
        $this->authorize('view', $post);

        $post->load([
            'spot',
            'user.media',
            'media',
            'tags',
            'reactions' => fn ($q) => $q->where('user_id', $request->user()->id),
        ]);

        $this->attachExplorerProfiles(collect([$post->user])->filter()->values());

        return $this->success(new PostResource($post));
    }

    /**
     * Create a post.
     *
     * ## Why this endpoint has to be idempotent
     *
     * Posting a letter and only writing the tracking number down once the
     * receipt is in your hand. Drop the receipt on the way out and you cannot
     * tell whether the letter went — so you post a second one.
     *
     * That is this endpoint. The post's id is minted here, server-side, so a
     * client learns it only from the response. A client whose response is lost
     * — a dropped radio, a proxy timeout, its own process killed — cannot
     * distinguish that from a request that never arrived, so a well-behaved
     * offline client MUST retry, and the retry creates a second post
     * (STOURIFY-166). No ordering the client adopts closes that window,
     * because it cannot know an id the server has not sent yet.
     *
     * What closes it is the client NAMING the request. `idempotency_key` is
     * optional and client-minted, so callers that never retry are unaffected;
     * the app derives it from the send-later queue row the post is waiting in,
     * which stays put for exactly as long as the retries do.
     *
     * A repeat gets the post the first attempt made, with `200` rather than
     * `201`, and its body is ignored. The key asserts *this is the request I
     * already sent you*, so a body that disagrees is a confused client rather
     * than a new intention — and the committed post is the one that client may
     * already have acted on.
     */
    public function store(PostStoreRequest $request): JsonResponse
    {
        $data = $request->validated();
        $author = $request->user();
        $idempotencyKey = $data['idempotency_key'] ?? null;

        // The retry path. Checked AFTER the FormRequest has authorized, so a
        // caller who may not create posts at all cannot use this endpoint to
        // find out which keys exist.
        if ($idempotencyKey !== null) {
            $already = $this->findByIdempotencyKey($author, $idempotencyKey);

            if ($already !== null) {
                return $this->success(
                    new PostResource($this->freshForResponse($already, $author)),
                    200,
                    'Post was already created.',
                );
            }
        }

        try {
            $post = CrudService::for(Post::class)->create([
                'spot_id' => $this->resolveSpotId($data),
                'user_id' => $author->id,
                'caption' => $data['caption'] ?? null,
                // A post nobody assigned a visibility to starts PRIVATE, not public
                // (STOURIFY-105). The safe direction for a privacy default is the
                // closed one: an author who says nothing shares nothing. An explicit
                // `visibility` in the request still wins — this only answers the
                // question the caller did not ask.
                'visibility' => $data['visibility'] ?? PostVisibility::Private->value,
                // The server owns this clock, never the client — see PostStoreRequest.
                'published_at' => ($data['publish'] ?? false) ? now() : null,
                'idempotency_key' => $idempotencyKey,
            ]);
        } catch (UniqueConstraintViolationException $raced) {
            // Two retries in flight at once: both read no existing post, both
            // insert, and the database — not the lookup above — is what
            // actually holds the guarantee. The loser reads the winner's post.
            //
            // Re-reading is deliberate over swallowing: if the violation came
            // from anything other than our key, $already is null and the
            // exception is re-thrown rather than silently reported as success.
            $already = $idempotencyKey === null
                ? null
                : $this->findByIdempotencyKey($author, $idempotencyKey);

            if ($already === null) {
                throw $raced;
            }

            return $this->success(
                new PostResource($this->freshForResponse($already, $author)),
                200,
                'Post was already created.',
            );
        }

        return $this->success(
            new PostResource($this->freshForResponse($post, $author)),
            201,
            'Post created successfully.',
        );
    }

    public function update(PostUpdateRequest $request, Post $post): JsonResponse
    {
        $data = $request->validated();

        if (array_key_exists('spot_uuid', $data)) {
            $data['spot_id'] = $this->resolveSpotId($data);
        }
        unset($data['spot_uuid']);

        $post = CrudService::for(Post::class)->update($post, $data);

        return $this->success(
            new PostResource($this->freshForResponse($post, $request->user())),
            200,
            'Post updated successfully.',
        );
    }

    /**
     * Publish a post whose media has finished uploading.
     *
     * Idempotent: republishing an already-published post is a no-op rather
     * than an error, and does not move it up the feed. An offline client
     * retrying a queued write must be able to send this twice safely.
     */
    public function publish(Request $request, Post $post): JsonResponse
    {
        $this->authorize('publish', $post);

        if ($post->published_at === null) {
            $post = CrudService::for(Post::class)->update($post, ['published_at' => now()]);
        }

        return $this->success(
            new PostResource($this->freshForResponse($post, $request->user())),
            200,
            'Post published successfully.',
        );
    }

    public function destroy(Post $post): JsonResponse
    {
        CrudService::for(Post::class)->delete($post);

        return $this->success(null, 200, 'Post deleted successfully.');
    }

    /**
     * Reload a just-written post the way the read paths do: spot, author
     * (with the media needed for `author.avatar_url`), and the viewer's own
     * reaction via the same `withViewerReaction()` mechanism the feed uses —
     * so `is_liked` is present, not absent, on write responses too.
     */
    private function freshForResponse(Post $post, User $viewer): Post
    {
        // `withTrashed()` rather than `query()`, for exactly one caller:
        // `store()` answering a retry whose original post has since been
        // deleted. It hands that post back — see findByIdempotencyKey() for
        // why the lookup must see deleted rows — and a non-trashed reload
        // would then fail to find it and answer 404, which is a confusing
        // reply to a request that created nothing and found what it asked for.
        // Inert for every other caller: route binding never resolves a deleted
        // post, so update() and publish() are never holding one.
        $post = $this->withViewerReaction(Post::withTrashed()->with(['spot', 'user.media', 'tags']), $viewer)
            ->whereKey($post->getKey())
            ->firstOrFail();

        $this->attachExplorerProfiles(collect([$post->user])->filter()->values());

        return $post;
    }

    /**
     * Constrain the index query to what this viewer may list.
     *
     * The audience rule itself lives in `Post::scopeVisibleTo()` — one
     * definition, shared with the home feed, so the two enforcement surfaces
     * cannot drift. The index layers a single extra privilege on top: a
     * moderator (`viewAnyRestricted`) lists everything, including drafts and
     * private posts, because the index doubles as a management surface. The
     * feed never grants that.
     *
     * @param  Builder<Post>  $query
     * @return Builder<Post>
     */
    private function visibleTo(Builder $query, User $user): Builder
    {
        if ($user->can('viewAnyRestricted', Post::class)) {
            return $query;
        }

        return $query->visibleTo($user);
    }

    /**
     * Find this author's post carrying a given client-minted key, if any.
     *
     * ## Two things about this query are deliberate
     *
     * **It is not cached, and that is a considered exception to the platform's
     * read rule.** Reads normally go through `getCached()`. This one must not:
     * a cache miss on a key that does exist creates precisely the duplicate
     * post this endpoint exists to prevent, so the correctness of the whole
     * mechanism rests on this question reaching the database every time. That
     * rule governs read paths; this is a uniqueness probe inside a write.
     *
     * **It includes soft-deleted posts.** A deleted post keeps its row and its
     * key, and the unique index keeps covering both — so a lookup that skipped
     * deleted rows would find nothing, attempt the insert, and hit the index,
     * turning a retry into a 500. A retry matching a post the author has since
     * deleted therefore gets that deleted post back; the client's next call,
     * `posts/{uuid}/publish`, does not resolve it, and the author sees the
     * failure on the offline queue screen rather than a post they deleted
     * quietly reappearing.
     */
    private function findByIdempotencyKey(User $author, string $idempotencyKey): ?Post
    {
        return Post::withTrashed()
            ->where('user_id', $author->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveSpotId(array $data): ?int
    {
        if (empty($data['spot_uuid'])) {
            return null;
        }

        return Spot::query()->where('uuid', $data['spot_uuid'])->value('id');
    }
}
