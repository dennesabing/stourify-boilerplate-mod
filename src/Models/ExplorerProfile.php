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
use Modules\Stourify\Database\Factories\ExplorerProfileFactory;

/**
 * The Stourify half of a user's identity — handle, bio, home city, interests.
 *
 * Deliberately separate from the boilerplate's core UserProfile: that one
 * holds platform data (mobile number, date of birth), this one holds domain
 * data, and domain data lives in the module.
 *
 * @use HasFactory<ExplorerProfileFactory>
 */
class ExplorerProfile extends Model
{
    use BelongsToOrganization, Cacheable, HasFactory, HasPermissionPrefix, HasUuid;

    protected $table = 'sto_explorer_profiles';

    /**
     * @var array<int, string>
     */
    protected array $invalidatesCachesOf = ['user'];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'organization_id',
        'user_id',
        'home_city_id',
        'username',
        'bio',
        'website',
        'interests',
        'is_private',
        'shows_location_on_spots',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'interests' => 'array',
            'is_private' => 'boolean',
            'shows_location_on_spots' => 'boolean',
        ];
    }

    protected static function newFactory(): ExplorerProfileFactory
    {
        return ExplorerProfileFactory::new();
    }

    public static function morphAlias(): string
    {
        return 'stourify_explorer_profile';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function homeCity(): BelongsTo
    {
        return $this->belongsTo(City::class, 'home_city_id');
    }
}
