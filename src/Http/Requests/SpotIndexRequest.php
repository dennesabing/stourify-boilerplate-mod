<?php

declare(strict_types=1);

namespace Modules\Stourify\Http\Requests;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Modules\Stourify\Enums\SpotStatus;

/**
 * Validates the list query.
 *
 * Reads are not writes, so `CrudService` never sees them — the controller
 * calls `$this->authorize('viewAny', Spot::class)` itself before running the
 * query. Validating the query string here still matters: `per_page` is capped
 * so a client cannot ask for the whole table, and `sort` is whitelisted so it
 * cannot become an injection point or an unindexed full scan.
 */
class SpotIndexRequest extends BaseFormRequest
{
    /**
     * Columns a client may sort by. Each is either indexed or a denormalized
     * aggregate we already maintain, so none of these turns into a file sort
     * over the whole table.
     *
     * @var list<string>
     */
    public const SORTABLE = [
        'created_at',
        'updated_at',
        'title',
        'rating_average',
        'reviews_count',
        'saves_count',
    ];

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::enum(SpotStatus::class)],
            'city_uuid' => ['nullable', 'uuid'],
            'mine' => ['nullable', 'boolean'],

            // One category's spots, for the Discover filter rail (STOURIFY-193).
            //
            // This rule is the whole reason the rail could not be wired up
            // before. Laravel drops a query parameter nothing has validated,
            // silently and without complaint -- so a filter built against a
            // rule that does not exist returns the UNFILTERED list every time,
            // and looks exactly like a filter that works. The app's own comment
            // on those chips said as much and left them decorative rather than
            // shipping something that lies.
            //
            // Not constrained to a fixed list on purpose: `SpotStoreRequest`
            // takes free strings, so the server has no vocabulary to check
            // against and inventing one here would reject categories the same
            // server accepted when the spot was created. The app offers a
            // shortlist; this accepts what that shortlist produces.
            'category' => ['nullable', 'string', 'max:64'],

            // One hashtag's spots (STOURIFY-172). A slug, capped at the
            // parser's own length. See PostIndexRequest for why there is no
            // pattern rule beyond that.
            'tag' => ['nullable', 'string', 'max:64'],

            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'sort' => ['nullable', Rule::in(self::SORTABLE)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ];
    }
}
