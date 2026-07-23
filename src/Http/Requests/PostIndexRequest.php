<?php

declare(strict_types=1);

namespace Modules\Stourify\Http\Requests;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * Validates the post list query.
 *
 * `sort` is whitelisted to the columns the `(user_id, published_at)` and
 * `(spot_id, published_at)` indexes cover, plus the two denormalized
 * engagement counters the feed sorts on.
 */
class PostIndexRequest extends BaseFormRequest
{
    /**
     * @var list<string>
     */
    public const SORTABLE = ['published_at', 'created_at', 'likes_count', 'comments_count'];

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'spot_uuid' => ['nullable', 'uuid'],
            'mine' => ['nullable', 'boolean'],

            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'sort' => ['nullable', Rule::in(self::SORTABLE)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ];
    }
}
