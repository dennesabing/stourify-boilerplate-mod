<?php

declare(strict_types=1);

use Modules\Stourify\Support\Hashtags\HashtagParser;

/**
 * The parser's whole rule set, one assertion per rule.
 *
 * It is a pure function of a string, so none of this needs a database, a
 * request or a user — which is the point of keeping it separate from the
 * observer that calls it. Every row here is a decision recorded on
 * STOURIFY-103 — Hash Tagging spots/posts; if one of them looks arbitrary,
 * that card's `spec` says why it was chosen and what lost.
 */
it('finds nothing in text with no hashtag', function (): void {
    expect(HashtagParser::parse('great noodles in Hanoi'))->toBe([]);
});

it('finds nothing in null or empty text', function (): void {
    expect(HashtagParser::parse(null))->toBe([]);
    expect(HashtagParser::parse(''))->toBe([]);
    expect(HashtagParser::parse('   '))->toBe([]);
});

it('reads one hashtag out of a caption', function (): void {
    expect(HashtagParser::parse('great noodles #streetfood'))
        ->toBe(['streetfood' => 'streetfood']);
});

it('reads several, in the order they were written', function (): void {
    expect(array_keys(HashtagParser::parse('#streetfood then #Hanoi and #pho')))
        ->toBe(['streetfood', 'hanoi', 'pho']);
});

it('treats different capitalisations as one tag and keeps the first spelling', function (): void {
    expect(HashtagParser::parse('#StreetFood and #streetfood and #STREETFOOD'))
        ->toBe(['streetfood' => 'StreetFood']);
});

/**
 * The case a lookbehind alone gets wrong. `#food#drink` is what people type,
 * and the naive "a hashtag may not follow a word character" rule kills the
 * second one — because the character before its `#` is the `d` of `food`.
 */
it('reads two tags out of #food#drink', function (): void {
    expect(array_keys(HashtagParser::parse('#food#drink')))->toBe(['food', 'drink']);
});

it('ignores a hash glued to the end of a word', function (): void {
    expect(HashtagParser::parse('I write C# for a living'))->toBe([]);
    expect(HashtagParser::parse('take route#5 north'))->toBe([]);
    expect(HashtagParser::parse('email me at a#b'))->toBe([]);
});

it('ignores a bare hash and a hash followed by punctuation', function (): void {
    expect(HashtagParser::parse('# nothing here'))->toBe([]);
    expect(HashtagParser::parse('#!!! nor here'))->toBe([]);
});

it('stops a tag at the first character that is not a letter, digit or underscore', function (): void {
    expect(HashtagParser::parse('#food. and #drink, and #trip!'))
        ->toBe(['food' => 'food', 'drink' => 'drink', 'trip' => 'trip']);
});

it('refuses an all-digit tag but allows one with a letter in it', function (): void {
    expect(HashtagParser::parse('paid #2026 and #5'))->toBe([]);
    expect(HashtagParser::parse('#a1 and #_x'))
        ->toBe(['a1' => 'a1', '_x' => '_x']);
});

it('keeps letters that are not English, and does not fold accents', function (): void {
    expect(HashtagParser::parse('#café and #cafe and #東京'))
        ->toBe(['café' => 'café', 'cafe' => 'cafe', '東京' => '東京']);
});

it('stops at 64 characters and leaves the rest as ordinary text', function (): void {
    $long = str_repeat('a', 70);

    expect(HashtagParser::parse("#{$long}"))
        ->toBe([str_repeat('a', 64) => str_repeat('a', 64)]);
});

it('keeps at most thirty tags and ignores the rest rather than failing', function (): void {
    $text = collect(range(1, 40))->map(fn (int $n): string => "#tag{$n}")->implode(' ');

    expect(HashtagParser::parse($text))->toHaveCount(30)
        ->and(array_key_first(HashtagParser::parse($text)))->toBe('tag1');
});

it('reads a hashtag that starts the text and one that ends it', function (): void {
    expect(array_keys(HashtagParser::parse('#first middle #last')))
        ->toBe(['first', 'last']);
});

it('reads a hashtag after a newline', function (): void {
    expect(HashtagParser::parse("line one\n#second"))->toBe(['second' => 'second']);
});
