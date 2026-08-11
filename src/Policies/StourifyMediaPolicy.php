<?php

declare(strict_types=1);

namespace Modules\Stourify\Policies;

use App\Models\User;
use App\Policies\MediaPolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Modules\Stourify\Models\Post;
use Modules\Stourify\Models\Spot;

/**
 * Scopes media *creation* on this module's photo hosts to people who may
 * actually write those hosts.
 *
 * The platform resolves an attachable's permission as
 * `{host::permissionPrefix()}.media.create` — `posts.media.create` for a post,
 * `spots.media.create` for a spot — and a role grant is not scoped to a host
 * instance. Granting it to `explorer` therefore says "explorers may attach
 * media to posts", never "only to their own": App\Policies\MediaPolicy::create()
 * checks the override tier, then that the host is non-null, then that the user
 * holds the permission. It never consults the host's owner, and it is not the
 * place to teach it to — App\Policies\Concerns\AttachablePolicy is generic
 * platform code consumed by other projects, and every attachable would inherit
 * the new rule (STOURIFY-22).
 *
 * So the module supplies the missing half for its own hosts: media rights
 * follow host write rights. The test is the host's own `update` ability rather
 * than an inline `user_id` comparison, which keeps the rule in
 * Modules\Stourify\Policies\PostPolicy and SpotPolicy where it already lives —
 * including their moderator tier, which must keep working — instead of forking
 * a second copy of it that can drift.
 *
 * A host this module does not own falls straight through to the parent, so
 * nothing outside Stourify changes behaviour.
 */
class StourifyMediaPolicy extends MediaPolicy
{
    /**
     * Determine whether the user can attach media to the given host.
     */
    public function create(User $user, ?Model $host = null): bool
    {
        if (($host instanceof Post || $host instanceof Spot)
            && ! Gate::forUser($user)->allows('update', $host)) {
            return false;
        }

        return parent::create($user, $host);
    }
}
