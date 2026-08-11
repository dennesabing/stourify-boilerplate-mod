<?php

declare(strict_types=1);

namespace Modules\Stourify\Http\Requests;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Stourify\Models\Review;
use Modules\Stourify\Models\Spot;

/**
 * Validates a new review.
 *
 * The one-review-per-explorer-per-spot rule is enforced twice on purpose: by a
 * unique index on `(spot_id, user_id)`, which is the actual guarantee, and
 * here, so a second attempt comes back as a 422 naming the field rather than a
 * 500 from a constraint violation. The index stays authoritative — it is what
 * holds under a concurrent double-submit from a retrying offline client.
 */
class ReviewStoreRequest extends BaseFormRequest
{
    /**
     * Gate the endpoint here, ahead of validation — `CrudService` authorizes
     * the same ability, but only after the rules below have already run for a
     * caller who may not create a review at all (STOURIFY-23).
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Review::class) ?? false;
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
            'rating' => ['required', 'integer', 'between:1,5'],
            'body' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $spotId = Spot::query()->where('uuid', $this->input('spot_uuid'))->value('id');

            if ($spotId === null) {
                return;
            }

            $exists = Review::query()
                ->where('spot_id', $spotId)
                ->where('user_id', $this->user()->id)
                ->exists();

            if ($exists) {
                $validator->errors()->add(
                    'spot_uuid',
                    'You have already reviewed this spot — edit your existing review instead.',
                );
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rating.between' => 'A rating must be between 1 and 5.',
            'spot_uuid.exists' => 'That spot does not exist.',
        ];
    }
}
