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
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Modules\Stourify\Enums\SpotStatus;
use Modules\Stourify\Http\Requests\SpotIndexRequest;
use Modules\Stourify\Http\Requests\SpotNearbyRequest;
use Modules\Stourify\Http\Requests\SpotStoreRequest;
use Modules\Stourify\Http\Requests\SpotUpdateRequest;
use Modules\Stourify\Http\Resources\SpotResource;
use Modules\Stourify\Models\Block;
use Modules\Stourify\Models\City;
use Modules\Stourify\Models\Spot;
use Modules\Stourify\Policies\SpotPolicy;

/**
 * The spots API — the entity every other Stourify surface references.
 *
 * Writes go through `CrudService`, which authorizes via the policy before it
 * touches the database. Reads authorize explicitly here, because CrudService
 * never sees them.
 *
 * Read authorization has two layers and both are load-bearing:
 *
 *   - `viewAny` / `view` gate *access to the endpoint and the record*.
 *   - `visibleTo()` scopes the *query*, so a list can never surface another
 *     explorer's draft. A policy alone cannot do this — it runs per model,
 *     after the rows have already been selected and paginated.
 *
 * @see SpotPolicy
 */
class SpotApiController extends Controller
{
    use ApiResponses;

    /**
     * List spots, newest first by default.
     *
     * `q` searches through Scout; everything else filters in SQL. The cache
     * key carries the querystring *and* the viewer, because two explorers
     * issuing the identical request legitimately see different rows — their
     * own drafts.
     */
    public function index(SpotIndexRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Spot::class);

        $user = $request->user();
        $filters = $request->validated();
        $perPage = (int) ($filters['per_page'] ?? 25);

        $cacheKey = sprintf(
            'api:stourify:spots:index:org:%d:user:%d:%s',
            app(OrganizationContext::class)->id() ?? 0,
            $user->id,
            hash('sha256', json_encode($filters) ?: ''),
        );

        $spots = Spot::getCachedList($cacheKey, function () use ($filters, $perPage, $user): LengthAwarePaginator {
            if (! empty($filters['q'])) {
                return Spot::search($filters['q'])
                    ->query(fn (Builder $query) => $this->applyFilters(
                        $this->visibleTo($query, $user), $filters
                    )->with(['city', 'user', 'media']))
                    ->paginate($perPage);
            }

            $query = $this->applyFilters(
                $this->visibleTo(Spot::query(), $user), $filters
            )->with(['city', 'user', 'media']);

            return $query
                ->orderBy($filters['sort'] ?? 'created_at', $filters['direction'] ?? 'desc')
                ->paginate($perPage);
        });

        return SpotResource::collection($spots);
    }

    /**
     * Spots within `radius` km of a point, nearest first.
     *
     * Only discoverable spots — a proximity search is a discovery surface, so
     * it never returns drafts, not even the caller's own. Someone looking for
     * their unfinished draft is looking at their profile, not the map.
     */
    public function nearby(SpotNearbyRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Spot::class);

        $latitude = (float) $request->validated('lat');
        $longitude = (float) $request->validated('lng');
        $radiusKm = (float) ($request->validated('radius') ?? config('stourify.discovery.default_radius_km', 5.0));
        $perPage = (int) ($request->validated('per_page') ?? 25);

        // Keyed per viewer since blocks landed. This result used to be shared
        // across everyone in the org, which is exactly what made it cheap —
        // but a block filter is per viewer, and a shared entry would have
        // served one explorer's filtered map to the next caller. Correctness
        // over cardinality; `spots:index` has always been keyed this way.
        $cacheKey = sprintf(
            'api:stourify:spots:nearby:org:%d:user:%d:%s',
            app(OrganizationContext::class)->id() ?? 0,
            $request->user()->id,
            hash('sha256', json_encode([$latitude, $longitude, $radiusKm, $perPage, $request->validated('page')]) ?: ''),
        );

        $spots = Spot::getCachedList($cacheKey, fn (): LengthAwarePaginator => Spot::query()
            ->published()
            ->nearby($latitude, $longitude, $radiusKm)
            ->whereNotIn('user_id', Block::hiddenUserIdsFor($request->user()))
            ->with(['city', 'user', 'media'])
            ->paginate($perPage));

        $this->attachDistances($spots, $latitude, $longitude);

        return SpotResource::collection($spots);
    }

    public function show(Spot $spot): JsonResponse
    {
        $this->authorize('view', $spot);

        return $this->success(
            new SpotResource($spot->load(['city', 'user', 'media'])),
        );
    }

    public function store(SpotStoreRequest $request): JsonResponse
    {
        $data = $request->validated();

        $spot = CrudService::for(Spot::class)->create([
            ...$this->resolveRelations($data),
            'user_id' => $request->user()->id,
            'slug' => $this->uniqueSlug($data['title']),
            'status' => $data['status'] ?? SpotStatus::Draft->value,
        ]);

        return $this->success(
            new SpotResource($spot->load(['city', 'user'])),
            201,
            'Spot created successfully.',
        );
    }

    public function update(SpotUpdateRequest $request, Spot $spot): JsonResponse
    {
        $data = $request->validated();

        $spot = CrudService::for(Spot::class)->update($spot, $this->resolveRelations($data));

        return $this->success(
            new SpotResource($spot->load(['city', 'user'])),
            200,
            'Spot updated successfully.',
        );
    }

    public function destroy(Spot $spot): JsonResponse
    {
        CrudService::for(Spot::class)->delete($spot);

        return $this->success(null, 200, 'Spot deleted successfully.');
    }

    /**
     * Restrict a query to what this viewer is allowed to see.
     *
     * Moderators see everything. Everyone else sees discoverable spots plus
     * their own, whatever state those are in — the offline flow creates a
     * draft locally and publishes it on reconnect, so an author must be able
     * to list a spot nobody else can.
     *
     * Blocks apply first and to everyone, moderators included: a block is a
     * personal decision about whose contributions this viewer sees, which is
     * a different question from the draft visibility the rest of this method
     * settles. The spot itself stays discoverable for every other explorer —
     * the filter is per viewer, so blocking a contributor never removes a real
     * place from the map for anyone else.
     *
     * @param  Builder<Spot>  $query
     * @return Builder<Spot>
     */
    private function visibleTo(Builder $query, User $user): Builder
    {
        $query->whereNotIn('user_id', Block::hiddenUserIdsFor($user));

        if ($user->can('viewAnyDraft', Spot::class)) {
            return $query;
        }

        return $query->where(fn (Builder $scoped) => $scoped
            ->whereIn('status', SpotStatus::discoverable())
            ->orWhere('user_id', $user->id));
    }

    /**
     * @param  Builder<Spot>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<Spot>
     */
    private function applyFilters(Builder $query, array $filters): Builder
    {
        return $query
            ->when(! empty($filters['status']), fn (Builder $q) => $q->where('status', $filters['status']))
            ->when(! empty($filters['mine']), fn (Builder $q) => $q->where('user_id', request()->user()->id))
            ->when(! empty($filters['city_uuid']), fn (Builder $q) => $q->whereHas(
                'city', fn (Builder $city) => $city->where('uuid', $filters['city_uuid'])
            ));
    }

    /**
     * Swap public UUIDs for the internal foreign keys the model actually stores.
     *
     * A client never sees or sends an auto-increment id — see
     * system-routing.md, *Route Model Binding*.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function resolveRelations(array $data): array
    {
        if (! array_key_exists('city_uuid', $data)) {
            return $data;
        }

        $cityUuid = $data['city_uuid'];
        unset($data['city_uuid']);

        $data['city_id'] = $cityUuid === null
            ? null
            : City::query()->where('uuid', $cityUuid)->value('id');

        return $data;
    }

    /**
     * Distance is computed here rather than in SQL on purpose.
     *
     * `scopeNearby()` orders by *squared* planar distance specifically to
     * avoid `SQRT`, which many SQLite builds ship without — so the ordering is
     * correct but the value it sorts on is not a distance. Taking the square
     * root of the same quantity in PHP turns it back into kilometres without
     * reintroducing the portability problem the scope exists to dodge.
     *
     * @param  LengthAwarePaginator<int, Spot>  $spots
     */
    private function attachDistances(LengthAwarePaginator $spots, float $latitude, float $longitude): void
    {
        $kmPerDegreeLat = 111.32;
        $kmPerDegreeLng = $kmPerDegreeLat * max(cos(deg2rad($latitude)), 0.01);

        $spots->getCollection()->each(function (Spot $spot) use ($latitude, $longitude, $kmPerDegreeLat, $kmPerDegreeLng): void {
            $dLat = ((float) $spot->latitude - $latitude) * $kmPerDegreeLat;
            $dLng = ((float) $spot->longitude - $longitude) * $kmPerDegreeLng;

            $spot->distance_km = sqrt(($dLat * $dLat) + ($dLng * $dLng));
        });
    }

    /**
     * Slugs are a URL affordance, not an identity — routes bind on UUID. Two
     * spots may legitimately share a title ("Sunset Point"), so collisions are
     * expected rather than exceptional and get a numeric suffix.
     */
    private function uniqueSlug(string $title): string
    {
        $base = Str::slug($title) ?: 'spot';
        $slug = $base;
        $suffix = 1;

        while (Spot::withTrashed()->where('slug', $slug)->exists()) {
            $slug = "{$base}-".++$suffix;
        }

        return $slug;
    }
}
