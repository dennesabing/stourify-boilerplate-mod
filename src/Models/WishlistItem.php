<?php

declare(strict_types=1);

namespace Modules\Stourify\Models;

use App\Models\User;
use App\Traits\BelongsToOrganization;
use App\Traits\Cacheable;
use App\Traits\HasPermissionPrefix;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Stourify\Database\Factories\WishlistItemFactory;

/**
 * A saved spot. `city_id` is denormalized off the spot so the Wishlist's
 * group-by-city query stays single-table.
 *
 * @use HasFactory<WishlistItemFactory>
 */
class WishlistItem extends Model
{
    use BelongsToOrganization, Cacheable, HasFactory, HasPermissionPrefix, HasUuid;

    protected $table = 'sto_wishlist_items';

    /**
     * @var array<string, string>
     */
    protected array $invalidatesRelationCachesOf = ['spot' => 'wishlistItems'];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'organization_id',
        'user_id',
        'spot_id',
        'city_id',
        'note',
        'is_downloaded_offline',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_downloaded_offline' => 'boolean',
        ];
    }

    protected static function newFactory(): WishlistItemFactory
    {
        return WishlistItemFactory::new();
    }

    public static function morphAlias(): string
    {
        return 'stourify_wishlist_item';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function spot(): BelongsTo
    {
        return $this->belongsTo(Spot::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }
}
