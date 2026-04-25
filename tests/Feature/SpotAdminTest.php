<?php

use App\Enums\RoleEnum;
use App\Models\User;
use Modules\Social\Models\Post;
use Modules\Stourify\Models\Spot;
use Tests\Traits\InteractsWithTestSetup;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class, InteractsWithTestSetup::class);

beforeEach(function () {
    $this->artisan('permissions:sync');
    $this->organization = $this->setUpTestOrganization();

    // Admin: SITE_ADMIN role (has admin.access) + stourify.spots.manage permission
    $this->createRoleWithPermissions(RoleEnum::SITE_ADMIN, ['admin.access', 'stourify.spots.manage']);
    $this->admin = $this->createSiteAdmin($this->organization, ['stourify.spots.manage']);

    // Moderator: SITE_ADMIN role + stourify.moderation.manage permission (no stourify.spots.manage)
    $this->createRoleWithPermissions('StourifyModerator', ['admin.access', 'stourify.moderation.manage']);
    $this->moderator = User::factory()->create([
        'current_organization_id' => $this->organization->id,
    ]);
    $this->moderator->organizations()->attach($this->organization->id);
    $this->moderator->assignRole('StourifyModerator');
});

test('admin can list spots', function () {
    Spot::factory()->count(3)->create();

    $this->actingAs($this->admin)
        ->get(route('stourify.admin.spots.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Admin/Spots/Index'));
});

test('admin can view spot create form', function () {
    $this->actingAs($this->admin)
        ->get(route('stourify.admin.spots.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Admin/Spots/Create'));
});

test('admin can create a spot', function () {
    $this->actingAs($this->admin)
        ->post(route('stourify.admin.spots.store'), [
            'name'      => 'Test Spot',
            'latitude'  => 6.1164,
            'longitude' => 125.1716,
            'status'    => 'active',
        ])
        ->assertRedirect();

    expect(Spot::where('name', 'Test Spot')->exists())->toBeTrue();
});

test('admin can view spot detail', function () {
    $spot = Spot::factory()->create();

    $this->actingAs($this->admin)
        ->get(route('stourify.admin.spots.show', $spot))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Admin/Spots/Show'));
});

test('admin can update spot status to active', function () {
    $spot = Spot::factory()->create(['status' => 'pending']);

    $this->actingAs($this->admin)
        ->put(route('stourify.admin.spots.update', $spot), [
            'name'      => $spot->name,
            'latitude'  => $spot->latitude,
            'longitude' => $spot->longitude,
            'status'    => 'active',
        ])
        ->assertRedirect();

    expect($spot->fresh()->status)->toBe('active');
});

test('admin can delete a spot', function () {
    $spot = Spot::factory()->create();

    $this->actingAs($this->admin)
        ->delete(route('stourify.admin.spots.destroy', $spot))
        ->assertRedirect(route('stourify.admin.spots.index'));

    expect(Spot::find($spot->id))->toBeNull();
});

test('admin can merge spots — posts reassigned and source deleted', function () {
    $source = Spot::factory()->create();
    $target = Spot::factory()->create();
    $post   = Post::factory()->create(['spot_id' => $source->id]);

    $this->actingAs($this->admin)
        ->post(route('stourify.admin.spots.merge', $source), [
            'target_spot_uuid' => $target->uuid,
        ])
        ->assertRedirect(route('stourify.admin.spots.show', $target));

    expect($post->fresh()->spot_id)->toBe($target->id);
    expect(Spot::find($source->id))->toBeNull();
});

test('moderator cannot access spot admin routes', function () {
    $this->actingAs($this->moderator)
        ->get(route('stourify.admin.spots.index'))
        ->assertForbidden();
});

test('unauthenticated user is redirected to login', function () {
    $this->get(route('stourify.admin.spots.index'))
        ->assertRedirect();
});
