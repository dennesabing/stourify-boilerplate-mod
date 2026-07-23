<?php

declare(strict_types=1);

namespace Modules\Stourify\Http\Requests;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Modules\Stourify\Enums\PostVisibility;

/**
 * Validates an edit to a post.
 *
 * Visibility is editable — tightening a post from `public` to `private` after
 * the fact is a thing people genuinely need to do.
 *
 * Publishing is not here: it is its own gated action on its own route, because
 * it is a one-way transition with its own ability, and folding it into a
 * general PATCH would let any field update quietly flip a draft into the feed.
 * `likes_count` and `comments_count` are likewise absent — they are derived.
 */
class PostUpdateRequest extends BaseFormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'spot_uuid' => [
                'sometimes',
                'nullable',
                'uuid',
                Rule::exists('sto_spots', 'uuid')->whereNull('deleted_at'),
            ],
            'caption' => ['sometimes', 'nullable', 'string', 'max:2200'],
            'visibility' => ['sometimes', Rule::enum(PostVisibility::class)],
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
