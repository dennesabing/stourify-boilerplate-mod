<?php

use App\Enums\RoleEnum;
use App\Models\User;
use Modules\Social\Models\Post;
use Modules\Stourify\Models\Report;
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

    $this->reporter = User::factory()->create([
        'current_organization_id' => $this->organization->id,
    ]);
    $this->reporter->organizations()->attach($this->organization->id);
});

test('moderator can view moderation queue', function () {
    $post = Post::factory()->create();
    Report::create([
        'reporter_id'     => $this->reporter->id,
        'reportable_type' => Post::class,
        'reportable_id'   => $post->id,
        'reason'          => 'spam',
        'status'          => 'pending',
    ]);

    $this->actingAs($this->moderator)
        ->get(route('stourify.admin.moderation.queue'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Admin/Moderation/Queue'));
});

test('dismissing a report marks it as dismissed without deleting content', function () {
    $post   = Post::factory()->create();
    $report = Report::create([
        'reporter_id'     => $this->reporter->id,
        'reportable_type' => Post::class,
        'reportable_id'   => $post->id,
        'reason'          => 'spam',
        'status'          => 'pending',
    ]);

    $this->actingAs($this->moderator)
        ->delete(route('stourify.admin.moderation.dismiss', $report))
        ->assertRedirect();

    expect($report->fresh()->status)->toBe('dismissed');
    expect(Post::withoutGlobalScopes()->find($post->id))->not->toBeNull();
});

test('delete and warn removes post and notifies author', function () {
    $author = User::factory()->create();
    $post   = Post::factory()->create(['user_id' => $author->id]);

    $this->actingAs($this->moderator)
        ->delete(route('stourify.admin.moderation.warn', ['type' => 'post', 'id' => $post->id]))
        ->assertRedirect();

    expect(Post::withoutGlobalScopes()->find($post->id))->toBeNull();
    expect($author->notifications()->where('type', \Modules\Stourify\Http\Notifications\ContentWarningNotification::class)->exists())->toBeTrue();
});
