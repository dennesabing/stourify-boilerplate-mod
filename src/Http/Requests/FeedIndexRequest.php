<?php

declare(strict_types=1);

namespace Modules\Stourify\Http\Requests;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates a home-feed page request.
 *
 * The feed is cursor-paginated, not page-numbered: `cursor` is an opaque token
 * the previous page handed back, so it is validated only as a string, never
 * parsed here. `limit` is capped so a client cannot pull an unbounded page.
 * There is no `sort` — the feed's order is fixed (newest first), because a
 * cursor is only stable against a fixed, indexed ordering.
 */
class FeedIndexRequest extends BaseFormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'cursor' => ['nullable', 'string'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}
