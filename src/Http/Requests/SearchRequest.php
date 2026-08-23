<?php

declare(strict_types=1);

namespace Modules\Stourify\Http\Requests;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * Validates a discovery search.
 *
 * `type` selects one result set for its own tab (`spots`, `cities`, `people`,
 * `tags`); omitting it returns a small preview of all four — the "All" tab. `q`
 * has a two-character floor because single-character full-text queries match
 * almost everything and are never what a user means.
 *
 * `tags` searches the hashtags people have typed, and it is the whole of
 * STOURIFY-25 — it used to be refused with a `422` because there was no tag
 * vocabulary to search until STOURIFY-171 created one.
 */
class SearchRequest extends BaseFormRequest
{
    /**
     * @var list<string>
     */
    public const TYPES = ['spots', 'cities', 'people', 'tags'];

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'min:2', 'max:255'],
            'type' => ['nullable', Rule::in(self::TYPES)],

            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
