<?php

declare(strict_types=1);

use App\Events\Domain\UserRegistered;
use App\Models\Organization;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Stourify\Database\Seeders\StourifyExplorerBackfillSeeder;
use Modules\Stourify\Listeners\JoinPublicOrganizationAsExplorer;
use Modules\Stourify\Models\ExplorerProfile;
use Modules\Stourify\Models\Spot;
use Modules\Stourify\StourifyModule;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Traits\InteractsWithTestSetup;

uses(RefreshDatabase::class, InteractsWithTestSetup::class);

beforeEach(function (): void {
    // The public organization every explorer joins.
    $this->publicOrg = Organization::factory()->create([
        'slug' => config('stourify.public_organization.slug'),
        'name' => config('stourify.public_organization.name'),
    ]);

    // The explorer role with its permission grant, as the module publishes it.
    foreach (StourifyModule::EXPLORER_PERMISSIONS as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    Role::findOrCreate('user', 'web');
    $role = Role::findOrCreate(JoinPublicOrganizationAsExplorer::ROLE, 'web');
    $role->syncPermissions(StourifyModule::EXPLORER_PERMISSIONS);
});

// ---------------------------------------------------------------------------
// Registration enrols an explorer
// ---------------------------------------------------------------------------

test('registering enrols the user as an explorer of the public org', function (): void {
    // UserService fires UserRegistered only for the 'registration' source, which
    // both the web and mobile register endpoints use.
    $user = app(UserService::class)->createUser([
        'name' => 'Grace Wanderer',
        'email' => 'grace@example.com',
        'password' => 'password',
    ], 'registration');

    // The listener runs on the dispatched event.
    UserRegistered::dispatch($user);

    expect($this->publicOrg->members()->where('users.id', $user->id)->exists())->toBeTrue()
        ->and($user->fresh()->current_organization_id)->toBe($this->publicOrg->id);

    setPermissionsTeamId($this->publicOrg->id);
    expect($user->fresh()->hasRole(JoinPublicOrganizationAsExplorer::ROLE))->toBeTrue();
});

// ---------------------------------------------------------------------------
// Registration also creates the explorer profile (STOURIFY-82)
// ---------------------------------------------------------------------------

test('registering creates the explorer profile', function (): void {
    $user = app(UserService::class)->createUser([
        'name' => 'Grace Wanderer',
        'email' => 'grace-profile@example.com',
        'password' => 'password',
    ], 'registration');

    $profile = ExplorerProfile::query()->withoutGlobalScopes()->where('user_id', $user->id)->first();

    expect($profile)->not->toBeNull()
        ->and($profile->organization_id)->toBe($this->publicOrg->id)
        // Every rule the column and ProfileUpdateRequest enforce, at once —
        // a handle that fails any of them is one the user can never edit.
        ->and($profile->username)->toMatch('/^[a-z0-9_.]{3,30}$/');
});

test('two explorers registering under the same name get different handles', function (): void {
    foreach (['a@example.com', 'b@example.com'] as $email) {
        app(UserService::class)->createUser([
            'name' => 'Grace Wanderer',
            'email' => $email,
            'password' => 'password',
        ], 'registration');
    }

    $handles = ExplorerProfile::query()->withoutGlobalScopes()->pluck('username');

    expect($handles)->toHaveCount(2)
        ->and($handles->unique())->toHaveCount(2);
});

test('a name with nothing usable in it still registers', function (): void {
    $user = app(UserService::class)->createUser([
        'name' => 'のぞみ',
        'email' => 'nozomi@example.com',
        'password' => 'password',
    ], 'registration');

    $profile = ExplorerProfile::query()->withoutGlobalScopes()->where('user_id', $user->id)->first();

    expect($profile)->not->toBeNull()
        ->and($profile->username)->toMatch('/^[a-z0-9_.]{3,30}$/');
});

test('a second enrolment neither duplicates the profile nor changes the handle', function (): void {
    $user = User::factory()->create(['current_organization_id' => null]);

    JoinPublicOrganizationAsExplorer::enrol($user, $this->publicOrg);
    $handle = ExplorerProfile::query()->withoutGlobalScopes()->where('user_id', $user->id)->value('username');

    JoinPublicOrganizationAsExplorer::enrol($user, $this->publicOrg);

    expect(ExplorerProfile::query()->withoutGlobalScopes()->where('user_id', $user->id)->count())->toBe(1)
        ->and(ExplorerProfile::query()->withoutGlobalScopes()->where('user_id', $user->id)->value('username'))
        ->toBe($handle);
});

/**
 * The endpoint the Profile tab actually reads. It answers `data: null` for a
 * user with no profile, which is what the device saw — so asserting the row
 * exists is not enough; the screen's own read has to come back with something.
 */
test('GET /profile answers with a profile straight after registering', function (): void {
    $user = app(UserService::class)->createUser([
        'name' => 'Grace Wanderer',
        'email' => 'grace-me@example.com',
        'password' => 'password',
    ], 'registration');

    Sanctum::actingAs($user->fresh());

    $this->getJson('/api/v1/profile', ['X-Organization-Id' => $this->publicOrg->uuid])
        ->assertOk()
        ->assertJsonPath('data.viewer.is_self', true)
        ->assertJsonStructure(['data' => ['uuid', 'username', 'counts', 'viewer']]);
});

test('a seeder-created user gets no profile either (only registration does)', function (): void {
    $seeded = User::factory()->create();

    expect(ExplorerProfile::query()->withoutGlobalScopes()->where('user_id', $seeded->id)->exists())
        ->toBeFalse();
});

test('a seeder-created user is not auto-enrolled (only registration is)', function (): void {
    // Factory / seeder users use a non-registration source, so no enrolment.
    $seeded = User::factory()->create();

    expect($this->publicOrg->members()->where('users.id', $seeded->id)->exists())->toBeFalse();
});

test('enrolment is idempotent', function (): void {
    $user = User::factory()->create(['current_organization_id' => null]);

    JoinPublicOrganizationAsExplorer::enrol($user, $this->publicOrg);
    JoinPublicOrganizationAsExplorer::enrol($user, $this->publicOrg);

    expect($this->publicOrg->members()->where('users.id', $user->id)->count())->toBe(1);
});

test('enrolment does not overwrite an existing current organization', function (): void {
    $otherOrg = Organization::factory()->create();
    $user = User::factory()->create(['current_organization_id' => $otherOrg->id]);

    JoinPublicOrganizationAsExplorer::enrol($user, $this->publicOrg);

    expect($user->fresh()->current_organization_id)->toBe($otherOrg->id);
});

// ---------------------------------------------------------------------------
// An enrolled explorer can actually use the app end to end
// ---------------------------------------------------------------------------

test('a freshly enrolled explorer can create a spot and like a post', function (): void {
    $user = app(UserService::class)->createUser([
        'name' => 'Grace Wanderer',
        'email' => 'grace2@example.com',
        'password' => 'password',
    ], 'registration');
    UserRegistered::dispatch($user);

    Sanctum::actingAs($user->fresh());
    $headers = ['X-Organization-Id' => $this->publicOrg->uuid];

    // Create a spot — proves org membership (middleware) + the create permission.
    $spot = $this->postJson('/api/v1/spots', [
        'title' => 'Onboarding Cove',
        'latitude' => 6.1,
        'longitude' => 125.1,
        'status' => 'published',
    ], $headers)->assertCreated()->json('data');

    // Post about it, then like it — proves the discovered reaction permission.
    $post = $this->postJson('/api/v1/posts', [
        'spot_uuid' => $spot['uuid'],
        'publish' => true,
    ], $headers)->assertCreated()->json('data');

    $this->postJson('/api/v1/reactions', [
        'reactable_type' => 'stourify_post',
        'reactable_uuid' => $post['uuid'],
        'type' => 'like',
    ], $headers)->assertOk();

    expect(Spot::query()->where('uuid', $spot['uuid'])->exists())->toBeTrue();
});

test('a non-member is refused by the organization middleware', function (): void {
    // A user who never enrolled is not a member of the public org, so the header
    // middleware rejects them before any policy runs.
    $stranger = User::factory()->create();
    Sanctum::actingAs($stranger);

    $this->getJson('/api/v1/spots', ['X-Organization-Id' => $this->publicOrg->uuid])
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// The backfill seeder
// ---------------------------------------------------------------------------

test('the backfill seeder enrols pre-existing users', function (): void {
    $existing = User::factory()->count(3)->create();

    $this->seed(StourifyExplorerBackfillSeeder::class);

    foreach ($existing as $user) {
        expect($this->publicOrg->members()->where('users.id', $user->id)->exists())->toBeTrue();
    }
});
