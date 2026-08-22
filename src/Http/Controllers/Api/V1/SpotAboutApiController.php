<?php

declare(strict_types=1);

namespace Modules\Stourify\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Crud\CrudService;
use App\Services\OrganizationContext;
use App\Traits\ApiResponses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Stourify\Http\Requests\SpotAboutIndexRequest;
use Modules\Stourify\Http\Requests\SpotAboutStoreRequest;
use Modules\Stourify\Http\Requests\SpotAboutUpdateRequest;
use Modules\Stourify\Http\Resources\SpotAboutResource;
use Modules\Stourify\Models\Spot;
use Modules\Stourify\Models\SpotAbout;
use Modules\Stourify\Policies\SpotAboutPolicy;
use Modules\Stourify\Support\AttachesExplorerProfiles;
use Modules\Stourify\Support\LoadsViewerReactions;

/**
 * About entries — what visitors have written about a spot.
 *
 * Liking one is deliberately NOT here. The platform's `/api/v1/reactions`
 * endpoint already addresses any host by type-alias plus UUID, so an entry
 * became likeable the moment `SpotAbout` picked up `HasReactions` and its alias
 * was registered. Adding a second way to do the same thing would leave two
 * surfaces to keep in agreement and one of them eventually wrong.
 *
 * @see SpotAboutPolicy
 * @see specs/2026-08-22-spot-about-design.md
 */
class SpotAboutApiController extends Controller
{
    use ApiResponses, AttachesExplorerProfiles, LoadsViewerReactions;

    public function index(SpotAboutIndexRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', SpotAbout::class);

        $user = $request->user();
        $filters = $request->validated();
        $perPage = (int) ($filters['per_page'] ?? 25);

        $cacheKey = sprintf(
            'api:stourify:spot-abouts:index:org:%d:user:%d:%s',
            app(OrganizationContext::class)->id() ?? 0,
            $user->id,
            hash('sha256', json_encode($filters) ?: ''),
        );

        $abouts = SpotAbout::getCachedList($cacheKey, fn (): LengthAwarePaginator => $this
            ->withViewerReaction(SpotAbout::query(), $user)
            // `user.media` and `spot` are eager-loaded here rather than resolved
            // inside the resource, so rendering a page costs one query each in
            // total instead of one per row.
            ->with(['spot', 'user.media'])
            ->whereHas('spot', fn ($spot) => $spot->where('uuid', $filters['spot_uuid']))
            ->when(! empty($filters['mine']), fn ($q) => $q->where('user_id', $user->id))
            ->orderBy($filters['sort'] ?? 'likes_count', $filters['direction'] ?? 'desc')
            // The two trailing keys are what make the ordering TOTAL. `likes_count`
            // is not unique, and offset pagination over a non-unique sort key is
            // unstable: two rows that tie can come back in a different order per
            // query, so one appears on page 1 AND page 2 while another appears on
            // neither. `id` is unique, which settles every remaining tie.
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($perPage));

        $this->attachExplorerProfiles(
            $abouts->getCollection()->pluck('user')->filter()->unique('id')->values()
        );

        return SpotAboutResource::collection($abouts);
    }

    public function show(Request $request, SpotAbout $about): JsonResponse
    {
        $this->authorize('view', $about);

        return $this->success(new SpotAboutResource(
            $this->freshForResponse($about, $request->user())
        ));
    }

    public function store(SpotAboutStoreRequest $request): JsonResponse
    {
        $data = $request->validated();

        $about = CrudService::for(SpotAbout::class)->create([
            'spot_id' => Spot::query()->where('uuid', $data['spot_uuid'])->value('id'),
            'user_id' => $request->user()->id,
            'body' => $data['body'],
        ]);

        return $this->success(
            new SpotAboutResource($this->freshForResponse($about, $request->user())),
            201,
            'About entry added successfully.',
        );
    }

    public function update(SpotAboutUpdateRequest $request, SpotAbout $about): JsonResponse
    {
        $about = CrudService::for(SpotAbout::class)->update($about, $request->validated());

        return $this->success(
            new SpotAboutResource($this->freshForResponse($about, $request->user())),
            200,
            'About entry updated successfully.',
        );
    }

    public function destroy(SpotAbout $about): JsonResponse
    {
        CrudService::for(SpotAbout::class)->delete($about);

        return $this->success(null, 200, 'About entry deleted successfully.');
    }

    /**
     * Reload one entry the way the list path loads a page of them — spot,
     * author with the media `author.avatar_url` needs, and the viewer's own
     * reaction — so a write response and a read response carry the same shape.
     * A client that has to special-case "the fields present right after I
     * posted" is a client that will get it wrong.
     */
    private function freshForResponse(SpotAbout $about, User $viewer): SpotAbout
    {
        $about = $this->withViewerReaction(SpotAbout::query()->with(['spot', 'user.media']), $viewer)
            ->whereKey($about->getKey())
            ->firstOrFail();

        $this->attachExplorerProfiles(collect([$about->user])->filter()->values());

        return $about;
    }
}
