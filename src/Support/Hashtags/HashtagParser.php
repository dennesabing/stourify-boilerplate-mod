<?php

declare(strict_types=1);

namespace Modules\Stourify\Support\Hashtags;

/**
 * Reads the hashtags out of a piece of text.
 *
 * A pure function of a string: no database, no request, no user. That is
 * deliberate — every rule below is a decision made on
 * STOURIFY-103 — Hash Tagging spots/posts, and keeping them here means each
 * one is a two-line test rather than a fixture.
 *
 * ## The rules, and the two that are not obvious
 *
 * A tag is `#` followed by 1 to {@see self::MAX_LENGTH} characters drawn from
 * letters, digits and `_`. Anything else ends it, so `#food.` is `food`.
 * Letters from any script count — this is a travel app, and refusing `#東京`
 * would make the feature useless outside English.
 *
 * **A hash glued to the end of a word is not a tag.** `C#` and `route#5` are
 * not tags, so a match whose preceding character is itself a letter, digit or
 * underscore is thrown away.
 *
 * **Except when that character was the tail of the tag before it.**
 * `#food#drink` is two tags, which is what people actually type. Written as a
 * lookbehind, the rule above kills the second one — the character before its
 * `#` is the `d` of `food`. So matches are collected with their byte offsets
 * and one is accepted when the character before it is not a word character
 * **or** the previous accepted match ended exactly where this one starts.
 * A regular expression cannot express that on its own, which is why this is a
 * loop rather than one clever pattern.
 *
 * ## Why the key is not `Str::slug()`
 *
 * The platform's admin tag endpoint slugs with `Str::slug()`, which is right
 * for what it does and wrong here: it strips accents and drops anything
 * outside the Latin alphabet, so `#東京` would slug to the empty string and
 * `#café` would become `cafe` — merging two words the parent card decided must
 * stay apart. A hashtag's key is `mb_strtolower()` of the word and nothing
 * more.
 */
final class HashtagParser
{
    /**
     * The `type` every tag minted from a hashtag carries, so a user-typed tag
     * stays distinguishable from one an administrator created in the admin
     * panel. They share the `tags` table; they are not the same thing.
     */
    public const TAG_TYPE = 'hashtag';

    /**
     * Characters after the `#`, at most. A longer run is not an error — the
     * match simply stops here and the remainder stays ordinary text.
     */
    private const MAX_LENGTH = 64;

    /**
     * Tags kept per piece of text. Beyond this they are ignored rather than
     * refused: an author's caption is never rejected over this.
     */
    private const MAX_TAGS = 30;

    /**
     * Read the hashtags out of `$text`.
     *
     * @return array<string, string> lowercased slug => the spelling as first
     *                               written, in the order they appear
     */
    public static function parse(?string $text): array
    {
        if ($text === null || trim($text) === '') {
            return [];
        }

        $pattern = '/#([\p{L}\p{N}_]{1,'.self::MAX_LENGTH.'})/u';

        if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE) === 0) {
            return [];
        }

        $tags = [];
        $previousEnd = null;

        foreach ($matches[1] as $index => [$word, $wordOffset]) {
            [, $hashOffset] = $matches[0][$index];

            if (! self::startsHere($text, $hashOffset, $previousEnd)) {
                continue;
            }

            $previousEnd = $wordOffset + strlen($word);

            // A tag needs a letter or an underscore in it, so prices, room
            // numbers and years stay ordinary text.
            if (preg_match('/[\p{L}_]/u', $word) !== 1) {
                continue;
            }

            $slug = mb_strtolower($word);

            // First spelling wins: whoever wrote `#StreetFood` first decides
            // how the tag reads, and everybody after them joins that same tag.
            if (! array_key_exists($slug, $tags)) {
                $tags[$slug] = $word;
            }

            if (count($tags) === self::MAX_TAGS) {
                break;
            }
        }

        return $tags;
    }

    /**
     * May a tag begin at this `#`?
     *
     * Yes when nothing precedes it, when what precedes it is not part of a
     * word, or when the tag before it ended on this very character — the
     * `#food#drink` case. See the class docblock.
     */
    private static function startsHere(string $text, int $hashOffset, ?int $previousEnd): bool
    {
        if ($hashOffset === 0 || $previousEnd === $hashOffset) {
            return true;
        }

        // One UTF-8 character back, not one byte: the character before the `#`
        // may be multi-byte, and reading a single byte of it would compare a
        // continuation byte against a letter class and always say "not a word".
        $before = mb_substr(substr($text, 0, $hashOffset), -1);

        return preg_match('/[\p{L}\p{N}_]/u', $before) !== 1;
    }
}
