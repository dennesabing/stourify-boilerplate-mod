<?php

declare(strict_types=1);

namespace Modules\Stourify\Http\Requests;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates an edit to a saved spot.
 *
 * Only the two fields a client actually changes on a saved item: the personal
 * note, and whether it has been downloaded for offline use. The spot itself is
 * not movable — re-saving a different spot is a new item, not an edit.
 */
class WishlistUpdateRequest extends BaseFormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'is_downloaded_offline' => ['sometimes', 'boolean'],
        ];
    }
}
