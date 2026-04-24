<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

test('stourify permissions are registered after sync', function () {
    $this->artisan('permissions:sync');

    expect(Permission::where('name', 'stourify.spots.manage')->exists())->toBeTrue();
    expect(Permission::where('name', 'stourify.moderation.manage')->exists())->toBeTrue();
});
