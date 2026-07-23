<?php

declare(strict_types=1);

namespace Modules\Stourify\Models;

use App\Models\User;
use App\Traits\BelongsToOrganization;
use App\Traits\Cacheable;
use App\Traits\HasPermissionPrefix;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Modules\Stourify\Database\Factories\ReportFactory;
use Modules\Stourify\Enums\ReportReason;
use Modules\Stourify\Enums\ReportStatus;

/**
 * A moderation report against any reportable subject — spot, post, review,
 * comment or user. Anonymous to the reported party, attributed for moderators.
 *
 * @use HasFactory<ReportFactory>
 */
class Report extends Model
{
    use BelongsToOrganization, Cacheable, HasFactory, HasPermissionPrefix, HasUuid;

    protected $table = 'sto_reports';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'organization_id',
        'user_id',
        'reportable_type',
        'reportable_id',
        'reason',
        'details',
        'status',
        'resolved_by_id',
        'resolved_at',
        'resolution_note',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reason' => ReportReason::class,
            'status' => ReportStatus::class,
            'resolved_at' => 'datetime',
        ];
    }

    protected static function newFactory(): ReportFactory
    {
        return ReportFactory::new();
    }

    public static function morphAlias(): string
    {
        return 'stourify_report';
    }

    public function reportable(): MorphTo
    {
        return $this->morphTo();
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [
            ReportStatus::Pending->value,
            ReportStatus::Reviewing->value,
        ]);
    }
}
