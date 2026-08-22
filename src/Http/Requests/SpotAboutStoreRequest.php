<?php

declare(strict_types=1);

namespace Modules\Stourify\Http\Requests;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Modules\Stourify\Models\SpotAbout;

/**
 * Validates a new About entry.
 *
 * `body` is capped at 2,000 characters. An About entry is a note, not an
 * essay — the same reasoning that caps a post's caption, one size up because
 * this one is prose rather than a caption.
 */
class SpotAboutStoreRequest extends BaseFormRequest
{
    /**
     * Gate the endpoint here, ahead of validation.
     *
     * `CrudService::create()` authorizes this ability too, so this is not the
     * only lock on the door — it is the one that fires FIRST. Laravel runs
     * `authorize()` before `rules()`, so without this override a caller who may
     * not write an entry at all is answered with a 422 itemising the fields the
     * server wanted, and reaches 403 only if their payload happens to validate.
     * Worse here than elsewhere: the rules contain an `exists` lookup on
     * `spot_uuid`, so that 422 is a free existence oracle over the spot table.
     * The ordering defect is STOURIFY-23.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', SpotAbout::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'spot_uuid' => [
                'required',
                'uuid',
                Rule::exists('sto_spots', 'uuid')->whereNull('deleted_at'),
            ],
            'body' => ['required', 'string', 'min:1', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'spot_uuid.exists' => 'That spot does not exist.',
        ];
    }
}
