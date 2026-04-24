<?php

use App\Enums\RoleEnum;
use App\Models\User;
use Modules\Social\Models\Follow;
use Tests\Traits\InteractsWithTestSetup;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class, InteractsWithTestSetup::class);

beforeEach(function () {
    $this->artisan('permissions:sync');
    $this->organization = $this->setUpTestOrganization();

    $this->createRoleWithPermissions(RoleEnum::SITE_ADMIN, ['admin.access', 'stourify.spots.manage']);
    $this->admin = $this->createSiteAdmin($this->organization, ['stourify.spots.manage']);

    $this->target = User::factory()->create([
        'current_organization_id' => $this->organization->id,
    ]);

    $this->follower = User::factory()->create([
        'current_organization_id' => $this->organization->id,
    ]);

    Follow::create([
        'follower_id' => $this->follower->id,
        'followee_id' => $this->target->id,
        'status'      => 'active',
    ]);
});

test('admin can view follow graph for a user', function () {
    $this->actingAs($this->admin)
        ->getJson(route('stourify.admin.users.follow-graph', $this->target))
        ->assertOk()
        ->assertJsonStructure(['followers', 'following']);
});

test('follow graph returns correct follower', function () {
    $this->actingAs($this->admin)
        ->getJson(route('stourify.admin.users.follow-graph', $this->target))
        ->assertOk()
        ->assertJsonPath('followers.0.name', $this->follower->name);
});

test('user without spots permission cannot access follow graph', function () {
    $this->createRoleWithPermissions('StourifyModerator', ['admin.access', 'stourify.moderation.manage']);
    $moderator = User::factory()->create([
        'current_organization_id' => $this->organization->id,
    ]);
    $moderator->organizations()->attach($this->organization->id);
    $moderator->assignRole('StourifyModerator');

    $this->actingAs($moderator)
        ->getJson(route('stourify.admin.users.follow-graph', $this->target))
        ->assertForbidden();
});
