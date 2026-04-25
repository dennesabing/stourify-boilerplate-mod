<?php

namespace Modules\Stourify\Models;

use App\Traits\Cacheable;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Stourify\Database\Factories\SpotCategoryFactory;

class SpotCategory extends Model
{
    use Cacheable, HasFactory, HasUuid;

    protected $fillable = ['name', 'slug', 'icon'];

    protected static function newFactory(): SpotCategoryFactory
    {
        return SpotCategoryFactory::new();
    }

    public function spots(): HasMany
    {
        return $this->hasMany(Spot::class, 'category_id');
    }
}
