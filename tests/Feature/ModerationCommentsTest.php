<?php

use App\Models\User;
use Modules\Social\Models\Comment;
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

test('moderator can browse all comments', function () {
    $post = Post::factory()->create();
    Comment::factory()->count(3)->create(['post_id' => $post->id]);

    $this->actingAs($this->moderator)
        ->get(route('stourify.admin.moderation.comments'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Admin/Moderation/Comments'));
});

test('moderator can delete and warn from comments browser', function () {
    $author  = User::factory()->create();
    $post    = Post::factory()->create();
    $comment = Comment::factory()->create(['post_id' => $post->id, 'user_id' => $author->id]);

    $this->actingAs($this->moderator)
        ->delete(route('stourify.admin.moderation.warn', ['type' => 'comment', 'id' => $comment->id]))
        ->assertRedirect();

    expect(Comment::find($comment->id))->toBeNull();
});
