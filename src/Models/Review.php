<?php

declare(strict_types=1);

namespace Modules\Stourify\Models;

use App\Models\User;
use App\Traits\BelongsToOrganization;
use App\Traits\Cacheable;
use App\Traits\HasOrganizationMedia;
use App\Traits\HasPermissionPrefix;
use App\Traits\HasReactions;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Stourify\Database\Factories\ReviewFactory;
use Spatie\MediaLibrary\HasMedia;

/**
 * A rating and write-up on a spot. One per explorer per spot.
 *
 * @use HasFactory<ReviewFactory>
 */
class Review extends Model implements HasMedia
{
    use BelongsToOrganization, Cacheable, HasFactory, HasOrganizationMedia,
        HasPermissionPrefix, HasReactions, HasUuid, SoftDeletes;

    /**
     * A review's only reaction is a "helpful" vote. It rides on the platform's
     * reaction subsystem — one per explorer, toggleable, deduplicated by the
     * unique reaction index — rather than a bare counter, which could not stop
     * one person voting many times. `helpful_count` remains as the denormalized
     * column the Reviews screen sorts on, kept truthful from these reactions.
     */
    public const HELPFUL_REACTION = 'helpful';

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

    /**
     * A review accepts only the "helpful" reaction — see HELPFUL_REACTION.
     *
     * @return list<string>
     */
    public function supportedReactions(): array
    {
        return [self::HELPFUL_REACTION];
    }

    /**
     * The explorer who wrote the review.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function spot(): BelongsTo
    {
        return $this->belongsTo(Spot::class);
    }
}
