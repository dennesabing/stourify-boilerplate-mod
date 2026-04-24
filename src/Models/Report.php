<?php

namespace Modules\Stourify\Models;

use App\Models\User;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Modules\Stourify\Database\Factories\ReportFactory;

class Report extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'reporter_id',
        'reportable_type',
        'reportable_id',
        'reason',
        'status',
    ];

    protected $attributes = [
        'status' => 'pending',
    ];

    protected $casts = [
        'status' => 'string',
    ];

    protected static function newFactory(): ReportFactory
    {
        return ReportFactory::new();
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function reportable(): MorphTo
    {
        return $this->morphTo();
    }
}
