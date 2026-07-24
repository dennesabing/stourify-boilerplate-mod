<?php

declare(strict_types=1);

namespace Modules\Stourify\Http\Requests;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Modules\Stourify\Models\ExplorerProfile;

/**
 * Validates a create-or-update of the caller's own profile.
 *
 * `PATCH /profile` is an upsert: the same request validates the first-time
 * create during onboarding and every later edit. That is why `username` is
 * `required` only when no profile exists yet — on an established profile it is
 * `sometimes`, so a client can change a bio without restating the handle.
 *
 * Username uniqueness is platform-wide, not per-organization (the column is
 * globally unique). The caller's own row is ignored so re-saving an unchanged
 * username is not a conflict. It is normalized to lowercase in the controller,
 * so the uniqueness check must run case-insensitively to match — enforced by
 * the lowercase rule here plus the controller's normalization.
 */
class ProfileUpdateRequest extends BaseFormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $existing = $this->currentProfile();
        $usernamePresence = $existing === null ? 'required' : 'sometimes';

        return [
            'username' => [
                $usernamePresence,
                'string',
                'min:3',
                'max:30',
                'lowercase',
                'regex:/^[a-z0-9_.]+$/',
                Rule::unique('sto_explorer_profiles', 'username')->ignore($existing?->id),
            ],
            'bio' => ['sometimes', 'nullable', 'string', 'max:150'],
            'website' => ['sometimes', 'nullable', 'url', 'max:255'],
            'home_city_uuid' => [
                'sometimes',
                'nullable',
                'uuid',
                Rule::exists('sto_cities', 'uuid')->whereNull('deleted_at'),
            ],
            'interests' => ['sometimes', 'nullable', 'array', 'max:20'],
            'interests.*' => ['string', 'max:40'],

            'is_private' => ['sometimes', 'boolean'],
            'shows_location_on_spots' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * The caller's existing profile, if any. Drives whether `username` is
     * required and which row the uniqueness rule ignores.
     */
    public function currentProfile(): ?ExplorerProfile
    {
        return ExplorerProfile::query()->where('user_id', $this->user()->id)->first();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'username.regex' => 'A username may use only lowercase letters, numbers, dots and underscores.',
            'username.unique' => 'That username is taken.',
            'home_city_uuid.exists' => 'That city does not exist.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['home_city_uuid' => 'home city'];
    }
}
