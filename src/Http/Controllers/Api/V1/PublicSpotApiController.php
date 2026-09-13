<?php

declare(strict_types=1);

namespace Modules\Stourify\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Traits\ApiResponses;
use Illuminate\Http\JsonResponse;
use Modules\Stourify\Http\Requests\PublicSpotShowRequest;
use Modules\Stourify\Http\Resources\PublicSpotResource;
use Modules\Stourify\Models\Spot;

/**
 * A spot's public page — readable with no account (STOURIFY-301).
 *
 * The one endpoint in this module outside the `auth:sanctum` group. Its whole
 * gate is the visibility rule below, and every miss is a 404 so a stranger can
 * never tell "private" from "does not exist".
 */
class PublicSpotApiController extends Controller
{
    use ApiResponses;

    public function show(PublicSpotShowRequest $request, string $uuid): JsonResponse
    {
        /*
         * A key of its own, not `getCached()`.
         *
         * `getCached()` keys every model on `Spot:{uuid}` whatever the callback
         * returns, so anything else in the app that caches a spot by uuid would
         * share this entry -- and a plain cached spot under that key would hand
         * this endpoint a draft. A dedicated key means only this query ever
         * writes it. It still carries the `Spot:list` tag, which is what makes
         * it fall out the moment the spot is saved or its contributor changes
         * their location or privacy setting (see ExplorerProfile::booted()).
         */
        $spot = Spot::getCachedList(
            "api:stourify:public-spot:{$uuid}",
            fn (): ?Spot => Spot::query()
                // There is no organization context on an anonymous request, so
                // the tenant is named explicitly: Stourify's one public
                // organization, and never anybody's private workspace.
                ->whereIn('organization_id', Organization::query()
                    ->select('id')
                    ->where('slug', config('stourify.public_organization.slug')))
                ->where('uuid', $uuid)
                ->publiclyShareable()
                ->with(['city', 'media'])
                ->first(),
        );

        // Checked again on the way out, on the model itself. The query above is
        // the rule; this is the belt that still holds if a cache ever returns
        // something the query would not have.
        if (! $spot instanceof Spot || ! $spot->isPubliclyShareable()) {
            abort(404);
        }

        return $this->success(new PublicSpotResource($spot));
    }
}
