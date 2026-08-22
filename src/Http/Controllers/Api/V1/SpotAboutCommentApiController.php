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
use Modules\Stourify\Http\Requests\SpotAboutCommentStoreRequest;
use Modules\Stourify\Models\SpotAbout;

/**
 * The conversation in the margin of one About entry.
 *
 * An About entry is a note pinned to a spot's corkboard. This is how somebody
 * replies to that note instead of pinning a second one beside it.
 *
 * The comments themselves are the platform's generic, polymorphic
 * `App\Models\Comment` — one table that can hang off any model. This controller
 * is a module-owned **adapter**: it exists only because the platform's own
 * comment surface is addressed by a numeric database id
 * (`/api/v1/comments?commentable_type=…&commentable_id=417`) and no Stourify
 * response carries one. Every resource in this module is addressed by UUID, so
 * this controller takes the UUID the client has and hands the platform the id
 * underneath. `PostCommentApiController` is the same adapter for posts, and the
 * two are deliberately shaped alike.
 *
 * Deleting a comment is **not** here. The platform's
 * `DELETE /api/v1/comments/{uuid}` is already addressed by UUID, so it needs no
 * translation and the app already calls it.
 *
 * @see SpotAboutCommentStoreRequest for the authorization pair both endpoints rely on
 */
class SpotAboutCommentApiController extends Controller
{
    use ApiResponses;

    public function index(Request $request, SpotAbout $about): CommentResourceCollection
    {
        // Two independent locks, in the order a reader would expect: may you see
        // the entry, and may you read comments on an entry. The store path makes
        // the same pair of checks inside its FormRequest, where they land ahead
        // of validation.
        $this->authorize('view', $about);
        $this->authorize('viewAny', [Comment::class, $about]);

        $page = (int) $request->input('page', 1);
        $cacheKey = sprintf('api:stourify:spot-abouts:%d:comments:index:page:%d', $about->id, $page);

        $comments = Comment::getCachedList(
            $cacheKey,
            fn (): LengthAwarePaginator => Comment::query()
                ->with('user')
                ->where('commentable_type', $this->commentableType())
                ->where('commentable_id', $about->getKey())
                ->latest()
                ->paginate(15),
            null,
            null,
            ["SpotAbout:{$about->uuid}:comments"],
        );

        return new CommentResourceCollection($comments);
    }

    public function store(SpotAboutCommentStoreRequest $request, SpotAbout $about): JsonResponse
    {
        $data = $request->validated();

        $comment = CrudService::for(Comment::class)->create([
            'commentable_type' => $this->commentableType(),
            'commentable_id' => $about->getKey(),
            'body' => $data['body'],
            'parent_id' => $data['parent_id'] ?? null,
        ], ['commentable' => $about]);

        return $this->success(
            new CommentResource($comment->load('user')),
            201,
            'Comment created successfully.',
        );
    }

    /**
     * The value written to and queried from `comments.commentable_type`.
     *
     * This is the full class name, not `$about->getMorphClass()` (the registered
     * `stourify_spot_about` alias) — deliberately, not by convention.
     * `App\Services\Crud\CommentCrudService::beforeCreate()` calls static methods
     * directly on the raw `commentable_type` value
     * (`$commentableType::getCachedList(...)`) without first resolving it through
     * `Relation::getMorphedModel()`, the way `ReactionCrudService` does. Passing
     * the alias there throws `Class "stourify_spot_about" not found`. That
     * asymmetry is a pre-existing boilerplate defect — STOURIFY-12 — and a module
     * must not patch `saas-boilerplate` to work around it, so this controller
     * writes and reads the class name consistently instead.
     *
     * `SpotAbout::comments()` is overridden to look for the same spelling. The
     * two have to agree, and this method is why.
     */
    private function commentableType(): string
    {
        return SpotAbout::class;
    }
}
