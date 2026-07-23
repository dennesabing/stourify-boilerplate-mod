<?php

declare(strict_types=1);

namespace Modules\Stourify\Http\Requests;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Modules\Stourify\Enums\SpotStatus;

/**
 * Validates a new spot.
 *
 * `authorize()` stays `true` per the platform convention documented on
 * BaseFormRequest — the create ability is enforced by `CrudService`, which
 * calls `Gate::authorize('create', Spot::class)` before it writes. That is the
 * "explicit Gate check" the root CLAUDE.md permits, and keeping it in one
 * place means the web controller, the API controller and the sync push path
 * cannot drift apart.
 *
 * A client may only create a spot as `draft` or `published`. `under_review`
 * and `removed` are moderation outcomes, never something an author asks for.
 */
class SpotStoreRequest extends BaseFormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:3', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],

            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'address' => ['nullable', 'string', 'max:255'],

            'city_uuid' => ['nullable', 'uuid', Rule::exists('sto_cities', 'uuid')->whereNull('deleted_at')],

            'categories' => ['nullable', 'array', 'max:10'],
            'categories.*' => ['string', 'max:40'],

            'hours' => ['nullable', 'array'],

            'status' => ['nullable', Rule::in([
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
            'status.in' => 'A spot can only be created as a draft or published.',
            'city_uuid.exists' => 'That city does not exist.',
        ];
    }
}
