<?php

declare(strict_types=1);

use App\Enums\RoleEnum;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Modules\Stourify\Database\Seeders\StourifyPublicOrganizationSeeder;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * What a bare production seed is allowed to leave behind, from Stourify's side.
 *
 * The platform promises that seeding production without SEED_PASSWORD creates
 * no accounts and no organizations -- for the platform's OWN seeders (see
 * saas-boilerplate tests/Feature/Seeders/DatabaseSeederTest.php). Stourify
 * needs one thing the platform does not: the Stourify Public organization,
 * which every sign-up joins, has to exist before the first person signs up.
 * An organization needs an owner, and on a bare install nobody exists yet, so
 * the seeder creates a caretaker account to hold it.
 *
 * These tests pin how small that exception is: one organization, one owner,
 * and an owner nobody can sign in as or take over with a reset link.
 */
function stourifySeedBareProductionInstall(): void
{
    // Exactly what a deploy issues, on a database with nobody in it and no
    // SEED_PASSWORD. --force because the command is confirmable on production.
    app()->detectEnvironment(fn (): string => 'production');
    config()->set('seed.password', null);

    test()->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])
        ->assertSuccessful();

    // Back to the test environment before driving any HTTP route, so what is
    // measured below is the account's state and not production-only middleware.
    app()->detectEnvironment(fn (): string => 'testing');
}

function stourifyPublicOrganization(): ?Organization
{
    return Organization::withoutGlobalScopes()
        ->where('slug', config('stourify.public_organization.slug'))
        ->first();
}

/**
 * Give $user the platform-wide Super Admin role, attached the way
 * InitialDataSeeder attaches it (inside an organization's team).
 */
function stourifyMakeGlobalSuperAdmin(User $user): void
{
    Role::findOrCreate(RoleEnum::SUPER_ADMIN->value, 'web');

    $organization = Organization::factory()->create();
    setPermissionsTeamId($organization->id);
    $user->assignRole(RoleEnum::SUPER_ADMIN->value);
}

test('a bare production seed creates the public organization and one owner, and nothing else', function (): void {
    stourifySeedBareProductionInstall();

    $organization = stourifyPublicOrganization();

    expect(DB::table('organizations')->count())->toBe(1)
        ->and($organization)->not->toBeNull()
        ->and(User::count())->toBe(1)
        ->and(User::sole()->email)->toBe(config('stourify.public_organization.system_user_email'))
        ->and($organization->owner_id)->toBe(User::sole()->id);
});

test('the seeded owner is inactive, so neither sign-in route lets anybody in', function (): void {
    stourifySeedBareProductionInstall();

    $owner = User::sole();

    expect($owner->is_active)->toBeFalsy();

    // Hand the account a password someone knows. Even then the answer must be
    // no: being inactive is what refuses it, not the secrecy of the password.
    $owner->forceFill(['password' => Hash::make('a-password-somebody-learned')])->save();

    $this->postJson('/api/v1/login', [
        'email' => $owner->email,
        'password' => 'a-password-somebody-learned',
    ])->assertStatus(422)
        ->assertJsonPath('errors.email.0', __('auth.inactive'));

    expect(DB::table('personal_access_tokens')->count())->toBe(0);
});

test('the seeded owner cannot be taken over through a password reset', function (): void {
    stourifySeedBareProductionInstall();

    Notification::fake();

    $email = User::sole()->email;

    $this->postJson('/api/v1/forgot-password', ['email' => $email])->assertStatus(200);
    $this->post(route('password.email'), ['email' => $email])->assertSessionHasErrors('email');

    Notification::assertNothingSent();
    expect(DB::table('password_reset_tokens')->count())->toBe(0);
});

test('an existing global admin owns the organization instead of an older ordinary user', function (): void {
    User::factory()->create(['name' => 'First consumer to sign up']);
    $admin = User::factory()->create();
    stourifyMakeGlobalSuperAdmin($admin);

    (new StourifyPublicOrganizationSeeder)->run();

    expect(stourifyPublicOrganization()->owner_id)->toBe($admin->id)
        ->and(User::where('email', config('stourify.public_organization.system_user_email'))->exists())
        ->toBeFalse();
});

test('with only ordinary users, an inactive system owner is created rather than handing one of them the organization', function (): void {
    $consumer = User::factory()->create();

    (new StourifyPublicOrganizationSeeder)->run();

    $owner = User::find(stourifyPublicOrganization()->owner_id);

    expect($owner->id)->not->toBe($consumer->id)
        ->and($owner->email)->toBe(config('stourify.public_organization.system_user_email'))
        ->and($owner->is_active)->toBeFalsy();
});

test('an inactive admin is not chosen as owner', function (): void {
    $admin = User::factory()->create(['is_active' => false]);
    stourifyMakeGlobalSuperAdmin($admin);

    (new StourifyPublicOrganizationSeeder)->run();

    expect(stourifyPublicOrganization()->owner_id)->not->toBe($admin->id);
});

test('an organization that already exists keeps its owner, and no account is created', function (): void {
    $owner = User::factory()->create();
    Organization::factory()->create([
        'slug' => config('stourify.public_organization.slug'),
        'owner_id' => $owner->id,
    ]);
    $usersBefore = User::count();

    (new StourifyPublicOrganizationSeeder)->run();

    expect(stourifyPublicOrganization()->owner_id)->toBe($owner->id)
        ->and(User::count())->toBe($usersBefore);
});

test('running it again on a bare database changes nothing', function (): void {
    (new StourifyPublicOrganizationSeeder)->run();
    (new StourifyPublicOrganizationSeeder)->run();

    expect(DB::table('organizations')->count())->toBe(1)
        ->and(User::count())->toBe(1);
});
