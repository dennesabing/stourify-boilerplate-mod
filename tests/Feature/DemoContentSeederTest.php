<?php

declare(strict_types=1);

use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Stourify\Database\Seeders\StourifyDemoContentSeeder;
use Modules\Stourify\Models\Spot;
use Tests\Traits\InteractsWithTestSetup;

uses(RefreshDatabase::class, InteractsWithTestSetup::class);

beforeEach(function (): void {
    $this->publicOrg = Organization::factory()->create([
        'slug' => config('stourify.public_organization.slug'),
        'name' => config('stourify.public_organization.name'),
    ]);
});

test('demo content is seeded when the environment allows it', function (): void {
    config(['stourify.seed_demo_content' => true]);

    (new StourifyDemoContentSeeder)->run();

    expect(Spot::where('organization_id', $this->publicOrg->id)->count())->toBeGreaterThan(0);
});

test('demo content is NOT seeded when the environment disallows it', function (): void {
    // `php artisan modules:seed` runs every published seeder on every deploy and
    // cannot skip one, so this switch is the only thing keeping fixture spots
    // out of production — where the content is real.
    config(['stourify.seed_demo_content' => false]);

    (new StourifyDemoContentSeeder)->run();

    expect(Spot::count())->toBe(0);
});

test('the config default keeps demo content out of production', function (): void {
    $default = require __DIR__.'/../../config/stourify.php';

    expect($default)->toHaveKey('seed_demo_content');

    // The default is derived from APP_ENV at config-load time; assert the
    // expression itself rather than the value this test run happens to carry.
    $source = file_get_contents(__DIR__.'/../../config/stourify.php');

    expect($source)->toContain("env('APP_ENV', 'production') !== 'production'");
});
