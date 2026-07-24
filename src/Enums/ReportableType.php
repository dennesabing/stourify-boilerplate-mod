<?php

declare(strict_types=1);

namespace Modules\Stourify\Enums;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Modules\Stourify\Models\Post;
use Modules\Stourify\Models\Review;
use Modules\Stourify\Models\Spot;

/**
 * What a report may be filed against.
 *
 * This is a *reportability* allowlist, deliberately separate from the
 * platform's attachable-morph registry (`AllowedMorph`): being reportable and
 * being an attachment host are different questions — a user is reportable but
 * is not an attachable host, and not every attachable host should be
 * reportable. Reports own their own list.
 *
 * The API speaks in these short tokens (`spot`, `user`) rather than morph
 * aliases or class names, so a client never sees `stourify_spot` — let alone a
 * `Modules\…` FQCN — and the reportable surface can grow without leaking
 * internal identifiers.
 *
 * Comments are intentionally not here yet: comment moderation is its own
 * surface that arrives with the Community work, not part of the M1 report flow.
 */
enum ReportableType: string
{
    case Spot = 'spot';
    case Post = 'post';
    case Review = 'review';
    case User = 'user';

    /**
     * @return class-string<Model>
     */
    public function modelClass(): string
    {
        return match ($this) {
            self::Spot => Spot::class,
            self::Post => Post::class,
            self::Review => Review::class,
            self::User => User::class,
        };
    }

    /**
     * The value stored in `reportable_type` for this kind — the model's morph
     * class, which is a registered alias (`stourify_spot`) for module models
     * and a stable FQCN for the boilerplate's `User`.
     */
    public function morphClass(): string
    {
        $modelClass = $this->modelClass();

        return (new $modelClass)->getMorphClass();
    }

    /**
     * The token for a concrete model instance, or null if its class is not a
     * reportable type. Used to render the subject of a queued report back to a
     * moderator without exposing the stored morph value.
     */
    public static function tryFromModel(Model $model): ?self
    {
        foreach (self::cases() as $case) {
            if ($model instanceof ($case->modelClass())) {
                return $case;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    public static function tokens(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
