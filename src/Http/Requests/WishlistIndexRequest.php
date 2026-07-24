<?php

declare(strict_types=1);

namespace Modules\Stourify\Http\Requests;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates the wishlist list query.
 *
 * `city_uuid` narrows to one city's saved spots — the Wishlist screen groups
 * by city and lets a group be opened on its own. There is no `sort` whitelist
 * or `mine` flag: a wishlist is always the caller's own and always newest
 * first, so neither is a meaningful axis.
 */
class WishlistIndexRequest extends BaseFormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'city_uuid' => ['nullable', 'uuid'],
            'downloaded' => ['nullable', 'boolean'],

            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
