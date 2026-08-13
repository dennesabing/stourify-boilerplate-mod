<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Services\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Stourify\Models\ExplorerProfile;
use Modules\Stourify\Support\ExplorerUsernameGenerator;
use Tests\Traits\InteractsWithTestSetup;

uses(RefreshDatabase::class, InteractsWithTestSetup::class);

/**
 * The generator hands a brand-new explorer a handle they did not choose, so the
 * bar is not "pretty" — it is that every handle it produces is one the user can
 * later save an edit over. That means satisfying all four of the column's and
 * the form request's rules at once: 3 to 30 characters, lowercase letters,
 * digits, dots and underscores only, and free.
 */
test('a plain name becomes the obvious handle', function (): void {
    expect(ExplorerUsernameGenerator::forName('Grace Wanderer'))->toBe('gracewanderer');
});

test('punctuation and accents are stripped to what the column allows', function (): void {
    expect(ExplorerUsernameGenerator::forName("Renée O'Brien-Smith"))->toBe('reneeobriensmith');
});

test('a taken handle yields the next free number', function (): void {
    ExplorerProfile::factory()->create(['username' => 'gracewanderer']);

    expect(ExplorerUsernameGenerator::forName('Grace Wanderer'))->toBe('gracewanderer2');

    ExplorerProfile::factory()->create(['username' => 'gracewanderer2']);

    expect(ExplorerUsernameGenerator::forName('Grace Wanderer'))->toBe('gracewanderer3');
});

/**
 * A name written entirely in a script the column cannot hold leaves nothing to
 * work with. Returning an empty string there would produce a row the user can
 * never edit, because `min:3` would reject their own current handle.
 */
test('a name with nothing usable in it still yields a valid handle', function (string $name): void {
    expect(ExplorerUsernameGenerator::forName($name))
        ->toMatch('/^[a-z0-9_.]{3,30}$/');
})->with([
    'non-Latin script' => ['のぞみ'],
    'punctuation only' => ['!!! ???'],
    'blank' => ['   '],
    'null' => [''],
]);

test('a two-character name is padded rather than left below the minimum', function (): void {
    expect(ExplorerUsernameGenerator::forName('Jo'))->toMatch('/^[a-z0-9_.]{3,30}$/');
});

/**
 * 30 is the column's width, and the number that resolves a collision has to fit
 * INSIDE it — appending after truncating to 30 is the easy way to produce a
 * value the database then refuses.
 */
test('an over-long name is cut to thirty characters, suffix included', function (): void {
    $long = str_repeat('a', 60);

    $first = ExplorerUsernameGenerator::forName($long);
    expect(strlen($first))->toBe(30);

    ExplorerProfile::factory()->create(['username' => $first]);

    $second = ExplorerUsernameGenerator::forName($long);
    expect(strlen($second))->toBeLessThanOrEqual(30)
        ->and($second)->not->toBe($first);
});

/**
 * `username` is unique across the whole table, but `ExplorerProfile` carries the
 * organization scope — so a scoped lookup can report a handle free when it is
 * taken in a different organization, and the insert then fails on an index the
 * query never consulted.
 */
test('a handle taken in another organization still counts as taken', function (): void {
    $other = Organization::factory()->create();
    ExplorerProfile::factory()->create([
        'organization_id' => $other->id,
        'username' => 'gracewanderer',
    ]);

    app(OrganizationContext::class)->setOrganization(
        Organization::factory()->create(),
    );

    expect(ExplorerUsernameGenerator::forName('Grace Wanderer'))->toBe('gracewanderer2');
});
