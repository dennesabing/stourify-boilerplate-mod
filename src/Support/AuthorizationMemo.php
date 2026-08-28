<?php

declare(strict_types=1);

namespace Modules\Stourify\Support;

use App\Models\User;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * The viewer's permission answers, remembered for the length of one request.
 *
 * Think of a doorman who is asked, for every single item a visitor looks at,
 * whether that visitor is staff. The answer is the same every time and it is
 * not cheap to work out — so he should ask once and remember, not walk to the
 * office and check the staff list again for each item.
 *
 * That is exactly what a feed page was doing. Every row a client receives
 * carries a `can` block — may I edit this, delete it, publish it — and five of
 * a post's six abilities begin by asking whether the viewer is a moderator
 * here. Answering "no" is the expensive direction: the permission library has
 * to gather up every permission of every role the viewer holds before it can
 * be sure the one being asked about is missing. Measured on this project's
 * development rig that gather costs about 13 ms, and a fifteen-row feed page
 * did it around ninety times — **2.7 seconds of a seventeen-second response,
 * with not a single database query involved** (STOURIFY-229).
 *
 * Nothing here changes an answer. It only stops the same question being asked
 * twice while one request is being served, which is the whole window in which
 * the answer is guaranteed not to move.
 *
 * **How a remembered answer stops being used.** Two ways, because access can
 * change in two quite different places and only one of them announces itself:
 *
 * 1. *A grant or revoke on the user.* Nothing announces this — the permission
 *    library does not even clear its own cache for it. So the answer is filed
 *    under the identity of the very lists that get thrown away, and a change
 *    simply makes the old answers unreachable. `key()` explains it properly.
 * 2. *A permission added to or removed from a role.* This one the permission
 *    library does announce, by discarding its own cache, and
 *    `StourifyServiceProvider` listens for that and empties this memo.
 *
 * And the whole thing dies with the request in any case, because it lives in
 * the service container and every request builds a fresh one.
 *
 * **Why not a real cache entry.** Because clearing it would then be somebody's
 * job. A permission change would have to reach across every viewer who had
 * ever been asked about, and getting that wrong shows up as somebody keeping
 * access they were supposed to lose. A memo that only lives for one request
 * has no invalidation problem worth the name. This module already takes the
 * same line for the block list — see `Block::hiddenUserIdsFor()`.
 */
final class AuthorizationMemo
{
    /**
     * Where the answers live for the duration of one request.
     */
    private const MEMO_KEY = 'stourify.authorization.memo';

    /**
     * How many times a question actually reached the permission library.
     *
     * This exists so a test can assert the invariant that matters — that the
     * count does not grow with the number of rows on a page — without a clock
     * in it. A wall-clock threshold on a development machine measures the
     * machine, not the code.
     */
    private const LOOKUP_KEY = 'stourify.authorization.lookups';

    /**
     * Where the grant lists named in a key are kept alive. See `hold()`.
     */
    private const HOLD_KEY = 'stourify.authorization.held';

    /**
     * Does this user hold this permission?
     *
     * A permission that is not in the database at all is a "no", not a crash:
     * a module whose permissions have not been seeded yet must deny rather
     * than explode. That is the behaviour the policies had before this class
     * existed, kept exactly.
     */
    public static function permits(User $user, string $permission): bool
    {
        return self::remember(
            self::key($user, "permission:{$permission}"),
            static function () use ($user, $permission): bool {
                try {
                    return $user->hasPermissionTo($permission);
                } catch (PermissionDoesNotExist) {
                    return false;
                }
            },
        );
    }

    /**
     * Does this user hold at least one of these roles?
     *
     * @param  list<string>  $roles
     */
    public static function holdsAnyRole(User $user, array $roles): bool
    {
        return self::remember(
            self::key($user, 'roles:'.implode(',', $roles)),
            static fn (): bool => $user->hasAnyRole($roles),
        );
    }

    /**
     * Forget everything remembered so far.
     *
     * Called whenever the permission library throws away its own cache, and
     * available to tests that change a user's access mid-test.
     */
    public static function forget(): void
    {
        app()->instance(self::MEMO_KEY, []);
        app()->instance(self::LOOKUP_KEY, 0);
        app()->instance(self::HOLD_KEY, []);
    }

    /**
     * How many questions have actually reached the permission library since
     * the last `forget()`. For tests; see `LOOKUP_KEY`.
     */
    public static function lookups(): int
    {
        return app()->bound(self::LOOKUP_KEY) ? (int) app(self::LOOKUP_KEY) : 0;
    }

    /**
     * The key an answer is filed under — and the part that makes the memo safe.
     *
     * Four things go into it, and each one is a way the answer could otherwise
     * go stale:
     *
     * **The tenant.** Roles and permissions here are per-organization — the
     * permission library is configured with teams, and `setPermissionsTeamId()`
     * selects whose grants are in view. An answer worked out under one
     * organization is not an answer under another, the same reason the tenant
     * is in `Block::hiddenUserIdsFor()`'s key.
     *
     * **The user**, obviously.
     *
     * **Which lists of grants this user is currently carrying.** This is the
     * part that is easy to leave out and expensive to get wrong. Giving a user
     * a permission does *not* disturb the permission library's own cache: it
     * writes one row, throws away the copy of the list the user was holding,
     * and announces nothing. So there is no event to listen for, and a key
     * built only from "which user, which organization" would keep answering
     * with the grants they had a moment ago.
     *
     * What goes in the key is not the contents of those lists but their
     * *identity* — think of a numbered ticket on a bag rather than an
     * inventory of what is inside it. Throwing the list away and fetching a
     * fresh one produces a new bag with a new number, so the old answers can
     * never be matched again. Comparing a number costs nothing; reading a list
     * of fifty permissions costs about a millisecond, and at ninety questions
     * a page that was itself a second of the response this class exists to
     * save. `hold()` below is what makes the number trustworthy.
     *
     * **The question.**
     *
     * The one change this cannot see is a permission being added to or removed
     * from a *role* — the user keeps holding the same list of roles, so the
     * same bag comes back. That path is covered instead by the listener in
     * `StourifyServiceProvider`, because changing a role's permissions **does**
     * make the permission library discard its cache, and that it does announce.
     */
    private static function key(User $user, string $question): string
    {
        $granted = $user->permissions;
        $roles = $user->roles;

        self::hold($granted, $roles);

        return sprintf(
            '%s:%d:%d/%d:%s',
            (string) (getPermissionsTeamId() ?? '0'),
            $user->id,
            spl_object_id($granted),
            spl_object_id($roles),
            $question,
        );
    }

    /**
     * Keep the lists named in a key alive for as long as their answers are.
     *
     * The number `spl_object_id()` hands out is only unique among objects that
     * exist *at the same time* — it is a cloakroom ticket, and the cloakroom
     * reissues a ticket once the coat has been collected. A list thrown away
     * and swept up could therefore lend its number to the replacement list,
     * and every stale answer filed under it would look current again.
     *
     * Keeping a reference to each list stops it being swept up, so its number
     * is never handed out to anything else while an answer is still filed
     * under it. The references die with the request, exactly like the answers.
     */
    private static function hold(object ...$lists): void
    {
        /** @var array<int, object> $held */
        $held = app()->bound(self::HOLD_KEY) ? app(self::HOLD_KEY) : [];
        $added = false;

        foreach ($lists as $list) {
            $id = spl_object_id($list);

            if (! isset($held[$id])) {
                $held[$id] = $list;
                $added = true;
            }
        }

        if ($added) {
            app()->instance(self::HOLD_KEY, $held);
        }
    }

    /**
     * @param  callable(): bool  $answer
     */
    private static function remember(string $key, callable $answer): bool
    {
        /** @var array<string, bool> $memo */
        $memo = app()->bound(self::MEMO_KEY) ? app(self::MEMO_KEY) : [];

        if (array_key_exists($key, $memo)) {
            return $memo[$key];
        }

        $memo[$key] = $answer();

        app()->instance(self::MEMO_KEY, $memo);
        app()->instance(self::LOOKUP_KEY, self::lookups() + 1);

        return $memo[$key];
    }
}
