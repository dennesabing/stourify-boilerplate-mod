<?php

declare(strict_types=1);

use App\Models\Comment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Stourify\Enums\PostVisibility;
use Modules\Stourify\Enums\SpotStatus;
use Modules\Stourify\Listeners\JoinPublicOrganizationAsExplorer;
use Modules\Stourify\Models\Post;
use Modules\Stourify\Models\Spot;
use Modules\Stourify\StourifyModule;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Traits\InteractsWithTestSetup;

uses(RefreshDatabase::class, InteractsWithTestSetup::class);

/**
 * Can a real explorer comment on a post? (STOURIFY-154)
 *
 * Every other comment test in this module answers a different question. They
 * hand their users a hand-written list of permissions — `posts.comments.view`,
 * `posts.comments.create` — and then check that the controller and the policy
 * behave correctly for somebody holding them. That is worth testing and those
 * tests are right, but no arrangement of them can ever notice that the
 * `explorer` role, the only role real users actually have, holds neither name.
 * It is the difference between checking that a key turns a lock and checking
 * that anybody was given the key. The suite was green for months while every
 * ordinary user's comment was refused with a 403.
 *
 * So nothing in this file names a permission. The users here are built from the
 * `explorer` role exactly as `StourifyModule` publishes it, and what they can
 * and cannot do is whatever that role says. Change the role's grant and these
 * tests change their answer — which is the whole point of them.
 */
beforeEach(function (): void {
    $this->organization = $this->setUpTestOrganization();

    // Every permission the role's grant names has to exist as a row before the
    // role can hold it — in production that is `permissions:sync`'s job, driven
    // by PermissionDiscoveryService reading the models' traits.
    foreach (StourifyModule::EXPLORER_PERMISSIONS as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    // The two names this card is about, created whether or not the grant lists
    // them. Otherwise a missing grant and a missing permission row would be
    // indistinguishable here, and the test would pass for the wrong reason on
    // the day somebody renamed the permission instead of dropping it.
    Permission::findOrCreate('posts.comments.view', 'web');
    Permission::findOrCreate('posts.comments.create', 'web');
    Permission::findOrCreate('posts.comments.update', 'web');
    Permission::findOrCreate('posts.comments.delete', 'web');

    Role::findOrCreate('user', 'web');
    Role::findOrCreate(JoinPublicOrganizationAsExplorer::ROLE, 'web')
        ->syncPermissions(StourifyModule::EXPLORER_PERMISSIONS);

    $this->author = $this->createUserWithRole($this->organization, JoinPublicOrganizationAsExplorer::ROLE);
    $this->explorer = $this->createUserWithRole($this->organization, JoinPublicOrganizationAsExplorer::ROLE);

    $this->spot = Spot::factory()->for($this->organization)->create([
        'user_id' => $this->author->id,
        'status' => SpotStatus::Published,
    ]);

    $this->post = Post::factory()->for($this->organization)->create([
        'user_id' => $this->author->id,
        'spot_id' => $this->spot->id,
        'visibility' => PostVisibility::Public,
        'published_at' => now(),
    ]);
});

// ---------------------------------------------------------------------------
// The reported bug
// ---------------------------------------------------------------------------

test('an explorer can comment on a post', function (): void {
    Sanctum::actingAs($this->explorer);

    $response = $this->postJson(
        "/api/v1/posts/{$this->post->uuid}/comments",
        ['body' => 'The gate really is open at five.'],
        orgHeader($this->organization),
    )->assertCreated();

    expect($response->json('data.body'))->toBe('The gate really is open at five.')
        ->and(Comment::query()->where('commentable_type', Post::class)->count())->toBe(1);
});

test('an explorer can read a post\'s comment thread', function (): void {
    Comment::factory()->for($this->organization)->create([
        'commentable_type' => Post::class,
        'commentable_id' => $this->post->id,
        'user_id' => $this->author->id,
        'body' => 'Is the gate open that early?',
    ]);

    Sanctum::actingAs($this->explorer);

    $response = $this->getJson(
        "/api/v1/posts/{$this->post->uuid}/comments",
        orgHeader($this->organization),
    )->assertOk();

    expect($response->json('data'))->toHaveCount(1);
});

// ---------------------------------------------------------------------------
// The grant is deliberately narrow — these pin it there
// ---------------------------------------------------------------------------

test('the explorer grant covers reading and writing post comments and nothing more', function (): void {
    expect(StourifyModule::EXPLORER_PERMISSIONS)
        ->toContain('posts.comments.view')
        ->toContain('posts.comments.create')
        // Somebody may edit and delete their OWN comment through CommentPolicy's
        // ownership rule without holding any permission at all. So these two
        // names would buy exactly one thing: reach over other people's replies.
        // That is a moderator's ability, not an explorer's.
        ->not->toContain('posts.comments.update')
        ->not->toContain('posts.comments.delete');
});

test('an explorer can delete their own post comment but not somebody else\'s', function (): void {
    $mine = Comment::factory()->for($this->organization)->create([
        'commentable_type' => Post::class,
        'commentable_id' => $this->post->id,
        'user_id' => $this->explorer->id,
        'body' => 'Mine.',
    ]);

    $theirs = Comment::factory()->for($this->organization)->create([
        'commentable_type' => Post::class,
        'commentable_id' => $this->post->id,
        'user_id' => $this->author->id,
        'body' => 'Theirs.',
    ]);

    Sanctum::actingAs($this->explorer);

    $this->deleteJson("/api/v1/comments/{$theirs->uuid}", [], orgHeader($this->organization))
        ->assertForbidden();

    $this->deleteJson("/api/v1/comments/{$mine->uuid}", [], orgHeader($this->organization))
        ->assertSuccessful();
});
