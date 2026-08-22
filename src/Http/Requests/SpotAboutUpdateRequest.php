<?php

declare(strict_types=1);

namespace Modules\Stourify\Http\Requests;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates an edit to an About entry.
 *
 * Only the text can change. An entry cannot be moved to another spot and
 * cannot be reassigned to another author — both would rewrite history a reader
 * has already reacted to, and neither is something the feature asks for. Those
 * fields are absent rather than validated-and-ignored, so an attempt to send
 * one is simply discarded by `validated()` rather than silently honoured.
 *
 * Authorization is not overridden here, unlike the store request: the ability
 * is per-record (`update` on this entry), the record comes from route model
 * binding, and `CrudService::update()` is the single gate every write in this
 * platform passes through. There is no existence oracle to protect — the
 * caller already had to name a real entry to reach this request at all.
 */
class SpotAboutUpdateRequest extends BaseFormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'min:1', 'max:2000'],
        ];
    }
}
