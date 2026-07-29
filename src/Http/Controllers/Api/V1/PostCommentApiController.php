<?php

declare(strict_types=1);

namespace Modules\Stourify\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CommentResource;
use App\Http\Resources\CommentResourceCollection;
use App\Models\Comment;
use App\Services\Crud\CrudService;
use App\Traits\ApiResponses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Stourify\Http\Requests\PostCommentStoreRequest;
use Modules\Stourify\Models\Post;

/**
 * Comments on a post, addressed by the post's uuid.
 *
 * Comments themselves live on the boilerplate's generic, polymorphic
 * `App\Models\Comment` — this controller is a module-owned adapter that
 * addresses the host the way every other Stourify route does (uuid, never
 * FQCN + int) and layers the audience rule on top: `PostPolicy::view()` is
 * checked before either endpoint touches a comment, so a stranger who
 * cannot see a private or unpublished post cannot read or write its
 * comments either, regardless of what permissions they hold on comments in
 * the abstract. `CommentPolicy` (via CrudService) still enforces its own
 * `posts.comments.*` permission on top of that — the two checks are
 * independent layers, not a substitute for one another.
 */
class PostCommentApiController extends Controller
{
    use ApiResponses;

    public function index(Request $request, Post $post): CommentResourceCollection
    {
        $this->authorize('view', $post);

        $page = (int) $request->input('page', 1);
        $cacheKey = sprintf('api:stourify:posts:%d:comments:index:page:%d', $post->id, $page);

        $comments = Comment::getCachedList(
            $cacheKey,
            fn (): LengthAwarePaginator => Comment::query()
                ->with('user')
                ->where('commentable_type', $this->commentableType())
                ->where('commentable_id', $post->getKey())
                ->latest()
                ->paginate(15),
            null,
            null,
            ["Post:{$post->uuid}:comments"],
        );

        return new CommentResourceCollection($comments);
    }

    public function store(PostCommentStoreRequest $request, Post $post): JsonResponse
    {
        $this->authorize('view', $post);

        $data = $request->validated();

        $comment = CrudService::for(Comment::class)->create([
            'commentable_type' => $this->commentableType(),
            'commentable_id' => $post->getKey(),
            'body' => $data['body'],
            'parent_id' => $data['parent_id'] ?? null,
        ], ['commentable' => $post]);

        return $this->success(
            new CommentResource($comment->load('user')),
            201,
            'Comment created successfully.',
        );
    }

    /**
     * The value written to and queried from `comments.commentable_type`.
     *
     * This is `Post::class`, not `$post->getMorphClass()` (the registered
     * `stourify_post` alias) — deliberately, not by convention.
     * `App\Services\Crud\CommentCrudService::beforeCreate()` calls static
     * methods directly on the raw `commentable_type` value
     * (`$commentableType::getCachedList(...)`) without first resolving it
     * through `Relation::getMorphedModel()`, the way
     * `ReactionCrudService::assertSupportedType()` does. Passing the alias
     * there throws `Class "stourify_post" not found`. That asymmetry is a
     * pre-existing boilerplate defect (comments have no morph-map
     * indirection, only reactions do), and this module must not patch
     * `saas-boilerplate` to work around it — so this controller writes and
     * reads the FQCN consistently instead.
     */
    private function commentableType(): string
    {
        return Post::class;
    }
}
