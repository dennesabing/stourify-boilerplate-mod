<?php

declare(strict_types=1);

namespace Modules\Stourify\Http\Requests;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Authorises a read of a spot's public page — the one request in this module a
 * stranger with no account may make (STOURIFY-301).
 *
 * **`authorize()` returns true, on purpose, and this is the reason.** The page
 * exists so a link sent to WhatsApp opens something for somebody who has never
 * installed the app; there is no caller identity to check, and inventing one
 * would make the feature pointless. That is not the same as "no gate". The gate
 * is the VISIBILITY rule, and it lives where it can answer with a 404 rather than
 * a 403: `PublicSpotApiController` finds only a published spot, in the public
 * organization, whose contributor's account is not private. A 403 here would tell
 * a stranger that a private spot exists, which is the one thing it must not say.
 *
 * Abuse is bounded by the route's rate limit, not here.
 *
 * The uuid arrives in the path and is constrained by `whereUuid()` on the route,
 * so there is nothing left to validate.
 */
class PublicSpotShowRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [];
    }
}
