<?php

declare(strict_types=1);

namespace Modules\Stourify\Http\Requests;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates a proximity query.
 *
 * The radius cap is not arbitrary. `Spot::scopeNearby()` approximates the
 * globe with a flat bounding box scaled by cos(latitude), which is accurate to
 * well under a percent at city scale and degrades as the radius grows. Capping
 * the radius keeps callers inside the range where that approximation holds —
 * see the scope's docblock and technical-spec.md §5.
 */
class SpotNearbyRequest extends BaseFormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'radius' => ['nullable', 'numeric', 'min:0.1', 'max:'.config('stourify.discovery.max_radius_km', 50.0)],

            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'radius.max' => 'The radius may not be greater than :max km — the planar distance approximation is only calibrated to city scale.',
        ];
    }
}
