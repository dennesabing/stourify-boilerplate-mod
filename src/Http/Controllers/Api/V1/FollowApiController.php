<?php

declare(strict_types=1);

namespace Modules\Stourify\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Crud\CrudService;
use App\Services\OrganizationContext;
use App\Traits\ApiResponses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Stourify\Enums\FollowStatus;
use Modules\Stourify\Http\Requests\FollowIndexRequest;
use Modules\Stourify\Http\Requests\FollowStoreRequest;
use Modules\Stourify\Http\Resources\FollowResource;
use Modules\Stourify\Models\Block;
use Modules\Stourify\Models\Follow;
use Modules\Stourify\Policies\FollowPolicy;
use Modules\Stourify\Support\AttachesExplorerProfiles;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The follow graph — a directed edge from follower to followee.
 *
 * Following a public account takes effect immediately. Following a private one
 * creates a request the followee must accept, and **the client does not get to
 * say which**: the status is derived from the target's privacy setting, never
 * from the payload. See FollowStoreRequest.
 *
 * Unfollowing hard-deletes the edge rather than soft-deleting it. `sto_follows`
 * carries a unique index on `(follower_id, followee_id)`, so a tombstone would
 * make re-following the same person fail forever.
 *
 * @see FollowPolicy
 */
class FollowApiController extends Controller
{
    use ApiResponses, AttachesExplorerProfiles;

    /**
     * List one side of the graph.
     *
     * A private account's follower and following lists are visible only to the
     * account itself and to its accepted followers. Without that, `is_private`
     * would hide someone's posts while their social graph stayed an open book.
     */
    public function index(FollowIndexRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Follow::class);

        $filters = $request->validated();
        $viewer = $request->user();
        $subject = $this->resolveSubject($filters['user_uuid'] ?? null, $viewer);
        $direction = $filters['direction'] ?? 'followers';
        $perPage = (int) ($filters['per_page'] ?? 25);

        $this->assertMaySeeGraphOf($subject, $viewer);

        // Reading "followers" means edges pointing AT the subject; "following"
        // means edges pointing away from them.
        $subjectColumn = $direction === 'followers' ? 'followee_id' : 'follower_id';
        $counterpart = $direction === 'followers' ? 'follower' : 'followee';

        $cacheKey = sprintf(
            'api:stourify:follows:index:org:%d:subject:%d:%s',
            app(OrganizationContext::class)->id() ?? 0,
            $subject->id,
            hash('sha256', json_encode([$direction, $filters['status'] ?? null, $perPage, $filters['page'] ?? 1]) ?: ''),
        );

        $follows = Follow::getCachedList($cacheKey, fn (): LengthAwarePaginator => Follow::query()
            ->where($subjectColumn, $subject->id)
            // Pending requests are not part of anyone's public follower list —
            // they surface only on the owner's requests screen.
            ->where('status', $filters['status'] ?? FollowStatus::Active->value)
            ->with([$counterpart])
            ->latest()
            ->paginate($perPage));

        $this->attachExplorerProfiles(
            $follows->getCollection()->pluck($counterpart)->filter()->values()
        );

        return FollowResource::collection($follows);
    }

    /**
     * Follow requests awaiting the caller's decision.
     *
     * Its own route rather than an index filter, because "requests addressed to
     * me" is a different question from "who follows this account" — it is
     * always about the caller and is never visible to anyone else.
     *
     * Deliberately uncached, unlike index(): this is a notifications-style
     * surface where a request must disappear the instant it is accepted or
     * rejected. Immediacy beats a cache here, the same trade the feed makes —
     * the follower/following lists cache because they are read far more often
     * than they change, which is the opposite profile.
     */
    public function requests(FollowIndexRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Follow::class);

        $user = $request->user();
        $perPage = (int) ($request->validated('per_page') ?? 25);

        $follows = Follow::query()
            ->where('followee_id', $user->id)
            ->where('status', FollowStatus::Pending->value)
            ->with(['follower'])
            ->latest()
            ->paginate($perPage);

        $this->attachExplorerProfiles($follows->getCollection()->pluck('follower')->filter()->values());

        return FollowResource::collection($follows);
    }

    public function show(Follow $follow): JsonResponse
    {
        $this->authorize('view', $follow);

        return $this->success(new FollowResource($this->loadParties($follow)));
    }

    public function store(FollowStoreRequest $request): JsonResponse
    {
        $targetId = (int) User::query()->where('uuid', $request->validated('user_uuid'))->value('id');

        // A block forbids a new edge from either side. Refused here rather
        // than in FollowStoreRequest so the response is a flat 403 with the
        // same neutral wording the profile header uses — a 422 naming the
        // field would tell the blocked party that this particular person is
        // the reason, which they are never told.
        if (Block::isHiddenFrom($request->user(), $targetId)) {
            throw new AccessDeniedHttpException('This explorer is not available.');
        }

        // Derived from the target's privacy setting, never from the payload.
        $isPrivate = $this->isPrivateAccount($targetId);

        $follow = CrudService::for(Follow::class)->create([
            'follower_id' => $request->user()->id,
            'followee_id' => $targetId,
            'status' => $isPrivate ? FollowStatus::Pending->value : FollowStatus::Active->value,
            'accepted_at' => $isPrivate ? null : now(),
        ]);

        return $this->success(
            new FollowResource($this->loadParties($follow)),
            201,
            $isPrivate ? 'Follow request sent.' : 'Now following.',
        );
    }

    /**
     * Accept a pending request. Followee only — see FollowPolicy::accept().
     *
     * Idempotent: accepting an already-active follow returns it unchanged
     * rather than erroring, so a retrying offline client is safe.
     */
    public function accept(Follow $follow): JsonResponse
    {
        $this->authorize('accept', $follow);

        if ($follow->status === FollowStatus::Pending) {
            $follow = CrudService::for(Follow::class)->update($follow, [
                'status' => FollowStatus::Active->value,
                'accepted_at' => now(),
            ]);
        }

        return $this->success(
            new FollowResource($this->loadParties($follow)),
            200,
            'Follow request accepted.',
        );
    }

    /**
     * Ends the relationship from either side — the follower unfollowing, the
     * followee rejecting a request or removing an existing follower. They are
     * the same operation on the same row, so they are one endpoint.
     */
    public function destroy(Follow $follow): JsonResponse
    {
        CrudService::for(Follow::class)->delete($follow);

        return $this->success(null, 200, 'Follow removed.');
    }

    /**
     * Whose graph is being read — a named explorer, or the caller by default.
     */
    private function resolveSubject(?string $userUuid, User $viewer): User
    {
        if ($userUuid === null) {
            return $viewer;
        }

        return User::query()->where('uuid', $userUuid)->firstOrFail();
    }

    /**
     * A private account's graph is for the account and its accepted followers.
     */
    private function assertMaySeeGraphOf(User $subject, User $viewer): void
    {
        if ($subject->id === $viewer->id || ! $this->isPrivateAccount($subject->id)) {
            return;
        }

        $isAcceptedFollower = Follow::query()
            ->where('follower_id', $viewer->id)
            ->where('followee_id', $subject->id)
            ->where('status', FollowStatus::Active->value)
            ->exists();

        if (! $isAcceptedFollower) {
            throw new AccessDeniedHttpException('This explorer keeps their follow graph private.');
        }
    }

    private function loadParties(Follow $follow): Follow
    {
        $follow->load(['follower', 'followee']);

        $this->attachExplorerProfiles(
            collect([$follow->follower, $follow->followee])->filter()->values()
        );

        return $follow;
    }
}
