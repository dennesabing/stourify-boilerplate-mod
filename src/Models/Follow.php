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
use Modules\Stourify\Database\Factories\FollowFactory;
use Modules\Stourify\Enums\FollowStatus;

/**
 * A directed edge in the follow graph.
 *
 * No SoftDeletes: unfollowing is a real removal, and a soft-deleted row would
 * collide with the (follower_id, followee_id) unique constraint on re-follow —
 * the same reasoning the core Reaction model documents.
 *
 * @use HasFactory<FollowFactory>
 */
class Follow extends Model
{
    use BelongsToOrganization, Cacheable, HasFactory, HasPermissionPrefix, HasUuid;

    protected $table = 'sto_follows';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'organization_id',
        'follower_id',
        'followee_id',
        'status',
        'accepted_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => FollowStatus::class,
            'accepted_at' => 'datetime',
        ];
    }

    protected static function newFactory(): FollowFactory
    {
        return FollowFactory::new();
    }

    public static function morphAlias(): string
    {
        return 'stourify_follow';
    }

    public function follower(): BelongsTo
    {
        return $this->belongsTo(User::class, 'follower_id');
    }

    public function followee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'followee_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', FollowStatus::Active->value);
    }
}
