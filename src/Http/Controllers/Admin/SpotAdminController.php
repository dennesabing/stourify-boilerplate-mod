<?php

namespace Modules\Stourify\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Crud\CrudService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Social\Models\Post;
use Modules\Stourify\Models\Spot;
use Modules\Stourify\Http\Requests\Admin\MergeSpotRequest;
use Modules\Stourify\Http\Requests\Admin\SpotAdminRequest;

class SpotAdminController extends Controller
{
    public function index(Request $request): Response
    {
        $spots = Spot::query()
            ->when($request->search, fn ($q, $s) => $q->where('name', 'like', "%{$s}%"))
            ->when(
                $request->status && $request->status !== 'all',
                fn ($q) => $q->where('status', $request->status)
            )
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Admin/Spots/Index', [
            'spots'   => $spots,
            'filters' => $request->only(['search', 'status']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Spots/Create');
    }

    public function store(SpotAdminRequest $request): RedirectResponse
    {
        $spot = CrudService::for(Spot::class)->create(array_merge($request->validated(), [
            'created_by' => $request->user()->id,
        ]));

        return redirect()->route('stourify.admin.spots.show', $spot)
            ->with('success', 'Spot created.');
    }

    public function show(Spot $spot): Response
    {
        return Inertia::render('Admin/Spots/Show', [
            'spot' => $spot->load('creator'),
        ]);
    }

    public function update(SpotAdminRequest $request, Spot $spot): RedirectResponse
    {
        CrudService::for(Spot::class)->update($spot, $request->validated());

        return redirect()->route('stourify.admin.spots.show', $spot)
            ->with('success', 'Spot updated.');
    }

    public function destroy(Spot $spot): RedirectResponse
    {
        $spot->delete();

        return redirect()->route('stourify.admin.spots.index')
            ->with('success', 'Spot deleted.');
    }

    public function merge(MergeSpotRequest $request, Spot $spot): RedirectResponse
    {
        $target = Spot::where('uuid', $request->target_spot_uuid)->firstOrFail();

        Post::withoutGlobalScopes()
            ->where('spot_id', $spot->id)
            ->update(['spot_id' => $target->id]);

        $spot->clearCache();
        $target->clearCache();
        $spot->delete();

        return redirect()->route('stourify.admin.spots.show', $target)
            ->with('success', "Merged into {$target->name}.");
    }
}
