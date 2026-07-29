<?php

declare(strict_types=1);

namespace Modules\Stourify\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Crud\CrudService;
use App\Services\OrganizationContext;
use App\Traits\ApiResponses;
use Illuminate\Database\Eloquent\Builder;
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
            ->with(['spot', 'user.media', 'media'])
            ->when(! empty($filters['spot_uuid']), fn (Builder $q) => $q->whereHas(
                'spot', fn (Builder $spot) => $spot->where('uuid', $filters['spot_uuid'])
            ))
            ->when(! empty($filters['mine']), fn (Builder $q) => $q->where('user_id', $user->id))
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
            'reactions' => fn ($q) => $q->where('user_id', $request->user()->id),
        ]);

        $this->attachExplorerProfiles(collect([$post->user])->filter()->values());

        return $this->success(new PostResource($post));
    }

    public function store(PostStoreRequest $request): JsonResponse
    {
        $data = $request->validated();

        $post = CrudService::for(Post::class)->create([
            'spot_id' => $this->resolveSpotId($data),
            'user_id' => $request->user()->id,
            'caption' => $data['caption'] ?? null,
            'visibility' => $data['visibility'] ?? PostVisibility::Public->value,
            // The server owns this clock, never the client — see PostStoreRequest.
            'published_at' => ($data['publish'] ?? false) ? now() : null,
        ]);

        return $this->success(
            new PostResource($this->freshForResponse($post, $request->user())),
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
        $post = $this->withViewerReaction(Post::query()->with(['spot', 'user.media']), $viewer)
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
