<?php

declare(strict_types=1);

namespace Modules\Stourify\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Crud\CrudService;
use App\Traits\ApiResponses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Stourify\Http\Requests\BlockStoreRequest;
use Modules\Stourify\Http\Resources\BlockResource;
use Modules\Stourify\Models\Block;
use Modules\Stourify\Models\Follow;
use Modules\Stourify\Models\Post;
use Modules\Stourify\Models\Spot;
use Modules\Stourify\Policies\BlockPolicy;
use Modules\Stourify\Support\AttachesExplorerProfiles;

/**
 * Blocking — the safety valve every UGC surface needs.
 *
 * Three operations and no more: list your own blocks, add one, lift one. The
 * shape is deliberately narrower than the follow graph's, because a block has
 * only one legitimate reader — the person who made it.
 *
 * **There is no way to ask who has blocked you, and that is a feature.** The
 * index is constrained to the caller's own rows, `BlockResource` never renders
 * a blocker, and `BlockPolicy` gives the blocked party no ability at all. This
 * mirrors the rule reports already follow: a report is anonymous to the
 * reported party, and a block is invisible to the blocked one.
 *
 * @see BlockPolicy
 */
class BlockApiController extends Controller
{
    use ApiResponses, AttachesExplorerProfiles;

    /**
     * The caller's own blocks — the "Blocked accounts" list.
     *
     * Uncached, unlike the follow lists. This is a settings-screen read that
     * must reflect a block or unblock the instant it happens, and it is short
     * by nature; the cache would buy a page nobody reads twice.
     */
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Block::class);

        $blocks = Block::query()
            ->where('blocker_id', auth()->id())
            ->with(['blocked'])
            ->latest()
            ->paginate(25);

        $this->attachExplorerProfiles(
            $blocks->getCollection()->pluck('blocked')->filter()->values()
        );

        return BlockResource::collection($blocks);
    }

    /**
     * Block someone.
     *
     * Idempotent: a second call returns the existing row with 200 rather than
     * tripping the unique index, so a retrying client — or an impatient
     * double-tap — is safe. Same contract the report endpoint offers.
     */
    public function store(BlockStoreRequest $request): JsonResponse
    {
        $blocker = $request->user();
        $blocked = User::query()->where('uuid', $request->validated('user_uuid'))->firstOrFail();

        $existing = Block::query()
            ->where('blocker_id', $blocker->id)
            ->where('blocked_id', $blocked->id)
            ->first();

        if ($existing !== null) {
            return $this->success(
                new BlockResource($this->withParties($existing)),
                200,
                'You have already blocked this explorer.',
            );
        }

        $block = CrudService::for(Block::class)->create([
            'blocker_id' => $blocker->id,
            'blocked_id' => $blocked->id,
        ]);

        $this->severFollowEdges($blocker, $blocked);
        $this->forgetContentLists();

        return $this->success(
            new BlockResource($this->withParties($block)),
            201,
            'Blocked.',
        );
    }

    /**
     * Lift a block. The blocker's alone — see BlockPolicy::delete().
     *
     * Follow edges are **not** restored. They were hard-deleted, not
     * suspended, and re-creating them would silently re-follow on someone's
     * behalf. Whoever wants the relationship back asks for it again.
     */
    public function destroy(Block $block): JsonResponse
    {
        $this->authorize('delete', $block);

        CrudService::for(Block::class)->delete($block);

        $this->forgetContentLists();

        return $this->success(null, 200, 'Block removed.');
    }

    /**
     * Delete the follow edges between these two, in both directions and in
     * whatever state they were in.
     *
     * Both directions, because a block that left the blocked party still
     * following would leave them inside the blocker's followers-only audience.
     * Pending as well as active, because an outstanding request that survived
     * a block would surface on the blocker's requests screen as if nothing had
     * happened.
     *
     * Through `CrudService` rather than a mass `delete()`, so each removal
     * fires the model events the offline-sync tombstones depend on — a mobile
     * client that pulled the edge yesterday has to be told it is gone.
     */
    private function severFollowEdges(User $blocker, User $blocked): void
    {
        Follow::query()
            ->where(fn ($query) => $query
                ->where(fn ($forward) => $forward
                    ->where('follower_id', $blocker->id)
                    ->where('followee_id', $blocked->id))
                ->orWhere(fn ($reverse) => $reverse
                    ->where('follower_id', $blocked->id)
                    ->where('followee_id', $blocker->id)))
            ->get()
            ->each(fn (Follow $follow) => CrudService::for(Follow::class)->delete($follow));
    }

    /**
     * Drop the cached post and spot indexes.
     *
     * Both are keyed per viewer, so a stale entry cannot leak one person's
     * audience to another — but it can leave the blocker's own list showing
     * the person they just blocked until the TTL runs out, which reads as the
     * block having failed.
     *
     * Done here rather than through `$invalidatesCachesOf`: that hook walks a
     * *relation* to a parent model and clears its cache, and a block has no
     * relation to a post or a spot to walk.
     */
    private function forgetContentLists(): void
    {
        Post::clearListCache();
        Spot::clearListCache();
    }

    private function withParties(Block $block): Block
    {
        $block->load(['blocked']);

        $this->attachExplorerProfiles(collect([$block->blocked])->filter()->values());

        return $block;
    }
}
