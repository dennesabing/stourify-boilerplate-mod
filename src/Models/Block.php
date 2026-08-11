<?php

declare(strict_types=1);

namespace Modules\Stourify\Models;

use App\Models\User;
use App\Services\OrganizationContext;
use App\Traits\BelongsToOrganization;
use App\Traits\Cacheable;
use App\Traits\HasPermissionPrefix;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Stourify\Database\Factories\BlockFactory;

/**
 * One explorer blocking another.
 *
 * The row is directed — `blocker` did it, and only they may lift it — but its
 * *effect* is symmetric, and that asymmetry is the whole point of this class.
 * Everything downstream asks the undirected question through
 * `hiddenUserIdsFor()` / `existsBetween()`, so no caller has to remember which
 * way round the row was written. A block enforced in one direction only is not
 * a block: the blocked party would go on reading, searching and following the
 * person who blocked them.
 *
 * No SoftDeletes, for the reason `Follow` documents: unblocking is a real
 * removal, and a tombstone would collide with the
 * `(blocker_id, blocked_id)` unique index on re-block.
 *
 * @use HasFactory<BlockFactory>
 */
class Block extends Model
{
    use BelongsToOrganization, Cacheable, HasFactory, HasPermissionPrefix, HasUuid;

    protected $table = 'sto_blocks';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'organization_id',
        'blocker_id',
        'blocked_id',
    ];

    protected static function newFactory(): BlockFactory
    {
        return BlockFactory::new();
    }

    public static function morphAlias(): string
    {
        return 'stourify_block';
    }

    public function blocker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'blocker_id');
    }

    public function blocked(): BelongsTo
    {
        return $this->belongsTo(User::class, 'blocked_id');
    }

    /**
     * Where the per-request memo lives. See hiddenUserIdsFor().
     */
    private const MEMO_KEY = 'stourify.blocks.hidden';

    /**
     * Flush the memo whenever a block appears or goes away, so a read later in
     * the same request sees the change rather than the answer from before it.
     */
    protected static function booted(): void
    {
        static::saved(static fn () => self::forgetHiddenMemo());
        static::deleted(static fn () => self::forgetHiddenMemo());
    }

    /**
     * Every user this viewer must not see, and who must not see them —
     * whichever side of the block each row was written from.
     *
     * This is the one place the undirected reading of a directed row happens.
     * Callers hand the result to a `whereNotIn('user_id', …)`; an empty array
     * is safe there, because `whereNotIn` against an empty list constrains
     * nothing.
     *
     * **Memoized per request, and it has to be.** `PostPolicy::view()` asks
     * this question once per row — `PostResource` resolves a `can` key for
     * every post in a page — so an uncached read here is a textbook N+1 that
     * scales with page size. The memo is held in the container rather than a
     * static property so it dies with the request (and with each test's
     * application instance) instead of outliving the ids it was keyed on.
     *
     * Not a *cache-store* entry: the invalidation surface would be every block
     * and unblock across both parties, for a two-column indexed read.
     *
     * @return list<int>
     */
    public static function hiddenUserIdsFor(User $viewer): array
    {
        $memo = app()->bound(self::MEMO_KEY) ? app(self::MEMO_KEY) : [];

        // Keyed by organization too: the org context decides which rows the
        // BelongsToOrganization scope even admits, so an answer computed under
        // one tenant is not an answer under another.
        $key = sprintf('%d:%d', app(OrganizationContext::class)->id() ?? 0, $viewer->id);

        if (array_key_exists($key, $memo)) {
            return $memo[$key];
        }

        $memo[$key] = self::query()
            ->where(fn ($query) => $query
                ->where('blocker_id', $viewer->id)
                ->orWhere('blocked_id', $viewer->id))
            ->get(['blocker_id', 'blocked_id'])
            ->flatMap(fn (self $block): array => [$block->blocker_id, $block->blocked_id])
            ->reject(fn (int $id): bool => $id === $viewer->id)
            ->unique()
            ->values()
            ->all();

        app()->instance(self::MEMO_KEY, $memo);

        return $memo[$key];
    }

    /**
     * Whether a block stands between this viewer and another user, in either
     * direction — the single-subject form of `hiddenUserIdsFor()`, and it goes
     * through the same memo rather than issuing its own query.
     */
    public static function isHiddenFrom(User $viewer, int $otherUserId): bool
    {
        return in_array($otherUserId, self::hiddenUserIdsFor($viewer), true);
    }

    public static function forgetHiddenMemo(): void
    {
        app()->instance(self::MEMO_KEY, []);
    }
}
