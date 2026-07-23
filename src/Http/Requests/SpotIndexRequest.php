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

            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'sort' => ['nullable', Rule::in(self::SORTABLE)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ];
    }
}
