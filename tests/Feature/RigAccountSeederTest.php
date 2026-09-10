<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Stourify\Database\Seeders\StourifyRigAccountSeeder;
use Modules\Stourify\Models\ExplorerProfile;
use Tests\Traits\InteractsWithTestSetup;

uses(RefreshDatabase::class, InteractsWithTestSetup::class);

beforeEach(function (): void {
    $this->publicOrg = Organization::factory()->create([
        'slug' => config('stourify.public_organization.slug'),
        'name' => config('stourify.public_organization.name'),
    ]);

    config(['stourify.rig_account' => [
        'email' => 'rig@example.test',
        'password' => 'first-Password-123!',
        'name' => 'Stourify Rig',
    ]]);
});

test('it creates a verified, active explorer of the public organization', function (): void {
    (new StourifyRigAccountSeeder)->run();

    $rig = User::where('email', 'rig@example.test')->sole();

    expect($rig->is_active)->toBeTruthy()
        ->and($rig->email_verified_at)->not->toBeNull()
        ->and($rig->current_organization_id)->toBe($this->publicOrg->id)
        ->and($this->publicOrg->members()->where('users.id', $rig->id)->exists())->toBeTrue()
        ->and(Hash::check('first-Password-123!', $rig->password))->toBeTrue()
        ->and(ExplorerProfile::withoutGlobalScopes()->where('user_id', $rig->id)->exists())->toBeTrue();
});

test('running it twice leaves one account and one membership', function (): void {
    (new StourifyRigAccountSeeder)->run();
    (new StourifyRigAccountSeeder)->run();

    $rig = User::where('email', 'rig@example.test')->sole();

    expect($this->publicOrg->members()->where('users.id', $rig->id)->count())->toBe(1);
});

test('the password follows the configured value, so the .env stays the one source of truth', function (): void {
    (new StourifyRigAccountSeeder)->run();

    config(['stourify.rig_account.password' => 'second-Password-456!']);
    (new StourifyRigAccountSeeder)->run();

    expect(Hash::check('second-Password-456!', User::where('email', 'rig@example.test')->sole()->password))
        ->toBeTrue();
});

test('it touches no other account', function (): void {
    // The backfill seeder enrols EVERY user; this one must not. The no-organization
    // fixture accounts other cards rely on stay exactly as they were.
    $bystanderOrg = Organization::factory()->create();
    $bystander = User::factory()->create(['current_organization_id' => $bystanderOrg->id]);

    (new StourifyRigAccountSeeder)->run();

    expect($bystander->fresh()->current_organization_id)->toBe($bystanderOrg->id)
        ->and($this->publicOrg->members()->where('users.id', $bystander->id)->exists())->toBeFalse();
});

test('it does nothing when the credentials are not configured', function (?string $email, ?string $password): void {
    config(['stourify.rig_account.email' => $email, 'stourify.rig_account.password' => $password]);

    (new StourifyRigAccountSeeder)->run();

    expect(User::where('email', 'rig@example.test')->exists())->toBeFalse();
})->with([
    'no email' => [null, 'first-Password-123!'],
    'no password' => ['rig@example.test', null],
    'empty password' => ['rig@example.test', ''],
]);

test('it refuses to run in production, whatever the configuration says', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    (new StourifyRigAccountSeeder)->run();

    expect(User::where('email', 'rig@example.test')->exists())->toBeFalse();
});

test('it does nothing when the public organization is not provisioned', function (): void {
    $this->publicOrg->delete();

    (new StourifyRigAccountSeeder)->run();

    expect(User::where('email', 'rig@example.test')->exists())->toBeFalse();
});
