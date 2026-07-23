<?php

declare(strict_types=1);

namespace Modules\Stourify\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Crud\CrudService;
use App\Services\OrganizationContext;
use App\Traits\ApiResponses;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Stourify\Http\Requests\ReviewIndexRequest;
use Modules\Stourify\Http\Requests\ReviewStoreRequest;
use Modules\Stourify\Http\Requests\ReviewUpdateRequest;
use Modules\Stourify\Http\Resources\ReviewResource;
use Modules\Stourify\Models\Review;
use Modules\Stourify\Models\Spot;
use Modules\Stourify\Observers\ReviewObserver;

/**
 * Reviews — a rating and write-up, one per explorer per spot.
 *
 * Unlike spots, reviews have no draft state, so the list needs no
 * visibility scoping: every review is public from the moment it is written.
 * That is also why there is no `visibleTo()` here.
 *
 * The spot's `rating_average` and `reviews_count` are maintained by
 * ReviewObserver, not by this controller — the same numbers must hold for
 * writes arriving through sync push, seeders and factories.
 *
 * @see ReviewObserver
 */
class ReviewApiController extends Controller
{
    use ApiResponses;

    public function index(ReviewIndexRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Review::class);

        $filters = $request->validated();
        $perPage = (int) ($filters['per_page'] ?? 25);
        $userId = $request->user()->id;

        $cacheKey = sprintf(
            'api:stourify:reviews:index:org:%d:user:%d:%s',
            app(OrganizationContext::class)->id() ?? 0,
            $userId,
            hash('sha256', json_encode($filters) ?: ''),
        );

        $reviews = Review::getCachedList($cacheKey, fn (): LengthAwarePaginator => Review::query()
            ->with(['spot', 'user'])
            ->when(! empty($filters['spot_uuid']), fn (Builder $q) => $q->whereHas(
                'spot', fn (Builder $spot) => $spot->where('uuid', $filters['spot_uuid'])
            ))
            ->when(! empty($filters['mine']), fn (Builder $q) => $q->where('user_id', $userId))
            ->when(! empty($filters['rating']), fn (Builder $q) => $q->where('rating', $filters['rating']))
            ->orderBy($filters['sort'] ?? 'created_at', $filters['direction'] ?? 'desc')
            ->paginate($perPage));

        return ReviewResource::collection($reviews);
    }

    public function show(Review $review): JsonResponse
    {
        $this->authorize('view', $review);

        return $this->success(new ReviewResource($review->load(['spot', 'user'])));
    }

    public function store(ReviewStoreRequest $request): JsonResponse
    {
        $data = $request->validated();

        $review = CrudService::for(Review::class)->create([
            'spot_id' => Spot::query()->where('uuid', $data['spot_uuid'])->value('id'),
            'user_id' => $request->user()->id,
            'rating' => $data['rating'],
            'body' => $data['body'] ?? null,
        ]);

        return $this->success(
            new ReviewResource($review->load(['spot', 'user'])),
            201,
            'Review created successfully.',
        );
    }

    public function update(ReviewUpdateRequest $request, Review $review): JsonResponse
    {
        $review = CrudService::for(Review::class)->update($review, $request->validated());

        return $this->success(
            new ReviewResource($review->load(['spot', 'user'])),
            200,
            'Review updated successfully.',
        );
    }

    public function destroy(Review $review): JsonResponse
    {
        CrudService::for(Review::class)->delete($review);

        return $this->success(null, 200, 'Review deleted successfully.');
    }
}
