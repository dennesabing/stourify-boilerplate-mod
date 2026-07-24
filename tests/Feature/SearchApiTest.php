<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Stourify\Enums\SpotStatus;
use Modules\Stourify\Models\City;
use Modules\Stourify\Models\ExplorerProfile;
use Modules\Stourify\Models\Spot;
use Tests\Traits\InteractsWithTestSetup;

uses(RefreshDatabase::class, InteractsWithTestSetup::class);

/**
 * @var list<string>
 */
const SEARCH_PERMISSIONS = ['stourify.spots.view'];

beforeEach(function (): void {
    $this->organization = $this->setUpTestOrganization();
    $this->seedPermissions(SEARCH_PERMISSIONS);

    $this->explorer = $this->createUserWithPermissions($this->organization, SEARCH_PERMISSIONS);
});

function actingAsSearcher(User $user): void
{
    Sanctum::actingAs($user);
}

// ---------------------------------------------------------------------------
// Per-type search
// ---------------------------------------------------------------------------

test('searching spots matches the title and returns only published spots', function (): void {
    $published = Spot::factory()->for($this->organization)->create([
        'user_id' => $this->explorer->id, 'title' => 'Kalaklan Lighthouse', 'status' => SpotStatus::Published,
    ]);
    $draft = Spot::factory()->for($this->organization)->create([
        'user_id' => $this->explorer->id, 'title' => 'Kalaklan Secret Cove', 'status' => SpotStatus::Draft,
    ]);
    Spot::factory()->for($this->organization)->create([
        'user_id' => $this->explorer->id, 'title' => 'Somewhere Else', 'status' => SpotStatus::Published,
    ]);

    actingAsSearcher($this->explorer);
    $uuids = collect(
        $this->getJson('/api/v1/discover/search?type=spots&q=Kalaklan', orgHeader($this->organization))
            ->assertOk()->json('data')
    )->pluck('uuid');

    expect($uuids)->toContain($published->uuid)
        ->and($uuids)->not->toContain($draft->uuid);
});

test('searching cities matches the name', function (): void {
    $match = City::factory()->for($this->organization)->create(['name' => 'General Santos']);
    City::factory()->for($this->organization)->create(['name' => 'Davao City']);

    actingAsSearcher($this->explorer);
    $uuids = collect(
        $this->getJson('/api/v1/discover/search?type=cities&q=General', orgHeader($this->organization))
            ->assertOk()->json('data')
    )->pluck('uuid');

    expect($uuids)->toContain($match->uuid)->and($uuids)->toHaveCount(1);
});

test('searching people matches the username', function (): void {
    $target = $this->createUserWithPermissions($this->organization, []);
    $profile = ExplorerProfile::factory()->for($this->organization)->create([
        'user_id' => $target->id, 'username' => 'wander_grace',
    ]);
    ExplorerProfile::factory()->for($this->organization)->create([
        'user_id' => $this->explorer->id, 'username' => 'someone_else',
    ]);

    actingAsSearcher($this->explorer);
    $data = $this->getJson('/api/v1/discover/search?type=people&q=wander', orgHeader($this->organization))
        ->assertOk()->json('data');

    expect($data)->toHaveCount(1)
        ->and($data[0]['uuid'])->toBe($profile->uuid)
        ->and($data[0]['username'])->toBe('wander_grace')
        ->and($data[0]['user_uuid'])->toBe($target->uuid);
});

test('a private account is still findable by handle', function (): void {
    $target = $this->createUserWithPermissions($this->organization, []);
    $profile = ExplorerProfile::factory()->for($this->organization)->private()->create([
        'user_id' => $target->id, 'username' => 'quiet_wanderer',
    ]);

    actingAsSearcher($this->explorer);
    $data = $this->getJson('/api/v1/discover/search?type=people&q=quiet', orgHeader($this->organization))
        ->assertOk()->json('data');

    expect(collect($data)->pluck('uuid'))->toContain($profile->uuid)
        ->and($data[0]['is_private'])->toBeTrue();
});

test('people search never exposes an email', function (): void {
    $target = $this->createUserWithPermissions($this->organization, []);
    ExplorerProfile::factory()->for($this->organization)->create([
        'user_id' => $target->id, 'username' => 'findable_person',
    ]);

    actingAsSearcher($this->explorer);
    $this->getJson('/api/v1/discover/search?type=people&q=findable', orgHeader($this->organization))
        ->assertOk()
        ->assertJsonMissing(['email' => $target->email]);
});

// ---------------------------------------------------------------------------
// The combined "All" preview
// ---------------------------------------------------------------------------

test('the untyped search returns a grouped preview of all three sections', function (): void {
    $spot = Spot::factory()->for($this->organization)->create([
        'user_id' => $this->explorer->id, 'title' => 'Sarangani Overlook', 'status' => SpotStatus::Published,
    ]);
    $city = City::factory()->for($this->organization)->create(['name' => 'Sarangani']);
    $target = $this->createUserWithPermissions($this->organization, []);
    $profile = ExplorerProfile::factory()->for($this->organization)->create([
        'user_id' => $target->id, 'username' => 'sarangani_local',
    ]);

    actingAsSearcher($this->explorer);
    $data = $this->getJson('/api/v1/discover/search?q=sarangani', orgHeader($this->organization))
        ->assertOk()->json('data');

    expect(collect($data['spots'])->pluck('uuid'))->toContain($spot->uuid)
        ->and(collect($data['cities'])->pluck('uuid'))->toContain($city->uuid)
        ->and(collect($data['people'])->pluck('uuid'))->toContain($profile->uuid);
});

test('the preview caps each section', function (): void {
    // Seven matching cities; the preview shows at most five.
    City::factory()->for($this->organization)->count(7)->create(['region' => 'Mindanao Region']);

    actingAsSearcher($this->explorer);
    $data = $this->getJson('/api/v1/discover/search?q=Mindanao', orgHeader($this->organization))
        ->assertOk()->json('data');

    expect(count($data['cities']))->toBeLessThanOrEqual(5);
});

// ---------------------------------------------------------------------------
// Validation and permission
// ---------------------------------------------------------------------------

test('a query shorter than two characters is rejected', function (): void {
    actingAsSearcher($this->explorer);

    $this->getJson('/api/v1/discover/search?q=a', orgHeader($this->organization))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['q']);

    $this->getJson('/api/v1/discover/search', orgHeader($this->organization))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['q']);
});

test('an unknown type is rejected', function (): void {
    actingAsSearcher($this->explorer);

    $this->getJson('/api/v1/discover/search?q=test&type=tags', orgHeader($this->organization))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['type']);
});

test('search rejects an unauthenticated caller', function (): void {
    $this->getJson('/api/v1/discover/search?q=test', orgHeader($this->organization))->assertUnauthorized();
});

test('search is denied without the spots view permission', function (): void {
    actingAsSearcher($this->createUserWithPermissions($this->organization, []));

    $this->getJson('/api/v1/discover/search?q=test', orgHeader($this->organization))->assertForbidden();
});
