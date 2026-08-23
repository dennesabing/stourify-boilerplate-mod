<?php

declare(strict_types=1);

namespace Modules\Stourify\Http\Requests;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Modules\Stourify\Models\Spot;

/**
 * Validates and authorises a hashtag lookup.
 *
 * The slug arrives in the path rather than the query string, so there is
 * nothing here to validate — but the request still exists, because
 * authorisation is its job and the root `CLAUDE.md` prefers it here rather than
 * in the controller. Laravel runs `authorize()` before `rules()`, so a caller
 * who may not be on this surface at all is answered `403` rather than being
 * handed a `422` describing the fields the server wanted. That ordering is the
 * defect STOURIFY-23 records.
 *
 * **Gated on spots, not on tags.** `organizations.tags.view` is the admin
 * panel's permission for managing the tag vocabulary, and no ordinary explorer
 * holds it — gating on it would make every tag page 403 for the people the
 * feature is for. Looking a hashtag up is a discovery action, so it is gated
 * exactly like the discovery surfaces beside it: `viewAny` on `Spot`, which is
 * what `/discover/search` and `/spots/nearby` already use.
 */
class TagShowRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Spot::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [];
    }
}
