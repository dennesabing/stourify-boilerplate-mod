<?php

declare(strict_types=1);

namespace Modules\Stourify\Http\Resources;

use App\Http\Resources\BaseResource;
use Illuminate\Http\Request;
use Modules\Stourify\Models\City;

/**
 * Cities are curated reference data, not user-generated content, so this is a
 * thin read shape — enough to label and group a spot.
 *
 * @property City $resource
 */
class CityResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $city = $this->resource;

        return [
            'uuid' => $city->uuid,
            'name' => $city->name,
            'region' => $city->region,
            'country' => $city->country,
            'is_featured' => $city->is_featured,
        ];
    }
}
