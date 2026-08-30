<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Stourify\Enums\SpotStatus;
use Modules\Stourify\Models\Review;
use Modules\Stourify\Models\Spot;
use Modules\Stourify\Policies\Concerns\ChecksModeratorAccess;
use Modules\Stourify\Support\AuthorizationMemo;
use Tests\Traits\InteractsWithTestSetup;

uses(RefreshDatabase::class, InteractsWithTestSetup::class);

/**
 * @var list<string>
 */
const COST_REVIEWER_PERMISSIONS = [
    'stourify.reviews.view',
    'stourify.reviews.create',
    'stourify.reviews.update',
    'stourify.reviews.delete',
];

beforeEach(function (): void {
    $this->organization = $this->setUpTestOrganization();
    $this->seedPermissions([...COST_REVIEWER_PERMISSIONS, 'stourify.reviews.manage']);

    $this->viewer = $this->createUserWithPermissions($this->organization, COST_REVIEWER_PERMISSIONS);

    $this->spot = Spot::factory()->for($this->organization)->create([
        'user_id' => $this->viewer->id,
        'status' => SpotStatus::Published,
    ]);
});

/**
 * The invariant this card exists to establish: a longer page must not cost
 * more thinking.
 *
 * Every row of a reviews page carries five abilities, and four of them begin
 * by asking whether the viewer may override the ownership rule. That answer
 * cannot change while a single request is being served, so asking once is
 * enough — and a count is what proves it is asked once rather than once per
 * row.
 *
 * Nothing here is timed, on purpose. A wall-clock threshold on a development
 * machine measures the machine; a count of questions is the same number
 * everywhere, which is what makes this a test rather than a mood.
 */
test('this module\'s policies consult the permission library a fixed number of times however many rows a reviews page returns', function (): void {
    Sanctum::actingAs($this->viewer);

    $lookupsFor = function (int $rows): int {
        Review::query()->forceDelete();
        Review::factory()->count($rows)->for($this->organization)->create([
            'spot_id' => $this->spot->id,
        ]);

        AuthorizationMemo::forget();

        $this->getJson(
            "/api/v1/reviews?spot_uuid={$this->spot->uuid}&per_page=25",
            orgHeader($this->organization)
        )->assertOk()->assertJsonCount($rows, 'data');

        return AuthorizationMemo::lookups();
    };

    $threeRows = $lookupsFor(3);
    $fifteenRows = $lookupsFor(15);

    // Two assertions, and the first is not padding. Zero is also flat, and
    // zero is exactly what you get if the policies stop going through the memo
    // at all — so without this the test would keep passing over the very
    // regression it exists to catch.
    expect($threeRows)->toBeGreaterThan(0,
        'The reviews page asked no permission question through the memo, so the policies are not using it.'
    );

    expect($fifteenRows)->toBe($threeRows,
        "Permission questions must not scale with rows: {$threeRows} for 3 reviews, {$fifteenRows} for 15."
    );
});

/**
 * The shared helper reads two constants off whichever policy is using it. A
 * policy that forgot one would fail at the moment somebody's access is being
 * decided, which is the worst imaginable time to find out — so it is checked
 * here, where forgetting one is a red test instead of a surprise.
 *
 * `null` is a real answer for the manage permission: three of these policies
 * deliberately have none, and writing it out is what tells the next reader
 * that was decided rather than forgotten.
 *
 * Reflection rather than `defined()`, because both constants are private and
 * `defined()` answers "no" for a private constant seen from outside the class
 * — which would have made this test pass for a policy that declared neither.
 */
test('every policy using the shared moderator helper declares the two constants it reads', function (): void {
    $policies = collect(glob(dirname(__DIR__, 2).'/src/Policies/*.php') ?: [])
        ->map(fn (string $path): string => 'Modules\\Stourify\\Policies\\'.basename($path, '.php'))
        ->filter(fn (string $class): bool => in_array(
            ChecksModeratorAccess::class,
            class_uses_recursive($class),
            true
        ));

    expect($policies->count())->toBeGreaterThan(0,
        'No policy uses the shared helper, so this test is checking nothing.'
    );

    foreach ($policies as $policy) {
        $reflection = new ReflectionClass($policy);

        expect($reflection->hasConstant('OVERRIDE_ROLES'))
            ->toBeTrue("{$policy} uses the shared moderator helper but declares no OVERRIDE_ROLES.");
        expect($reflection->hasConstant('MANAGE_PERMISSION'))
            ->toBeTrue("{$policy} uses the shared moderator helper but declares no MANAGE_PERMISSION (use null where it has none).");
    }
});

/**
 * A remembered answer that outlives the thing it was an answer to is the one
 * way this whole idea could hurt somebody. Grant a permission the viewer did
 * not have, and the very next question must come back with the new answer
 * rather than the remembered one.
 */
test('granting a permission is answered immediately by a policy converted here', function (): void {
    $user = $this->createUserWithPermissions($this->organization, COST_REVIEWER_PERMISSIONS);

    expect(AuthorizationMemo::permits($user, 'stourify.reviews.manage'))->toBeFalse();

    $user->givePermissionTo('stourify.reviews.manage');

    expect(AuthorizationMemo::permits($user, 'stourify.reviews.manage'))->toBeTrue();
});
