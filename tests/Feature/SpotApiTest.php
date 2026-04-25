<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Stourify\Models\Spot;
use Modules\Stourify\Models\SpotCategory;

uses(RefreshDatabase::class);

test('unauthenticated cannot list spots', function () {
    $this->getJson('/api/v1/spots')->assertUnauthorized();
});

test('authenticated user can list spots', function () {
    $user = User::factory()->create();
    Spot::factory()->count(3)->create();

    $this->actingAs($user)
        ->getJson('/api/v1/spots')
        ->assertOk()
        ->assertJsonStructure(['data']);
});

test('authenticated user can view a spot', function () {
    $user = User::factory()->create();
    $spot = Spot::factory()->create();

    $this->actingAs($user)
        ->getJson("/api/v1/spots/{$spot->uuid}")
        ->assertOk()
        ->assertJsonPath('data.id', $spot->uuid);
});

test('authenticated user can create a spot', function () {
    $user = User::factory()->create();
    $category = SpotCategory::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/v1/spots', [
            'name'        => 'Hidden Beach',
            'description' => 'A secret cove.',
            'category_id' => $category->uuid,
            'latitude'    => 6.1164,
            'longitude'   => 125.1716,
        ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Hidden Beach');

    expect(Spot::where('name', 'Hidden Beach')->exists())->toBeTrue();
});

test('spot creator can update the spot', function () {
    $user = User::factory()->create();
    $spot = Spot::factory()->create(['created_by' => $user->id]);

    $this->actingAs($user)
        ->putJson("/api/v1/spots/{$spot->uuid}", ['description' => 'Updated'])
        ->assertOk()
        ->assertJsonPath('data.description', 'Updated');
});

test('non-creator cannot update spot', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $spot = Spot::factory()->create(['created_by' => $other->id]);

    $this->actingAs($user)
        ->putJson("/api/v1/spots/{$spot->uuid}", ['description' => 'Hack'])
        ->assertForbidden();
});
