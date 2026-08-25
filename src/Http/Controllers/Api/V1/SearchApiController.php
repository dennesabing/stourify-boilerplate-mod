<?php

declare(strict_types=1);

namespace Modules\Stourify\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Tag;
use App\Traits\ApiResponses;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Modules\Stourify\Http\Requests\SearchRequest;
use Modules\Stourify\Http\Resources\CityResource;
use Modules\Stourify\Http\Resources\PersonResource;
use Modules\Stourify\Http\Resources\SpotResource;
use Modules\Stourify\Http\Resources\TagResource;
use Modules\Stourify\Models\Block;
use Modules\Stourify\Models\City;
use Modules\Stourify\Models\ExplorerProfile;
use Modules\Stourify\Models\Spot;
use Modules\Stourify\Support\Hashtags\HashtagParser;

/**
 * Discovery search across spots, cities, people and hashtags.
 *
 * This is the module's own search, at `/discover/search`, deliberately not the
 * boilerplate's generic `/api/v1/search`. Two reasons: the generic endpoint
 * applies no domain filtering, so it would surface draft spots, and it returns
 * a flat, type-agnostic shape rather than the tabbed spots / cities / people
 * result the Discover screen needs.
 *
 * `type` returns one paginated result set for its tab; omitting it returns a
 * small preview of all three for the "All" tab. Search runs through Scout,
 * org-scoped by `OrganizationSearchable`.
 *
 * **Which Scout engine, and why it is worth knowing.** This said "Meilisearch in
 * production, the collection driver in tests" until STOURIFY-204. Production has
 * never run Meilisearch — no such process, nothing on its port, no setting in
 * the environment — so the sentence described an arrangement that was never set
 * up, which is how somebody loses an afternoon debugging an index that is not
 * there. Every tier runs the **collection driver**, and the operator decided on
 * 2026-08-26 to keep it for now.
 *
 * That decision has a shape worth understanding before changing anything here.
 * A real search engine keeps its own index, the way a book's index maps a word
 * to its pages, so its cost barely moves as the book grows. The collection
 * driver has no index: it reads the rows and checks each one, the way you would
 * find a word by reading every page. On today's catalogue that is instant. It
 * slows in direct proportion to how much content exists, and it cannot do the
 * things an index makes cheap — tolerating a typo, matching "beaches" to
 * "beach", ranking by relevance.
 *
 * So this is a deliberate deferral, not an oversight, and STOURIFY-204 carries
 * the reasoning and the conditions for revisiting it.
 *
 * Only *discoverable* spots are returned — a search is a discovery surface, so
 * a draft never appears, the same rule the map and the nearby list follow.
 * People and cities carry no such filter: a profile header is public even for
 * a private account (you can find someone to request to follow them), and
 * cities are public reference data.
 */
class SearchApiController extends Controller
{
    use ApiResponses;

    /**
     * How many hits each section shows in the combined "All" preview.
     */
    private const PREVIEW_LIMIT = 5;

    public function index(SearchRequest $request): JsonResponse|Responsable
    {
        // Gated like the other discovery surfaces (nearby): searching is a
        // spot-first activity, and every explorer holds spots.view. People and
        // cities ride along on the same authenticated, gated request.
        $this->authorize('viewAny', Spot::class);

        $query = $request->validated('q');
        $type = $request->validated('type');
        $perPage = (int) ($request->validated('per_page') ?? 25);

        return match ($type) {
            'spots' => SpotResource::collection($this->spots($query)->paginate($perPage)),
            'cities' => CityResource::collection($this->cities($query)->paginate($perPage)),
            'people' => PersonResource::collection($this->people($query)->paginate($perPage)),
            'tags' => TagResource::collection($this->tags($query)->paginate($perPage)),
            default => $this->preview($query),
        };
    }

    /**
     * The "All" tab — a capped preview of each section in one response.
     */
    private function preview(string $query): JsonResponse
    {
        return $this->success([
            'spots' => SpotResource::collection($this->spots($query)->take(self::PREVIEW_LIMIT)->get()),
            'cities' => CityResource::collection($this->cities($query)->take(self::PREVIEW_LIMIT)->get()),
            'people' => PersonResource::collection($this->people($query)->take(self::PREVIEW_LIMIT)->get()),
            'tags' => TagResource::collection($this->tags($query)->take(self::PREVIEW_LIMIT)->get()),
        ]);
    }

    /**
     * Hashtag hits — the words themselves, not the things carrying them.
     *
     * This is the whole of STOURIFY-25, and it needed no new searchable
     * projection: `Tag` already uses `OrganizationSearchable` and declares its
     * own `searchableAs()`, so it goes through Scout exactly like the three
     * sections above it.
     *
     * **The `type` constraint is the part that matters.** The `tags` table is
     * shared with the admin panel's own tag manager, whose labels are internal
     * and have a different audience. Without this line, searching would show an
     * explorer words no explorer ever typed and no tag page could explain.
     *
     * @return \Laravel\Scout\Builder<Tag>
     */
    private function tags(string $query): \Laravel\Scout\Builder
    {
        return Tag::search($query)
            ->query(fn (Builder $builder) => $builder
                ->where('type', HashtagParser::TAG_TYPE)
                // ...and not one an administrator has taken down. Search is the
                // surface that turned an offensive word from something you had
                // to stumble across into something you could go and look for,
                // so it is the one this switch most has to reach (STOURIFY-174).
                ->notSuppressed());
    }

    /**
     * Spot hits, constrained to discoverable spots and eager-loaded for the card.
     *
     * @return \Laravel\Scout\Builder<Spot>
     */
    private function spots(string $query): \Laravel\Scout\Builder
    {
        return Spot::search($query)
            ->query(fn (Builder $builder) => $builder
                ->published()
                ->whereNotIn('user_id', $this->hidden())
                ->with(['city', 'user', 'tags']));
    }

    /**
     * @return \Laravel\Scout\Builder<City>
     */
    private function cities(string $query): \Laravel\Scout\Builder
    {
        return City::search($query);
    }

    /**
     * People hits, with the user eager-loaded so the card can show the name.
     *
     * @return \Laravel\Scout\Builder<ExplorerProfile>
     */
    private function people(string $query): \Laravel\Scout\Builder
    {
        return ExplorerProfile::search($query)
            ->query(fn (Builder $builder) => $builder
                ->whereNotIn('user_id', $this->hidden())
                ->with(['user']));
    }

    /**
     * The users this searcher must not see, and who must not see them.
     *
     * Resolved once per request and handed to both sections. Search is the
     * surface where a missed block is most visible — a blocked account that
     * still turns up under its own handle makes the block look broken, and
     * finding someone is the first step to reaching them again.
     *
     * @return list<int>
     */
    private function hidden(): array
    {
        return Block::hiddenUserIdsFor(request()->user());
    }
}
