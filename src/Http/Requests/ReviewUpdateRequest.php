<?php

declare(strict_types=1);

namespace Modules\Stourify\Http\Requests;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates an edit to a review.
 *
 * `spot_uuid` is absent: a review belongs to the spot it was written about,
 * and moving it would silently corrupt both spots' rating aggregates. Editing
 * replaces the rating and the write-up, nothing else. `helpful_count` is
 * likewise not writable — it is other people's opinion of the review.
 */
class ReviewUpdateRequest extends BaseFormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'rating' => ['sometimes', 'required', 'integer', 'between:1,5'],
            'body' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rating.between' => 'A rating must be between 1 and 5.',
        ];
    }
}
