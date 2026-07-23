<?php

declare(strict_types=1);

namespace Modules\Stourify\Http\Requests;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Modules\Stourify\Enums\SpotStatus;

/**
 * Validates an edit to an existing spot.
 *
 * Every field is `sometimes` so a client can PATCH one attribute without
 * restating the record — which is also what the offline sync push sends.
 *
 * As with the store request, the `update` ability is enforced by
 * `CrudService` via `Gate::authorize('update', $spot)`; see SpotStoreRequest
 * for why authorization lives there rather than in `authorize()`.
 *
 * `is_verified` is deliberately absent: it is a moderator trust signal set
 * through its own gated action, never a field an author can PATCH.
 */
class SpotUpdateRequest extends BaseFormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'min:3', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],

            'latitude' => ['sometimes', 'required', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'required', 'numeric', 'between:-180,180'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],

            'city_uuid' => ['sometimes', 'nullable', 'uuid', Rule::exists('sto_cities', 'uuid')->whereNull('deleted_at')],

            'categories' => ['sometimes', 'nullable', 'array', 'max:10'],
            'categories.*' => ['string', 'max:40'],

            'hours' => ['sometimes', 'nullable', 'array'],

            'status' => ['sometimes', Rule::in([
                SpotStatus::Draft->value,
                SpotStatus::Published->value,
            ])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.in' => 'A spot can only be moved between draft and published.',
            'city_uuid.exists' => 'That city does not exist.',
        ];
    }
}
