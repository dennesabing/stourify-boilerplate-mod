<?php

declare(strict_types=1);

namespace Modules\Stourify\Models;

use App\Models\User;
use App\Traits\BelongsToOrganization;
use App\Traits\Cacheable;
use App\Traits\HasComments;
use App\Traits\HasOrganizationMedia;
use App\Traits\HasPermissionPrefix;
use App\Traits\HasReactions;
use App\Traits\HasTags;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Stourify\Database\Factories\PostFactory;
use Modules\Stourify\Enums\PostVisibility;
use Spatie\MediaLibrary\HasMedia;

/**
 * One explorer's visit to a spot — the unit the Home Feed renders.
 *
 * @use HasFactory<PostFactory>
 */
class Post extends Model implements HasMedia
{
    use BelongsToOrganization, Cacheable, HasComments, HasFactory, HasOrganizationMedia,
        HasPermissionPrefix, HasReactions, HasTags, HasUuid, SoftDeletes;

    protected $table = 'sto_posts';

    /**
     * Busting a post's cache must also bust its spot's — the Spot Hub renders
     * the post's media in its gallery.
     *
     * @var array<string, string>
     */
    protected array $invalidatesRelationCachesOf = ['spot' => 'posts'];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'organization_id',
        'user_id',
        'spot_id',
        'caption',
        'visibility',
        'published_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'visibility' => PostVisibility::class,
            'published_at' => 'datetime',
        ];
    }

    protected static function newFactory(): PostFactory
    {
        return PostFactory::new();
    }

    public static function morphAlias(): string
    {
        return 'stourify_post';
    }

    /**
     * The explorer whose visit this post records.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function spot(): BelongsTo
    {
        return $this->belongsTo(Spot::class);
    }

    /**
     * Posts that have finished uploading their media and are publicly visible.
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->whereNotNull('published_at')
            ->where('visibility', PostVisibility::Public->value);
    }
}
