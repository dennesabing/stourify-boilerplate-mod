<?php

declare(strict_types=1);

namespace Modules\Stourify\Http\Requests;

use App\Http\Requests\BaseFormRequest;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Stourify\Models\Follow;

/**
 * Validates a new follow.
 *
 * The client sends only who to follow. It does **not** send `status`: whether
 * the edge lands Active or Pending is decided by the *target's* privacy
 * setting, and letting a caller choose would let anyone walk past a private
 * account's gate by simply asking for `active`.
 *
 * Two rules are checked here so they surface as 422s naming the field rather
 * than as a 500 from the unique index or a nonsensical self-edge:
 * following yourself, and following someone twice.
 */
class FollowStoreRequest extends BaseFormRequest
{
    /**
     * Gate the endpoint here, ahead of validation — `CrudService` authorizes
     * the same ability, but only after the rules below have already run for a
     * caller who may not follow anyone at all (STOURIFY-23). The `exists`
     * lookup on `users` makes that ordering matter more than usual: it answers
     * whether an account exists, to someone the module has not authorized.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Follow::class) ?? false;
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

            $targetId = User::query()->where('uuid', $this->input('user_uuid'))->value('id');

            if ($targetId === null) {
                return;
            }

            if ($targetId === $this->user()->id) {
                $validator->errors()->add('user_uuid', 'You cannot follow yourself.');

                return;
            }

            $alreadyFollowing = Follow::query()
                ->where('follower_id', $this->user()->id)
                ->where('followee_id', $targetId)
                ->exists();

            if ($alreadyFollowing) {
                $validator->errors()->add('user_uuid', 'You already follow this explorer.');
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
