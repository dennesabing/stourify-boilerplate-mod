<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Stourify\Database\Seeders\StourifyExplorerBackfillSeeder;
use Modules\Stourify\Listeners\JoinPublicOrganizationAsExplorer;
use Modules\Stourify\Models\ExplorerProfile;
use Modules\Stourify\StourifyModule;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Traits\InteractsWithTestSetup;

uses(RefreshDatabase::class, InteractsWithTestSetup::class);

/**
 * STOURIFY-91: the backfill hands accounts that registered before STOURIFY-82 a
 * handle they never chose. The operator's condition for doing that at all is
 * that the handle is one its owner can keep or change — so the bar here is not
 * a regex copied from the form request, it is the form request itself. Every
 * account saves its generated handle, unchanged, through the same
 * `PATCH /profile` the edit screen uses. A handle that endpoint rejects is a
 * user told their current name is invalid, with no way forward.
 */
beforeEach(function (): void {
    $this->publicOrg = Organization::factory()->create([
        'slug' => config('stourify.public_organization.slug'),
        'name' => config('stourify.public_organization.name'),
    ]);

    foreach (StourifyModule::EXPLORER_PERMISSIONS as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    Role::findOrCreate('user', 'web');
    Role::findOrCreate(JoinPublicOrganizationAsExplorer::ROLE, 'web')
        ->syncPermissions(StourifyModule::EXPLORER_PERMISSIONS);

    $this->headers = ['X-Organization-Id' => $this->publicOrg->uuid];
});

function backfilledHandle(User $user): ?string
{
    return ExplorerProfile::query()->withoutGlobalScopes()->where('user_id', $user->id)->value('username');
}

test('every handle the backfill hands out is one its owner can save unchanged', function (): void {
    // Taken in ANOTHER organization: the column is unique platform-wide, so the
    // backfill has to step around it even though the tenant scope hides it.
    ExplorerProfile::factory()->create([
        'organization_id' => Organization::factory()->create()->id,
        'username' => 'gracewanderer',
    ]);

    $names = [
        'Grace Wanderer',
        'Grace Wanderer',
        'GRACE WANDERER',
        "Renée O'Brien-Smith",
        'のぞみ',
        '!!! ???',
        '',
        '   ',
        'Jo',
        str_repeat('Long', 15),
        '...',
        'a.b_c',
        '😀 Emoji Person',
    ];

    $users = collect($names)->map(
        fn (string $name): User => User::factory()->create(['name' => $name, 'current_organization_id' => null]),
    );

    $this->seed(StourifyExplorerBackfillSeeder::class);

    $handles = $users->map(fn (User $user): ?string => backfilledHandle($user));

    expect($handles->filter())->toHaveCount(count($names))
        ->and($handles->unique())->toHaveCount(count($names))
        ->and($handles)->not->toContain('gracewanderer');

    foreach ($users as $i => $user) {
        Sanctum::actingAs($user->fresh());

        $this->patchJson('/api/v1/profile', ['username' => $handles[$i]], $this->headers)
            ->assertOk()
            ->assertJsonPath('data.username', $handles[$i]);
    }
});

/**
 * The save above would also pass if the uniqueness rule were not running at
 * all. This is the other half: somebody else's generated handle is refused.
 */
test('another account cannot take a backfilled handle', function (): void {
    [$first, $second] = User::factory()->count(2)->create(['name' => 'Grace Wanderer']);

    $this->seed(StourifyExplorerBackfillSeeder::class);

    Sanctum::actingAs($second->fresh());

    $this->patchJson('/api/v1/profile', ['username' => backfilledHandle($first)], $this->headers)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('username');
});

/**
 * Deleted accounts are skipped, and that is the reason the production check on
 * STOURIFY-91 excludes them: a deleted account with no profile is expected,
 * not evidence of a path that bypasses enrolment.
 */
test('the backfill gives no profile to a deleted account', function (): void {
    $deleted = User::factory()->create();
    $deleted->delete();

    $this->seed(StourifyExplorerBackfillSeeder::class);

    expect(backfilledHandle($deleted))->toBeNull();
});
