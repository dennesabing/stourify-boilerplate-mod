<?php

namespace Modules\Stourify\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Crud\CrudService;
use App\Traits\ApiResponses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Stourify\Http\Requests\SpotStoreRequest;
use Modules\Stourify\Http\Requests\SpotUpdateRequest;
use Modules\Stourify\Http\Resources\SpotResource;
use Modules\Stourify\Models\Spot;
use Modules\Stourify\Models\SpotCategory;

class SpotController extends Controller
{
    use ApiResponses;

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Spot::class);

        $page = $request->integer('page', 1);
        $hashVars = hash('sha256', json_encode($request->query()));
        $cacheKey = "api:spots:index:{$hashVars}:page:{$page}";

        $spots = Spot::getCachedList($cacheKey, function () use ($request) {
            $query = Spot::with(['category', 'creator'])
                ->where('status', 'active')
                ->latest();

            if ($categoryUuid = $request->input('category')) {
                $query->whereHas('category', fn ($q) => $q->where('uuid', $categoryUuid));
            }

            return $query->paginate(20)->withQueryString();
        });

        return $this->success(SpotResource::collection($spots));
    }

    public function store(SpotStoreRequest $request): JsonResponse
    {
        Gate::authorize('create', Spot::class);

        $data = $request->validated();

        if (isset($data['category_id'])) {
            $category = SpotCategory::getCachedList(
                "spot_category:{$data['category_id']}",
                fn () => SpotCategory::where('uuid', $data['category_id'])->firstOrFail(),
            );
            $data['category_id'] = $category->id;
        }

        $data['created_by'] = $request->user()->id;

        $spot = CrudService::for(Spot::class)->create($data);

        return $this->success(new SpotResource($spot->load(['category', 'creator'])), 201, 'Spot created.');
    }

    public function show(Spot $spot): JsonResponse
    {
        Gate::authorize('view', $spot);

        $spot->load(['category', 'creator', 'tags']);

        return $this->success(new SpotResource($spot));
    }

    public function update(SpotUpdateRequest $request, Spot $spot): JsonResponse
    {
        Gate::authorize('update', $spot);

        $data = $request->validated();

        if (isset($data['category_id'])) {
            $category = SpotCategory::getCachedList(
                "spot_category:{$data['category_id']}",
                fn () => SpotCategory::where('uuid', $data['category_id'])->firstOrFail(),
            );
            $data['category_id'] = $category->id;
        }

        $spot = CrudService::for(Spot::class)->update($spot, $data);

        return $this->success(new SpotResource($spot->load(['category', 'creator'])));
    }

    public function posts(Spot $spot): JsonResponse
    {
        Gate::authorize('view', $spot);

        $cacheKey = "api:spots:{$spot->uuid}:posts";

        $posts = Spot::getCachedList($cacheKey, function () use ($spot) {
            return \Modules\Social\Models\Post::with(['user', 'media'])
                ->where('spot_id', $spot->id)
                ->where('visibility', 'public')
                ->latest()
                ->paginate(20);
        });

        return $this->success(\Modules\Social\Http\Resources\PostResource::collection($posts));
    }

    public function media(Spot $spot): JsonResponse
    {
        Gate::authorize('view', $spot);

        $cacheKey = "api:spots:{$spot->uuid}:media";

        $media = Spot::getCachedList($cacheKey, function () use ($spot) {
            return \Modules\Social\Models\PostMedia::whereHas('post', function ($q) use ($spot) {
                $q->where('spot_id', $spot->id)->where('visibility', 'public');
            })->latest()->paginate(40);
        });

        return $this->success(\Modules\Social\Http\Resources\PostMediaResource::collection($media));
    }
}
