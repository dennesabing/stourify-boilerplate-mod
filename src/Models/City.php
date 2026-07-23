<?php

declare(strict_types=1);

namespace Modules\Stourify\Models;

use App\Traits\BelongsToOrganization;
use App\Traits\Cacheable;
use App\Traits\HasPermissionPrefix;
use App\Traits\HasUuid;
use App\Traits\OrganizationSearchable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Stourify\Database\Factories\CityFactory;

/**
 * Curated reference data — anchors the home-city choice, groups the Wishlist,
 * and backs Featured Cities.
 *
 * @use HasFactory<CityFactory>
 */
class City extends Model
{
    use BelongsToOrganization, Cacheable, HasFactory, HasPermissionPrefix, HasUuid,
        OrganizationSearchable, SoftDeletes;

    protected $table = 'sto_cities';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'organization_id',
        'name',
        'slug',
        'region',
        'country',
        'latitude',
        'longitude',
        'is_featured',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'is_featured' => 'boolean',
        ];
    }

    protected static function newFactory(): CityFactory
    {
        return CityFactory::new();
    }

    public static function morphAlias(): string
    {
        return 'stourify_city';
    }

    public function spots(): HasMany
    {
        return $this->hasMany(Spot::class);
    }

    public function searchableAs(): string
    {
        return 'sto_cities';
    }

    /**
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'organization_id' => $this->organization_id,
            'name' => $this->name,
            'region' => $this->region,
            'country' => $this->country,
        ];
    }
}
