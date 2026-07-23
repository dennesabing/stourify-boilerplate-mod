<?php

declare(strict_types=1);

namespace Modules\Stourify\Http\Requests;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Modules\Stourify\Enums\PostVisibility;

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
