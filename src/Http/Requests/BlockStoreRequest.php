<?php

declare(strict_types=1);

namespace Modules\Stourify\Http\Requests;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Stourify\Models\Block;

/**
 * Validates a new block.
 *
 * Blocking someone you have already blocked is *not* an error here — the
 * controller answers it idempotently, because a second tap on Block should
 * feel like the first one worked, not like something broke. That is the one
 * way this differs from `FollowStoreRequest`, which rejects a duplicate edge.
 * Self-blocking is still a 422: it is nonsense rather than a repeat.
 */
class BlockStoreRequest extends BaseFormRequest
{
    /**
     * Gated here, ahead of validation, for the reason `FollowStoreRequest`
     * spells out: the `exists` rule below answers whether an account exists,
     * and that question must not be answerable by a caller the module has not
     * authorized.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Block::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'user_uuid' => [
                'required',
                'uuid',
                Rule::exists('users', 'uuid')->whereNull('deleted_at'),
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if ($this->input('user_uuid') === $this->user()?->uuid) {
                $validator->errors()->add('user_uuid', 'You cannot block yourself.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'user_uuid.exists' => 'That explorer does not exist.',
        ];
    }
}
