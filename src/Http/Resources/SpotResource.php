<?php

namespace Modules\Stourify\Http\Resources;

use App\Http\Resources\BaseResource;
use Illuminate\Http\Request;

class SpotResource extends BaseResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->uuid,
            'name'        => $this->name,
            'slug'        => $this->slug,
            'description' => $this->description,
            'latitude'    => (float) $this->latitude,
            'longitude'   => (float) $this->longitude,
            'address'     => $this->address,
            'status'      => $this->status,
            'verified_at' => $this->verified_at?->toISOString(),
            'category'    => $this->whenLoaded('category', fn () => [
                'id'   => $this->category->uuid,
                'name' => $this->category->name,
                'slug' => $this->category->slug,
            ]),
            'creator' => $this->whenLoaded('creator', fn () => [
                'id'   => $this->creator->uuid,
                'name' => $this->creator->name,
            ]),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'can'        => $this->resolvePermissions($this->resource),
        ];
    }
}
