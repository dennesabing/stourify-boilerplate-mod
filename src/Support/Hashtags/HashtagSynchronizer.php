<?php

declare(strict_types=1);

namespace Modules\Stourify\Support\Hashtags;

use App\Models\Tag;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\UniqueConstraintViolationException;
use Modules\Stourify\Observers\SyncTombstoneObserver;

/**
 * Puts the hashtags found in a record's text onto that record.
 *
 * {@see HashtagParser} decides what the words are; this decides which rows
 * they correspond to and what changes on the join table.
 *
 * ## Two decisions worth reading before changing anything here
 *
 * **Tags are minted with a plain `create()`, not through `CrudService`.** That
 * is a deliberate departure from the platform's write rule and the reasoning is
 * on STOURIFY-103 — Hash Tagging spots/posts: `CrudService::create()`
 * authorises `tags.create`, an organisation-admin permission no ordinary
 * explorer holds, so routing this through it would make hashtags silently
 * disappear for every normal user. There is no separate intent to authorise
 * either — the author's intent was to write the post, and that was already
 * authorised before this code runs. {@see SyncTombstoneObserver}
 * documents the same exception for the same kind of side-effect write.
 *
 * **The difference is applied, never `sync()`.** `sync()` detaches everything
 * not in the list, which would strip a tag an administrator attached from the
 * admin panel — somebody else's data, destroyed by an author fixing a typo. So
 * only tags of type {@see HashtagParser::TAG_TYPE} are added and removed, and
 * everything else on the record is left alone.
 */
final class HashtagSynchronizer
{
    /**
     * Recompute `$record`'s hashtags from `$text` and apply the difference.
     */
    public function sync(Model $record, ?string $text): void
    {
        $organizationId = $record->getAttribute('organization_id');

        if ($organizationId === null) {
            return;
        }

        $desired = [];

        foreach (HashtagParser::parse($text) as $slug => $name) {
            $desired[] = $this->tagId((int) $organizationId, $slug, $name);
        }

        /** @var MorphToMany<Tag, Model> $relation */
        $relation = $record->tags();

        $current = $relation->newQuery()
            ->where('tags.type', HashtagParser::TAG_TYPE)
            ->pluck('tags.id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $remove = array_diff($current, $desired);
        $add = array_diff($desired, $current);

        if ($remove !== []) {
            $relation->detach(array_values($remove));
        }

        if ($add !== []) {
            $relation->attach(array_values($add));
        }
    }

    /**
     * The id of this organisation's hashtag with that slug, creating it if this
     * is the first time anybody has written it.
     *
     * The `tags` table carries a unique index on
     * `(organization_id, slug, type)`, and that index — not the lookup above it
     * — is what actually holds the guarantee. Two authors writing the same new
     * word at the same instant both read nothing and both insert; one loses,
     * catches the violation and re-reads the winner's row. A check followed by
     * an insert is a race no amount of checking closes. Same pattern, same
     * reason, as `PostApiController::store()`'s idempotency handling.
     */
    private function tagId(int $organizationId, string $slug, string $name): int
    {
        $existing = $this->find($organizationId, $slug);

        if ($existing !== null) {
            return $existing;
        }

        try {
            return (int) Tag::query()->create([
                'organization_id' => $organizationId,
                'name' => $name,
                'slug' => $slug,
                'type' => HashtagParser::TAG_TYPE,
            ])->getKey();
        } catch (UniqueConstraintViolationException $raced) {
            $winner = $this->find($organizationId, $slug);

            // A violation that does not resolve to our row came from something
            // else entirely, and reporting it as success would hide it.
            if ($winner === null) {
                throw $raced;
            }

            return $winner;
        }
    }

    /**
     * The organisation's global scope is bypassed on purpose: this runs inside
     * a model event, which can fire from a console command or a seeder where no
     * organisation context is set at all. The scope is replaced by an explicit
     * `organization_id` here, so the query is right in every caller rather than
     * only in an HTTP request.
     */
    private function find(int $organizationId, string $slug): ?int
    {
        $id = Tag::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)
            ->where('slug', $slug)
            ->where('type', HashtagParser::TAG_TYPE)
            ->value('id');

        return $id === null ? null : (int) $id;
    }
}
