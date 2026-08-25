<?php

declare(strict_types=1);

namespace Modules\Stourify\Http\Controllers\Api\V1;

use App\Exceptions\CrudAuthorizationException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Crud\CrudService;
use App\Services\OrganizationContext;
use App\Traits\ApiResponses;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Modules\Stourify\Enums\FollowStatus;
use Modules\Stourify\Enums\SpotStatus;
use Modules\Stourify\Http\Requests\FollowStoreRequest;
use Modules\Stourify\Http\Requests\ProfileUpdateRequest;
use Modules\Stourify\Http\Requests\ReviewStoreRequest;
use Modules\Stourify\Http\Requests\ReviewUpdateRequest;
use Modules\Stourify\Http\Requests\SpotStoreRequest;
use Modules\Stourify\Http\Requests\SpotUpdateRequest;
use Modules\Stourify\Http\Requests\SyncPushRequest;
use Modules\Stourify\Http\Requests\WishlistStoreRequest;
use Modules\Stourify\Http\Requests\WishlistUpdateRequest;
use Modules\Stourify\Models\City;
use Modules\Stourify\Models\ExplorerProfile;
use Modules\Stourify\Models\Follow;
use Modules\Stourify\Models\Review;
use Modules\Stourify\Models\Spot;
use Modules\Stourify\Models\SyncTombstone;
use Modules\Stourify\Models\WishlistItem;
use Modules\Stourify\Support\Sync\SyncRegistry;
use Modules\Stourify\Support\Sync\SyncSerializer;
use Throwable;

/**
 * The offline-sync spine's server half — `delta` (pull) and `push` (drain)
 * for the mobile WatermelonDB client. See
 * docs/superpowers/specs/2026-07-25-m2a-backend-sync-contract-design.md.
 *
 * `delta()` is deliberately **not** read through `getCachedList()`. Every
 * other read endpoint in this module caches because many viewers issue the
 * identical request; a delta is the opposite — it is keyed by the caller
 * *and* an ever-advancing `since` cursor, so a cache entry is used at most
 * once and would only add memory pressure. The feed controller documents the
 * same exception for the same reason.
 *
 * `push()` authorizes every operation through the *same* policies a single
 * `POST /api/v1/spots` (etc.) call would — via `CrudService` — so sync is not
 * a bypass. See `pushUpsert()`.
 */
class SyncController extends Controller
{
    use ApiResponses;

    public function delta(Request $request): JsonResponse
    {
        $user = $request->user();
        $serverTime = now()->toIso8601String();

        $sinceAt = null;
        $since = $request->query('since');

        if (is_string($since) && $since !== '') {
            try {
                $sinceAt = Carbon::parse($since);
            } catch (Throwable) {
                return response()->json([
                    'message' => 'The since parameter must be a valid ISO8601 timestamp.',
                ], 422);
            }
        }

        $payload = [];

        foreach (SyncRegistry::tables() as $table) {
            $payload[$table] = $this->tableDelta($table, $user, $sinceAt);
        }

        $payload['server_time'] = $serverTime;

        return response()->json($payload);
    }

    public function push(SyncPushRequest $request): JsonResponse
    {
        $user = $request->user();
        $payload = $request->validated();
        $results = [];

        foreach (SyncRegistry::tables() as $table) {
            if (! SyncRegistry::isPushable($table) || ! isset($payload[$table])) {
                continue;
            }

            $tableOps = $payload[$table];

            foreach ((array) ($tableOps['created'] ?? []) as $row) {
                $results[] = $this->pushUpsert($table, (array) $row, $user, 'created');
            }

            foreach ((array) ($tableOps['updated'] ?? []) as $row) {
                $results[] = $this->pushUpsert($table, (array) $row, $user, 'updated');
            }

            foreach ((array) ($tableOps['deleted'] ?? []) as $uuid) {
                $results[] = $this->pushDelete($table, $uuid, $user);
            }
        }

        return $this->success([
            'results' => $results,
            'server_time' => now()->toIso8601String(),
        ]);
    }

    // -------------------------------------------------------------------------
    // Delta
    // -------------------------------------------------------------------------

    /**
     * @return array{created: list<array<string, mixed>>, updated: list<array<string, mixed>>, deleted: list<string>}
     */
    private function tableDelta(string $table, User $user, ?Carbon $since): array
    {
        $modelClass = SyncRegistry::model($table);

        if ($since === null) {
            $created = SyncRegistry::scope($table, $modelClass::query()->with(SyncRegistry::eagerLoad($table)), $user)->get();

            return [
                'created' => $this->serializeAll($table, $created),
                'updated' => [],
                'deleted' => [],
            ];
        }

        $created = SyncRegistry::scope($table, $modelClass::query()->with(SyncRegistry::eagerLoad($table)), $user)
            ->where('created_at', '>', $since)
            ->get();

        $updated = SyncRegistry::scope($table, $modelClass::query()->with(SyncRegistry::eagerLoad($table)), $user)
            ->where('updated_at', '>', $since)
            ->where('created_at', '<=', $since)
            ->get();

        $tombstones = SyncTombstone::query()
            ->where('entity_type', $modelClass::morphAlias())
            ->where('organization_id', app(OrganizationContext::class)->id());

        SyncRegistry::tombstoneScope($table, $tombstones, $user);

        $deleted = $tombstones->where('deleted_at', '>', $since)->pluck('entity_uuid')->all();

        return [
            'created' => $this->serializeAll($table, $created),
            'updated' => $this->serializeAll($table, $updated),
            'deleted' => $deleted,
        ];
    }

    /**
     * @param  Collection<int, Model>  $models
     * @return list<array<string, mixed>>
     */
    private function serializeAll(string $table, Collection $models): array
    {
        return $models->map(fn (Model $model): array => SyncSerializer::forTable($table, $model))->values()->all();
    }

    // -------------------------------------------------------------------------
    // Push — create/update
    // -------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function pushUpsert(string $table, array $row, User $user, string $op): array
    {
        $uuid = is_string($row['uuid'] ?? null) && $row['uuid'] !== '' ? $row['uuid'] : null;

        if ($uuid === null) {
            return $this->rejected($table, null, $op, 'validation', ['uuid' => ['A uuid is required.']]);
        }

        try {
            return match ($table) {
                'sto_spots' => $this->pushSpot($row, $uuid, $user, $op),
                'sto_reviews' => $this->pushReview($row, $uuid, $user, $op),
                'sto_wishlist_items' => $this->pushWishlistItem($row, $uuid, $user, $op),
                'sto_follows' => $this->pushFollow($row, $uuid, $user, $op),
                'sto_explorer_profiles' => $this->pushExplorerProfile($row, $uuid, $user, $op),
                default => $this->rejected($table, $uuid, $op, 'unsupported', []),
            };
        } catch (CrudAuthorizationException $e) {
            return $this->rejected($table, $uuid, $op, 'forbidden', ['authorization' => [$e->getMessage()]]);
        } catch (Throwable $e) {
            // One row's failure must not abort the rest of the batch — see
            // spec §4. A constraint violation (e.g. a duplicate review, a
            // self-follow the manual Validator did not catch — see the
            // module CHANGELOG's "deferred cross-field checks" note) lands
            // here rather than as a 500 for the whole push.
            return $this->rejected($table, $uuid, $op, 'error', ['exception' => [$e->getMessage()]]);
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function pushSpot(array $row, string $uuid, User $user, string $op): array
    {
        $existing = Spot::query()->where('uuid', $uuid)->where('user_id', $user->id)->first();

        $rules = $existing === null ? (new SpotStoreRequest)->rules() : (new SpotUpdateRequest)->rules();
        $validator = Validator::make($row, $rules);

        if ($validator->fails()) {
            return $this->rejected('sto_spots', $uuid, $op, 'validation', $validator->errors()->toArray());
        }

        $data = $this->resolveCityUuid($validator->validated());
        $data['user_id'] = $user->id;

        if ($existing === null) {
            $data['slug'] = $this->uniqueSpotSlug((string) ($data['title'] ?? 'spot'));
            $data['status'] = $data['status'] ?? SpotStatus::Draft->value;

            $model = $this->keyByClientUuid(CrudService::for(Spot::class)->create($data), $uuid);
        } else {
            $model = CrudService::for(Spot::class)->update($existing, $data);
        }

        return $this->ok('sto_spots', $uuid, $op, SyncSerializer::forTable('sto_spots', $model));
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function pushReview(array $row, string $uuid, User $user, string $op): array
    {
        $existing = Review::query()->where('uuid', $uuid)->where('user_id', $user->id)->first();

        $rules = $existing === null ? (new ReviewStoreRequest)->rules() : (new ReviewUpdateRequest)->rules();
        $validator = Validator::make($row, $rules);

        if ($validator->fails()) {
            return $this->rejected('sto_reviews', $uuid, $op, 'validation', $validator->errors()->toArray());
        }

        $data = $validator->validated();

        if ($existing === null) {
            $spotId = Spot::query()->where('uuid', $data['spot_uuid'])->value('id');

            if ($spotId === null) {
                return $this->rejected('sto_reviews', $uuid, $op, 'validation', ['spot_uuid' => ['That spot does not exist.']]);
            }

            $model = $this->keyByClientUuid(CrudService::for(Review::class)->create([
                'spot_id' => $spotId,
                'user_id' => $user->id,
                'rating' => $data['rating'],
                'body' => $data['body'] ?? null,
            ]), $uuid);
        } else {
            $model = CrudService::for(Review::class)->update($existing, $data);
        }

        return $this->ok('sto_reviews', $uuid, $op, SyncSerializer::forTable('sto_reviews', $model));
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function pushWishlistItem(array $row, string $uuid, User $user, string $op): array
    {
        $existing = WishlistItem::query()->where('uuid', $uuid)->where('user_id', $user->id)->first();

        $rules = $existing === null ? (new WishlistStoreRequest)->rules() : (new WishlistUpdateRequest)->rules();
        $validator = Validator::make($row, $rules);

        if ($validator->fails()) {
            return $this->rejected('sto_wishlist_items', $uuid, $op, 'validation', $validator->errors()->toArray());
        }

        $data = $validator->validated();

        if ($existing === null) {
            $spot = Spot::query()->where('uuid', $data['spot_uuid'])->first();

            if ($spot === null) {
                return $this->rejected('sto_wishlist_items', $uuid, $op, 'validation', ['spot_uuid' => ['That spot does not exist.']]);
            }

            $model = $this->keyByClientUuid(CrudService::for(WishlistItem::class)->create([
                'user_id' => $user->id,
                'spot_id' => $spot->id,
                'city_id' => $spot->city_id,
                'note' => $data['note'] ?? null,
                'is_downloaded_offline' => $data['is_downloaded_offline'] ?? false,
            ]), $uuid);
        } else {
            $model = CrudService::for(WishlistItem::class)->update($existing, $data);
        }

        return $this->ok('sto_wishlist_items', $uuid, $op, SyncSerializer::forTable('sto_wishlist_items', $model));
    }

    /**
     * Follows have exactly one legal client-driven mutation — create (and its
     * mirror, delete). Accepting a pending request is its own gated action
     * (`FollowApiController::accept()`), never a general field update, so a
     * push `updated` op for this table is rejected rather than silently
     * accepted — see the module CHANGELOG.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function pushFollow(array $row, string $uuid, User $user, string $op): array
    {
        if ($op === 'updated') {
            return $this->rejected('sto_follows', $uuid, $op, 'unsupported', [
                'op' => ['A follow has no editable fields; accept it through its own endpoint instead.'],
            ]);
        }

        $existing = Follow::query()->where('uuid', $uuid)
            ->where(fn ($edge) => $edge->where('follower_id', $user->id)->orWhere('followee_id', $user->id))
            ->first();

        if ($existing !== null) {
            // Idempotent replay of the same create — the headline
            // "unduplicated" guarantee, spec §4 point 3.
            return $this->ok('sto_follows', $uuid, $op, SyncSerializer::forTable('sto_follows', $existing));
        }

        $validator = Validator::make($row, (new FollowStoreRequest)->rules());

        if ($validator->fails()) {
            return $this->rejected('sto_follows', $uuid, $op, 'validation', $validator->errors()->toArray());
        }

        $data = $validator->validated();
        $targetId = User::query()->where('uuid', $data['user_uuid'])->value('id');

        if ($targetId === null) {
            return $this->rejected('sto_follows', $uuid, $op, 'validation', ['user_uuid' => ['That explorer does not exist.']]);
        }

        if ((int) $targetId === $user->id) {
            return $this->rejected('sto_follows', $uuid, $op, 'validation', ['user_uuid' => ['You cannot follow yourself.']]);
        }

        // Mirrors `AttachesExplorerProfiles::isPrivateAccount()`: an absent
        // profile means a public account, the permissive default.
        $isPrivate = (bool) ExplorerProfile::query()->where('user_id', $targetId)->value('is_private');

        $model = $this->keyByClientUuid(CrudService::for(Follow::class)->create([
            'follower_id' => $user->id,
            'followee_id' => $targetId,
            'status' => $isPrivate ? FollowStatus::Pending->value : FollowStatus::Active->value,
            'accepted_at' => $isPrivate ? null : now(),
        ]), $uuid);

        return $this->ok('sto_follows', $uuid, $op, SyncSerializer::forTable('sto_follows', $model));
    }

    /**
     * The explorer profile is an upsert keyed by `user_id` in the domain
     * (`ProfileApiController::update()`), but the push contract resolves by
     * `uuid` like every other table — the two agree except for the rare case
     * of a caller's very first sync racing a profile created under a
     * different local uuid, guarded against below.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function pushExplorerProfile(array $row, string $uuid, User $user, string $op): array
    {
        $existing = ExplorerProfile::query()->where('uuid', $uuid)->where('user_id', $user->id)->first();

        $formRequest = ProfileUpdateRequest::create('/', 'PATCH', $row);
        $formRequest->setUserResolver(fn () => $user);

        $validator = Validator::make($row, $formRequest->rules(), $formRequest->messages());

        if ($validator->fails()) {
            return $this->rejected('sto_explorer_profiles', $uuid, $op, 'validation', $validator->errors()->toArray());
        }

        $data = $validator->validated();

        if (array_key_exists('username', $data)) {
            $data['username'] = Str::lower($data['username']);
        }

        if (array_key_exists('home_city_uuid', $data)) {
            $data['home_city_id'] = $data['home_city_uuid'] === null
                ? null
                : City::query()->where('uuid', $data['home_city_uuid'])->value('id');
            unset($data['home_city_uuid']);
        }

        if ($existing === null) {
            $alreadyHasProfile = ExplorerProfile::query()->where('user_id', $user->id)->exists();

            if ($alreadyHasProfile) {
                return $this->rejected('sto_explorer_profiles', $uuid, $op, 'conflict', [
                    'uuid' => ['You already have a profile under a different id.'],
                ]);
            }

            $model = $this->keyByClientUuid(CrudService::for(ExplorerProfile::class)->create([
                ...$data,
                'user_id' => $user->id,
            ]), $uuid);
        } else {
            $model = CrudService::for(ExplorerProfile::class)->update($existing, $data);
        }

        return $this->ok('sto_explorer_profiles', $uuid, $op, SyncSerializer::forTable('sto_explorer_profiles', $model));
    }

    // -------------------------------------------------------------------------
    // Push — delete
    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function pushDelete(string $table, mixed $uuidValue, User $user): array
    {
        $uuid = is_string($uuidValue) && $uuidValue !== '' ? $uuidValue : null;

        if ($uuid === null) {
            return $this->rejected($table, null, 'deleted', 'validation', ['uuid' => ['A uuid is required.']]);
        }

        $modelClass = SyncRegistry::model($table);

        try {
            $model = SyncRegistry::scope($table, $modelClass::query()->with(SyncRegistry::eagerLoad($table)), $user)->where('uuid', $uuid)->first();

            if ($model === null) {
                // Already gone — deleting is idempotent, spec §4 point 2.
                return $this->ok($table, $uuid, 'deleted', ['uuid' => $uuid]);
            }

            CrudService::for($modelClass)->delete($model);

            return $this->ok($table, $uuid, 'deleted', ['uuid' => $uuid]);
        } catch (CrudAuthorizationException $e) {
            return $this->rejected($table, $uuid, 'deleted', 'forbidden', ['authorization' => [$e->getMessage()]]);
        } catch (Throwable $e) {
            return $this->rejected($table, $uuid, 'deleted', 'error', ['exception' => [$e->getMessage()]]);
        }
    }

    // -------------------------------------------------------------------------
    // Shared helpers
    // -------------------------------------------------------------------------

    /**
     * Force the client-generated `uuid` onto a just-created row.
     *
     * `HasUuid::bootHasUuid()` only fills a *blank* `uuid` on `creating`, and
     * no synced model's `$fillable` lists `uuid` (accepting it from a normal
     * API write would let any caller pick their own record's identity) — so
     * `CrudService::create()` always mints a fresh one. The push contract's
     * uuid-keyed idempotency depends on the row being keyed by the *client's*
     * uuid instead, so it is force-set immediately after, outside mass
     * assignment.
     */
    private function keyByClientUuid(Model $model, string $uuid): Model
    {
        $model->forceFill(['uuid' => $uuid])->save();

        return $model->refresh();
    }

    /**
     * Swap `city_uuid` for the internal `city_id` FK — the same relation
     * resolution `SpotApiController::resolveRelations()` performs for a
     * single-record write.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function resolveCityUuid(array $data): array
    {
        if (! array_key_exists('city_uuid', $data)) {
            return $data;
        }

        $cityUuid = $data['city_uuid'];
        unset($data['city_uuid']);

        $data['city_id'] = $cityUuid === null ? null : City::query()->where('uuid', $cityUuid)->value('id');

        return $data;
    }

    /**
     * Mirrors `SpotApiController::uniqueSlug()` — routes bind on uuid, so a
     * title collision across two pushed spots gets a numeric suffix rather
     * than a constraint violation.
     */
    private function uniqueSpotSlug(string $title): string
    {
        $base = Str::slug($title) ?: 'spot';
        $slug = $base;
        $suffix = 1;

        while (Spot::withTrashed()->where('slug', $slug)->exists()) {
            $slug = "{$base}-".++$suffix;
        }

        return $slug;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function ok(string $table, string $uuid, string $op, array $record): array
    {
        return [
            'table' => $table,
            'uuid' => $uuid,
            'op' => $op,
            'status' => 'ok',
            'record' => $record,
        ];
    }

    /**
     * @param  array<string, mixed>  $errors
     * @return array<string, mixed>
     */
    private function rejected(string $table, ?string $uuid, string $op, string $reason, array $errors): array
    {
        return [
            'table' => $table,
            'uuid' => $uuid,
            'op' => $op,
            'status' => 'rejected',
            'reason' => $reason,
            'errors' => $errors,
        ];
    }
}
