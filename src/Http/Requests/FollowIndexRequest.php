<?php

declare(strict_types=1);

namespace Modules\Stourify\Http\Requests;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Modules\Stourify\Enums\FollowStatus;

/**
 * Validates a follow-graph list query.
 *
 * `direction` says which side of the edge to read — the same table backs both
 * the Followers and the Following screen, which is why both directions are
 * indexed. `user_uuid` defaults to the caller.
 */
class FollowIndexRequest extends BaseFormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'direction' => ['nullable', Rule::in(['followers', 'following'])],
            'user_uuid' => ['nullable', 'uuid'],
            'status' => ['nullable', Rule::enum(FollowStatus::class)],

            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
