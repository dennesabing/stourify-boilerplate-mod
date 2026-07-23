<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use App\Registries\ModuleRegistry;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Stourify\Database\Seeders\StourifyPublicOrganizationSeeder;
use Modules\Stourify\Enums\SpotStatus;
use Modules\Stourify\Models\City;
use Modules\Stourify\Models\Post;
use Modules\Stourify\Models\Report;
use Modules\Stourify\Models\Review;
use Modules\Stourify\Models\Spot;
use Modules\Stourify\StourifyModule;
use Tests\Traits\InteractsWithTestSetup;

uses(RefreshDatabase::class, InteractsWithTestSetup::class);

beforeEach(function () {
    $this->organization = $this->setUpTestOrganization();
});

test('the module is registered and enabled', function () {
    expect(app(ModuleRegistry::class)->isEnabled('stourify'))->toBeTrue();
});

test('every polymorphic model resolves through a stable morph alias', function () {
    // The alias is the contract — a namespace change must not orphan the
    // media, comment, reaction and report rows already written.
    expect(Relation::getMorphedModel('stourify_spot'))->toBe(Spot::class)
        ->and(Relation::getMorphedModel('stourify_post'))->toBe(Post::class)
        ->and(Relation::getMorphedModel('stourify_review'))->toBe(Review::class)
        ->and((new Spot)->getMorphClass())->toBe('stourify_spot');
});

test('the public organization seeder is idempotent', function () {
    $slug = config('stourify.public_organization.slug');

    (new StourifyPublicOrganizationSeeder)->run();
    $afterFirst = Organization::withoutGlobalScopes()->where('slug', $slug)->count();

    (new StourifyPublicOrganizationSeeder)->run();
    $afterSecond = Organization::withoutGlobalScopes()->where('slug', $slug)->count();

    expect($afterFirst)->toBe(1)
        ->and($afterSecond)->toBe(1);
});

test('the seeder never writes a known credential', function () {
    (new StourifyPublicOrganizationSeeder)->run();

    $email = config('stourify.public_organization.system_user_email');
    $systemUser = User::where('email', $email)->first();

    // On a database that already has users the seeder reuses one, so the
    // synthetic account may legitimately not exist. When it does, its password
    // must not be guessable.
    if ($systemUser !== null) {
        expect(Hash::check('password', $systemUser->password))->toBeFalse()
            ->and(Hash::check('', $systemUser->password))->toBeFalse();
    }
})->skip(fn () => User::query()->exists(), 'Existing users are reused as owner.');

test('nearby returns spots inside the radius, closest first, and excludes those outside', function () {
    $city = City::factory()->genSan()->create(['organization_id' => $this->organization->id]);

    $latitude = 6.1164;
    $longitude = 125.1716;

    $far = Spot::factory()->near($latitude, $longitude, 40.0)->create([
        'title' => 'Forty kilometres away',
        'city_id' => $city->id,
        'organization_id' => $this->organization->id,
    ]);
    $nearest = Spot::factory()->near($latitude, $longitude, 0.5)->create([
        'title' => 'Half a kilometre away',
        'city_id' => $city->id,
        'organization_id' => $this->organization->id,
    ]);
    $middle = Spot::factory()->near($latitude, $longitude, 2.0)->create([
        'title' => 'Two kilometres away',
        'city_id' => $city->id,
        'organization_id' => $this->organization->id,
    ]);

    $results = Spot::withoutGlobalScopes()
        ->nearby($latitude, $longitude, 5.0)
        ->pluck('id')
        ->all();

    expect($results)->toBe([$nearest->id, $middle->id])
        ->and($results)->not->toContain($far->id);
});

test('published scope hides drafts from discovery', function () {
    $published = Spot::factory()->create(['organization_id' => $this->organization->id]);
    $draft = Spot::factory()->draft()->create(['organization_id' => $this->organization->id]);

    $visible = Spot::withoutGlobalScopes()->published()->pluck('id')->all();

    expect($visible)->toContain($published->id)
        ->and($visible)->not->toContain($draft->id)
        ->and($draft->status)->toBe(SpotStatus::Draft);
});

test('a spot accepts the core attachables rather than defining its own', function () {
    $spot = Spot::factory()->create(['organization_id' => $this->organization->id]);

    // Comments, media, reactions and tags are the boilerplate's — the module
    // must never grow parallel tables for them.
    expect(method_exists($spot, 'comments'))->toBeTrue()
        ->and(method_exists($spot, 'media'))->toBeTrue()
        ->and(method_exists($spot, 'reactions'))->toBeTrue()
        ->and(method_exists($spot, 'tags'))->toBeTrue()
        ->and(Spot::permissionPrefix())->toBe('spots');
});

test('a report resolves its polymorphic subject through the alias', function () {
    $spot = Spot::factory()->create(['organization_id' => $this->organization->id]);

    $report = Report::factory()->create([
        'organization_id' => $this->organization->id,
        'reportable_type' => $spot->getMorphClass(),
        'reportable_id' => $spot->id,
    ]);

    expect($report->getRawOriginal('reportable_type'))->toBe('stourify_spot')
        ->and($report->reportable->is($spot))->toBeTrue();
});

test('a spot cannot be reviewed twice by the same explorer', function () {
    $spot = Spot::factory()->create(['organization_id' => $this->organization->id]);
    $user = User::factory()->create();

    Review::factory()->create([
        'organization_id' => $this->organization->id,
        'spot_id' => $spot->id,
        'user_id' => $user->id,
    ]);

    expect(fn () => Review::factory()->create([
        'organization_id' => $this->organization->id,
        'spot_id' => $spot->id,
        'user_id' => $user->id,
    ]))->toThrow(QueryException::class);
});

test('the module publishes its permissions', function () {
    $permissions = (new StourifyModule)->permissions();

    expect($permissions)->toContain('stourify.spots.create')
        ->and($permissions)->toContain('stourify.reports.manage')
        // Attachable permissions are discovered from the host model, never
        // hand-declared — declaring them here would create two sources.
        ->and($permissions)->not->toContain('spots.comments.view');
});
