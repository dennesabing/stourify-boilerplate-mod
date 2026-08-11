<?php

declare(strict_types=1);

namespace Modules\Stourify\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Crud\CrudService;
use App\Traits\ApiResponses;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Modules\Stourify\Enums\FollowStatus;
use Modules\Stourify\Enums\SpotStatus;
use Modules\Stourify\Http\Requests\ProfileUpdateRequest;
use Modules\Stourify\Http\Resources\ProfileResource;
use Modules\Stourify\Models\Block;
use Modules\Stourify\Models\City;
use Modules\Stourify\Models\ExplorerProfile;
use Modules\Stourify\Models\Follow;
use Modules\Stourify\Models\Spot;
use Modules\Stourify\Policies\ExplorerProfilePolicy;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Explorer profiles — read/update, username uniqueness, home city, interests.
 *
 * Two read shapes:
 *   - `GET /profile` is the caller's own, and returns `null` data when they
 *     have not created one yet (a freshly registered user mid-onboarding),
 *     rather than 404 — "you have no profile" is a state the client renders,
 *     not an error.
 *   - `GET /profiles/{user}` is anyone's public header. The header is public
 *     even for a private account; privacy gates content, not identity.
 *
 * `PATCH /profile` is an upsert — the same endpoint serves the onboarding
 * create and every later edit — so the client has one write path.
 *
 * The three counts are computed here from indexed aggregates and attached to
 * the model before it reaches the resource; see ProfileResource for why they
 * are not read from the denormalized columns.
 *
 * @see ExplorerProfilePolicy
 */
class ProfileApiController extends Controller
{
    use ApiResponses;

    /**
     * The caller's own profile, or null if they have not set one up.
     */
    public function me(): JsonResponse
    {
        $user = auth()->user();
        $profile = ExplorerProfile::query()->where('user_id', $user->id)->first();

        if ($profile === null) {
            return $this->success(null, 200, 'No profile yet.');
        }

        return $this->success(
            new ProfileResource($this->withViewerState($this->withCounts($profile->load(['user', 'homeCity'])))),
        );
    }

    /**
     * Any explorer's public header, keyed by their user UUID.
     */
    public function show(User $user): JsonResponse
    {
        $this->assertNoBlockBetween($user);

        $profile = ExplorerProfile::query()->where('user_id', $user->id)->firstOrFail();

        $this->authorize('view', $profile);

        return $this->success(
            new ProfileResource($this->withViewerState($this->withCounts($profile->load(['user', 'homeCity'])))),
        );
    }

    /**
     * Create or update the caller's profile.
     */
    public function update(ProfileUpdateRequest $request): JsonResponse
    {
        $data = $request->validated();
        $existing = $request->currentProfile();

        if (array_key_exists('username', $data)) {
            $data['username'] = Str::lower($data['username']);
        }

        if (array_key_exists('home_city_uuid', $data)) {
            $data['home_city_id'] = $this->resolveCityId($data['home_city_uuid']);
            unset($data['home_city_uuid']);
        }

        if ($existing === null) {
            $profile = CrudService::for(ExplorerProfile::class)->create([
                ...$data,
                'user_id' => $request->user()->id,
            ]);
            $status = 201;
            $message = 'Profile created.';
        } else {
            $this->authorize('update', $existing);
            $profile = CrudService::for(ExplorerProfile::class)->update($existing, $data);
            $status = 200;
            $message = 'Profile updated.';
        }

        return $this->success(
            new ProfileResource($this->withViewerState($this->withCounts($profile->load(['user', 'homeCity'])))),
            $status,
            $message,
        );
    }

    /**
     * Refuse the header when a block stands between the caller and its subject.
     *
     * One message, one status, thrown from one place — deliberately identical
     * whichever side of the block the caller is on. If the blocked party got a
     * different response from the blocker, the block would announce itself,
     * and the whole point is that they are never told. The wording names
     * neither party nor the word "block", so a 403 here is indistinguishable
     * from any other unavailable profile.
     *
     * Checked ahead of `firstOrFail()` so a blocked pair cannot use the
     * 403-vs-404 difference to learn whether the other has a profile at all.
     */
    private function assertNoBlockBetween(User $subject): void
    {
        if (Block::isHiddenFrom(auth()->user(), $subject->id)) {
            throw new AccessDeniedHttpException('This profile is not available.');
        }
    }

    /**
     * Attach the three header counts as computed attributes.
     *
     * Each is a single indexed aggregate: followers and following off
     * `sto_follows` (active only — a pending request is not a follower), spots
     * off the published spots the explorer contributed.
     */
    private function withCounts(ExplorerProfile $profile): ExplorerProfile
    {
        $userId = $profile->user_id;

        $profile->spots_computed = Spot::query()
            ->where('user_id', $userId)
            ->whereIn('status', SpotStatus::discoverable())
            ->count();

        $profile->followers_computed = Follow::query()
            ->where('followee_id', $userId)
            ->where('status', FollowStatus::Active->value)
            ->count();

        $profile->following_computed = Follow::query()
            ->where('follower_id', $userId)
            ->where('status', FollowStatus::Active->value)
            ->count();

        return $profile;
    }

    /**
     * Attach the caller's own outgoing edge to this profile's subject.
     *
     * One indexed lookup on the `(follower_id, followee_id)` unique index, and
     * skipped entirely when the caller is the subject — you cannot follow
     * yourself, so the query would always be empty.
     *
     * Deliberately the caller's OUTGOING edge only. The reverse edge ("they
     * follow me") is a different fact and belongs to the followers list; a
     * Follow button that read it would offer to unfollow someone the caller
     * never followed.
     */
    private function withViewerState(ExplorerProfile $profile): ExplorerProfile
    {
        $viewer = auth()->user();

        $edge = $viewer === null || $viewer->id === $profile->user_id
            ? null
            : Follow::query()
                ->where('follower_id', $viewer->id)
                ->where('followee_id', $profile->user_id)
                ->first();

        $profile->viewer_follow_status = $edge?->status->value;
        $profile->viewer_follow_uuid = $edge?->uuid;

        return $profile;
    }

    private function resolveCityId(?string $cityUuid): ?int
    {
        if ($cityUuid === null) {
            return null;
        }

        return City::query()->where('uuid', $cityUuid)->value('id');
    }
}
