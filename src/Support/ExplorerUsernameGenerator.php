<?php

declare(strict_types=1);

namespace Modules\Stourify\Support;

use Illuminate\Support\Str;
use Modules\Stourify\Http\Requests\ProfileUpdateRequest;
use Modules\Stourify\Listeners\JoinPublicOrganizationAsExplorer;
use Modules\Stourify\Models\ExplorerProfile;

/**
 * Hands a brand-new explorer a starter handle derived from their account name.
 *
 * Registration creates the profile before the user has ever been asked what
 * they want to be called (STOURIFY-82), so something has to fill the one column
 * that cannot be left empty. This is the same move a hotel makes when it gives
 * you room 214 on arrival: you did not pick it, it is definitely yours, and you
 * can ask to move.
 *
 * The bar is not that the handle is attractive — it is that the user can later
 * save an edit over it. `sto_explorer_profiles.username` is `NOT NULL`, 30
 * characters wide and unique across the WHOLE table, and `ProfileUpdateRequest`
 * additionally rejects anything outside `^[a-z0-9_.]+$` or shorter than 3. A
 * handle that fails any one of those is a profile whose owner is locked out of
 * their own edit form, so every value this class returns satisfies all four.
 *
 * @see JoinPublicOrganizationAsExplorer
 * @see ProfileUpdateRequest
 */
final class ExplorerUsernameGenerator
{
    /** What a name with nothing usable in it becomes. */
    private const FALLBACK = 'explorer';

    /** `ProfileUpdateRequest`'s `min:3`. */
    private const MIN_LENGTH = 3;

    /** The column's width. */
    private const MAX_LENGTH = 30;

    /**
     * How many numbered candidates to try before giving up on tidiness.
     *
     * Past this it stops counting and reaches for randomness. Counting is nicer
     * to read but costs one query per attempt, so a name shared by hundreds of
     * accounts would otherwise turn one registration into hundreds of lookups.
     */
    private const MAX_NUMBERED_ATTEMPTS = 50;

    /**
     * A free, valid handle for someone whose account name is `$name`.
     *
     * Free *at the moment it is asked*, which is not the same as free when the
     * insert lands — two registrations in the same instant can be handed the
     * same answer. The unique index is what actually decides, and the caller is
     * expected to retry on it; see the listener.
     */
    public static function forName(?string $name): string
    {
        $base = self::normalize($name);

        if (! self::isTaken($base)) {
            return $base;
        }

        for ($n = 2; $n <= self::MAX_NUMBERED_ATTEMPTS; $n++) {
            $candidate = self::withSuffix($base, (string) $n);

            if (! self::isTaken($candidate)) {
                return $candidate;
            }
        }

        return self::random($base);
    }

    /**
     * A handle that avoids a collision by chance rather than by counting.
     *
     * Used both when the numbered candidates are exhausted and when the caller
     * lost the race for one it had already been given.
     */
    public static function random(?string $name = null): string
    {
        return self::withSuffix(self::normalize($name), Str::lower(Str::random(6)));
    }

    /**
     * Reduce a display name to the characters the column accepts.
     *
     * `Str::ascii` first, so "Renée" becomes "Renee" rather than losing the
     * letter entirely — transliterating keeps more of the person's actual name
     * than stripping does.
     */
    private static function normalize(?string $name): string
    {
        $base = preg_replace('/[^a-z0-9_.]/', '', Str::lower(Str::ascii((string) $name))) ?? '';

        // Too short to be a valid handle at all — a name written entirely in a
        // script this column cannot hold leaves nothing behind.
        if (strlen($base) < self::MIN_LENGTH) {
            $base = $base === '' ? self::FALLBACK : $base.self::FALLBACK;
        }

        return substr($base, 0, self::MAX_LENGTH);
    }

    /**
     * Append a suffix, trimming the base so the RESULT fits.
     *
     * Truncating to 30 and then appending is the easy way to build a value the
     * database refuses, which is why the trim happens first.
     */
    private static function withSuffix(string $base, string $suffix): string
    {
        $room = self::MAX_LENGTH - strlen($suffix);

        return substr($base, 0, max(self::MIN_LENGTH, $room)).$suffix;
    }

    /**
     * Deliberately unscoped.
     *
     * `ExplorerProfile` carries `BelongsToOrganization`, whose global scope
     * filters reads to the current tenant — but the `username` index does not:
     * it is platform-wide. A scoped lookup would report a handle free while it
     * is taken in another organization, and the insert would then fail on an
     * index the query never consulted.
     */
    private static function isTaken(string $username): bool
    {
        return ExplorerProfile::query()
            ->withoutGlobalScopes()
            ->where('username', $username)
            ->exists();
    }
}
