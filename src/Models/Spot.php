<?php

namespace Modules\Stourify\Models;

use App\Contracts\HasCrudService;
use App\Traits\Cacheable;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Modules\Stourify\Database\Factories\SpotFactory;
use Modules\Stourify\Services\SpotCrudService;

class Spot extends Model implements HasCrudService
{
    use Cacheable, HasFactory, HasUuid;

    public static function crudService(): string
    {
        return SpotCrudService::class;
    }

    protected $fillable = [
        'category_id',
        'created_by',
        'name',
        'slug',
        'description',
        'latitude',
        'longitude',
        'address',
        'status',
        'verified_at',
    ];

    protected $casts = [
        'latitude'    => 'decimal:7',
        'longitude'   => 'decimal:7',
        'verified_at' => 'datetime',
    ];

    protected static function newFactory(): SpotFactory
    {
        return SpotFactory::new();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(SpotCategory::class, 'category_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(SpotTag::class, 'spot_tag_pivot');
    }
}
