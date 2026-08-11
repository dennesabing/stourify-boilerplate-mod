<?php

declare(strict_types=1);

namespace Modules\Stourify\Http\Requests;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Modules\Stourify\Enums\PostVisibility;
use Modules\Stourify\Models\Post;

/**
 * Validates a new post.
 *
 * `publish` is a boolean rather than a `published_at` timestamp the client
 * supplies. The server owns that clock — a device with a skewed or deliberately
 * altered clock must not be able to backdate itself up the feed, or postdate
 * itself into a future the ranking query will not return.
 *
 * A post may be created unpublished on purpose: the offline flow writes the
 * record first and publishes once every queued photo has uploaded.
 */
class PostStoreRequest extends BaseFormRequest
{
    /**
     * Gate the endpoint here, ahead of validation.
     *
     * `CrudService::create()` already authorizes this ability, so this is not
     * the only lock on the door — but it is the one that fires *first*. Laravel
     * runs `authorize()` before `rules()`, so without this override a caller who
     * may not create a post at all was answered with a 422 itemising the fields
     * the server wanted, and only reached 403 if their payload happened to
     * validate. That ordering is the defect STOURIFY-23 records; the root
     * CLAUDE.md's "authorize in the FormRequest (preferred)" is the rule that
     * prevents it.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Post::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'spot_uuid' => [
                'nullable',
                'uuid',
                Rule::exists('sto_spots', 'uuid')->whereNull('deleted_at'),
            ],
            'caption' => ['nullable', 'string', 'max:2200'],
            'visibility' => ['nullable', Rule::enum(PostVisibility::class)],
            'publish' => ['nullable', 'boolean'],
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
