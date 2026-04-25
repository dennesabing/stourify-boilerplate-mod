<?php

namespace Modules\Stourify\Models;

use App\Traits\Cacheable;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Modules\Stourify\Database\Factories\SpotTagFactory;

class SpotTag extends Model
{
    use Cacheable, HasFactory, HasUuid;

    protected $fillable = ['name', 'slug'];

    protected static function newFactory(): SpotTagFactory
    {
        return SpotTagFactory::new();
    }
}
