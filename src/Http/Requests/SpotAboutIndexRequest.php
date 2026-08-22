<?php

declare(strict_types=1);

namespace Modules\Stourify\Http\Requests;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * Validates the About list query.
 *
 * `spot_uuid` is required rather than optional. Every other index in this
 * module lists across spots and lets the caller narrow; this one has no
 * meaning unbounded — "all About entries everywhere, most-liked first" is not
 * a screen anybody wants, and answering it would page through every spot's
 * contributions.
 *
 * `sort` is whitelisted to the three columns the `(spot_id, likes_count)` index
 * and the timestamps cover. An unlisted value is REJECTED rather than ignored:
 * a silently-dropped sort returns a differently-ordered list while the client
 * believes it asked for something specific.
 */
class SpotAboutIndexRequest extends BaseFormRequest
{
    /**
     * @var list<string>
     */
    public const SORTABLE = ['likes_count', 'created_at', 'updated_at'];

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'spot_uuid' => ['required', 'uuid'],
            'mine' => ['nullable', 'boolean'],

            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'sort' => ['nullable', Rule::in(self::SORTABLE)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ];
    }
}
