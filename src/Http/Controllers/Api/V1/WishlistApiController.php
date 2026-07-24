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
use Modules\Stourify\Http\Requests\WishlistIndexRequest;
use Modules\Stourify\Http\Requests\WishlistStoreRequest;
use Modules\Stourify\Http\Requests\WishlistUpdateRequest;
use Modules\Stourify\Http\Resources\WishlistItemResource;
use Modules\Stourify\Models\Spot;
use Modules\Stourify\Models\WishlistItem;
use Modules\Stourify\Policies\WishlistItemPolicy;

/**
 * The wishlist — a user's saved spots.
 *
 * Every read and write is the caller's own. The list is scoped to
 * `user_id = caller` in SQL rather than relying on the policy alone, for the
 * same reason spots and posts are: a policy runs per record, after the query,
 * and cannot stop a list returning someone else's rows.
 *
 * Unsaving hard-deletes. `sto_wishlist_items` carries a unique
 * `(user_id, spot_id)` index, so a soft-delete tombstone would make re-saving
 * the same spot fail forever — the same reasoning as the follow graph.
 *
 * @see WishlistItemPolicy
 */
class WishlistApiController extends Controller
{
    use ApiResponses;

    public function index(WishlistIndexRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', WishlistItem::class);

        $user = $request->user();
        $filters = $request->validated();
        $perPage = (int) ($filters['per_page'] ?? 25);

        $cacheKey = sprintf(
            'api:stourify:wishlist:index:org:%d:user:%d:%s',
            app(OrganizationContext::class)->id() ?? 0,
            $user->id,
            hash('sha256', json_encode($filters) ?: ''),
        );

        $items = WishlistItem::getCachedList($cacheKey, fn (): LengthAwarePaginator => WishlistItem::query()
            ->where('user_id', $user->id)
            ->with(['spot', 'city'])
            ->when(! empty($filters['city_uuid']), fn (Builder $q) => $q->whereHas(
                'city', fn (Builder $city) => $city->where('uuid', $filters['city_uuid'])
            ))
            ->when(array_key_exists('downloaded', $filters) && $filters['downloaded'] !== null,
                fn (Builder $q) => $q->where('is_downloaded_offline', (bool) $filters['downloaded']))
            ->latest()
            ->paginate($perPage));

        return WishlistItemResource::collection($items);
    }

    public function show(WishlistItem $wishlist): JsonResponse
    {
        $this->authorize('view', $wishlist);

        return $this->success(new WishlistItemResource($wishlist->load(['spot', 'city'])));
    }

    public function store(WishlistStoreRequest $request): JsonResponse
    {
        $data = $request->validated();
        $spot = Spot::query()->where('uuid', $data['spot_uuid'])->firstOrFail();

        $item = CrudService::for(WishlistItem::class)->create([
            'user_id' => $request->user()->id,
            'spot_id' => $spot->id,
            // Denormalized off the spot, never taken from the request — see
            // WishlistStoreRequest.
            'city_id' => $spot->city_id,
            'note' => $data['note'] ?? null,
            'is_downloaded_offline' => $data['is_downloaded_offline'] ?? false,
        ]);

        return $this->success(
            new WishlistItemResource($item->load(['spot', 'city'])),
            201,
            'Spot saved.',
        );
    }

    public function update(WishlistUpdateRequest $request, WishlistItem $wishlist): JsonResponse
    {
        $item = CrudService::for(WishlistItem::class)->update($wishlist, $request->validated());

        return $this->success(
            new WishlistItemResource($item->load(['spot', 'city'])),
            200,
            'Wishlist item updated.',
        );
    }

    public function destroy(WishlistItem $wishlist): JsonResponse
    {
        CrudService::for(WishlistItem::class)->delete($wishlist);

        return $this->success(null, 200, 'Spot removed from wishlist.');
    }
}
