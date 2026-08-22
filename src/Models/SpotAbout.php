<?php

declare(strict_types=1);

namespace Modules\Stourify\Models;

use App\Models\User;
use App\Traits\BelongsToOrganization;
use App\Traits\Cacheable;
use App\Traits\HasComments;
use App\Traits\HasPermissionPrefix;
use App\Traits\HasReactions;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Stourify\Database\Factories\SpotAboutFactory;

/**
 * One contributor's note about a spot.
 *
 * The spot's own `description` is the brass plaque — one author, one text.
 * This is the corkboard next to it: anybody who has been there can pin up what
 * they know, and the notes other visitors found useful rise to the top.
 *
 * Almost everything the feature does comes from traits rather than from code
 * here. `HasComments` and `HasReactions` are what make an entry commentable and
 * likeable through endpoints that already exist; `HasPermissionPrefix` derives
 * `spot_abouts` from the class name, which is what the platform's permission
 * discovery mints `spot_abouts.comments.*` and `spot_abouts.reactions.*` from.
 * Nothing anywhere lists those names by hand.
 *
 * @use HasFactory<SpotAboutFactory>
 */
class SpotAbout extends Model
{
    use BelongsToOrganization, Cacheable, HasComments, HasFactory,
        HasPermissionPrefix, HasReactions, HasUuid, SoftDeletes;

    /**
     * An About entry's only reaction is a "like".
     *
     * The platform supports a richer set (love, haha, …) and the base trait
     * would accept all of it. Narrowing to one is what makes "sorted by number
     * of likes" a well-defined instruction: with six types there is no single
     * quantity to sort by, only an unmade decision about which ones count.
     * `Post` and `Review` narrow the same way, to `like` and `helpful`.
     */
    public const LIKE_REACTION = 'like';

    protected $table = 'sto_spot_abouts';

    /**
     * A spot's cached reads include its About entries, so writing one has to
     * bust the spot's cache as well as its own.
     *
     * @var array<string, string>
     */
    protected array $invalidatesRelationCachesOf = ['spot' => 'abouts'];

    /**
     * `likes_count` is deliberately absent: it is denormalized, and
     * `ReactionCountObserver` is its only writer. Leaving it unfillable means a
     * wrong value can only ever have come from one place.
     *
     * @var list<string>
     */
    protected $fillable = [
        'organization_id',
        'spot_id',
        'user_id',
        'body',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'likes_count' => 'integer',
        ];
    }

    protected static function newFactory(): SpotAboutFactory
    {
        return SpotAboutFactory::new();
    }

    /**
     * The stable name written into every polymorphic `*_type` column pointing
     * at this model. The alias is the contract; the namespace is an
     * implementation detail that can move without a data migration.
     */
    public static function morphAlias(): string
    {
        return 'stourify_spot_about';
    }

    /**
     * @return list<string>
     */
    public function supportedReactions(): array
    {
        return [self::LIKE_REACTION];
    }

    /**
     * The explorer who wrote this note.
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
