<?php

declare(strict_types=1);

namespace Modules\Stourify\Http\Requests;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * Validates the review list query.
 *
 * `sort` is whitelisted to columns backed by the `(spot_id, rating)` and
 * `(organization_id, created_at)` indexes, plus `helpful_count` — the Reviews
 * screen's "most helpful" ordering.
 */
class ReviewIndexRequest extends BaseFormRequest
{
    /**
     * @var list<string>
     */
    public const SORTABLE = ['created_at', 'rating', 'helpful_count'];

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'spot_uuid' => ['nullable', 'uuid'],
            'mine' => ['nullable', 'boolean'],
            'rating' => ['nullable', 'integer', 'between:1,5'],

            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'sort' => ['nullable', Rule::in(self::SORTABLE)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ];
    }
}
