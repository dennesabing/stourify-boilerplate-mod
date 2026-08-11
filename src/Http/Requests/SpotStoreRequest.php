<?php

declare(strict_types=1);

namespace Modules\Stourify\Http\Requests;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Modules\Stourify\Enums\SpotStatus;
use Modules\Stourify\Models\Spot;

/**
 * Validates a new spot.
 *
 * A client may only create a spot as `draft` or `published`. `under_review`
 * and `removed` are moderation outcomes, never something an author asks for.
 */
class SpotStoreRequest extends BaseFormRequest
{
    /**
     * Gate the endpoint here, ahead of validation.
     *
     * This request previously documented the opposite choice — leave
     * `authorize()` at the `BaseFormRequest` default and let `CrudService` own
     * the check, so the web controller, the API controller and the sync push
     * path could not drift apart. That reasoning holds and `CrudService` still
     * runs its gate; what it missed is *when*. Laravel authorizes before it
     * validates, so a check that lives only in `CrudService` runs after the
     * whole rule set — including the `exists` lookups below — has already
     * answered a caller who may not create a spot at all. One lock is not
     * enough when the question is which door opens first (STOURIFY-23).
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Spot::class) ?? false;
    }

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
