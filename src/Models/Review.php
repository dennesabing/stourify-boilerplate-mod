<?php

declare(strict_types=1);

namespace Modules\Stourify\Models;

use App\Traits\BelongsToOrganization;
use App\Traits\Cacheable;
use App\Traits\HasOrganizationMedia;
use App\Traits\HasPermissionPrefix;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Stourify\Database\Factories\ReviewFactory;

/**
 * A rating and write-up on a spot. One per explorer per spot.
 *
 * @use HasFactory<ReviewFactory>
 */
class Review extends Model
{
    use BelongsToOrganization, Cacheable, HasFactory, HasOrganizationMedia,
        HasPermissionPrefix, HasUuid, SoftDeletes;

    protected $table = 'sto_reviews';

    /**
     * A new review changes the spot's rating average and review count.
     *
     * @var array<string, string>
     */
    protected array $invalidatesRelationCachesOf = ['spot' => 'reviews'];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'organization_id',
        'user_id',
        'spot_id',
        'rating',
        'body',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rating' => 'integer',
        ];
    }

    protected static function newFactory(): ReviewFactory
    {
        return ReviewFactory::new();
    }

    public static function morphAlias(): string
    {
        return 'stourify_review';
    }

    public function spot(): BelongsTo
    {
        return $this->belongsTo(Spot::class);
    }
}
