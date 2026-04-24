<?php

use App\Models\User;
use Modules\Social\Models\Post;
use Tests\Traits\InteractsWithTestSetup;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class, InteractsWithTestSetup::class);

beforeEach(function () {
    $this->artisan('permissions:sync');
    $this->organization = $this->setUpTestOrganization();

    $this->createRoleWithPermissions('StourifyModerator', ['admin.access', 'stourify.moderation.manage']);
    $this->moderator = User::factory()->create([
        'current_organization_id' => $this->organization->id,
    ]);
    $this->moderator->organizations()->attach($this->organization->id);
    $this->moderator->assignRole('StourifyModerator');
});

test('moderator can browse all posts', function () {
    Post::factory()->count(3)->create(['visibility' => 'public']);

    $this->actingAs($this->moderator)
        ->get(route('stourify.admin.moderation.posts'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Admin/Moderation/Posts'));
});

test('moderator can delete and warn from posts browser', function () {
    $author = User::factory()->create();
    $post   = Post::factory()->create(['user_id' => $author->id]);

    $this->actingAs($this->moderator)
        ->delete(route('stourify.admin.moderation.warn', ['type' => 'post', 'id' => $post->id]))
        ->assertRedirect();

    expect(Post::withoutGlobalScopes()->find($post->id))->toBeNull();
});
