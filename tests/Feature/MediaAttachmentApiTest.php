<?php

declare(strict_types=1);

use App\Enums\RoleEnum;
use App\Models\Organization;
use App\Models\User;
use App\Services\Media\PresignedUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Modules\Stourify\Enums\SpotStatus;
use Modules\Stourify\Models\Post;
use Modules\Stourify\Models\Spot;
use Modules\Stourify\StourifyModule;
use Spatie\Permission\Models\Role;
use Tests\Traits\InteractsWithTestSetup;

uses(RefreshDatabase::class, InteractsWithTestSetup::class);

/**
 * Media attachment authorization for the module's two photo hosts.
 *
 * Every user here is provisioned with exactly StourifyModule::EXPLORER_PERMISSIONS
 * — the real grant, not a hand-written list — so these tests fail the moment the
 * role stops granting what the media endpoints require. That coupling is the
 * point: STOURIFY-22 was a permission row nobody was granted, and a test that
 * spells its own permission set would have passed straight over it.
 */
beforeEach(function (): void {
    $this->organization = $this->setUpTestOrganization();
    $this->seedPermissions(StourifyModule::EXPLORER_PERMISSIONS);

    $this->author = $this->createUserWithPermissions($this->organization, StourifyModule::EXPLORER_PERMISSIONS);
    $this->stranger = $this->createUserWithPermissions($this->organization, StourifyModule::EXPLORER_PERMISSIONS);

    $this->spot = Spot::factory()->for($this->organization)->create([
        'user_id' => $this->author->id,
        'status' => SpotStatus::Published,
    ]);

    $this->post = Post::factory()->for($this->organization)->create([
        'user_id' => $this->author->id,
        'spot_id' => $this->spot->id,
    ]);

    $this->strangersSpot = Spot::factory()->for($this->organization)->create([
        'user_id' => $this->stranger->id,
        'status' => SpotStatus::Published,
    ]);

    $this->strangersPost = Post::factory()->for($this->organization)->create([
        'user_id' => $this->stranger->id,
        'spot_id' => $this->strangersSpot->id,
    ]);
});

/**
 * The fake disk has no S3 signer, so the presign step itself is stubbed; these
 * tests cover the endpoint's authorization, not the signature.
 */
function stubPresign(): void
{
    test()->mock(PresignedUploadService::class, function ($mock): void {
        $mock->shouldReceive('forUpload')->andReturn([
            'key' => 'uploads/pending/abc/file.jpg',
            'url' => 'https://spaces.example.com/signed-put',
            'headers' => ['Content-Type' => 'image/jpeg'],
            'expires_at' => '2026-08-11T00:15:00+00:00',
        ]);
    });
}

/**
 * Put a pending object on the media disk, as a completed presigned PUT would.
 *
 * The bytes are a real image, not a placeholder string: both photo hosts
 * register a `thumb` conversion, and media-library runs it on attach, so a
 * non-image body fails the request for a reason that has nothing to do with
 * the authorization under test.
 */
function pendingUpload(): string
{
    $key = 'uploads/pending/'.fake()->uuid().'/file.jpg';
    Storage::disk(app(PresignedUploadService::class)->disk())
        ->put($key, UploadedFile::fake()->image('sunset.jpg', 64, 64)->getContent());

    return $key;
}

function requestUploadUrl(User $user, Organization $organization, string $morphAlias, string $uuid): TestResponse
{
    return test()->actingAs($user, 'sanctum')->postJson('/api/v1/media/upload-url', [
        'filename' => 'sunset.jpg',
        'content_type' => 'image/jpeg',
        'model_type' => $morphAlias,
        'model_uuid' => $uuid,
    ], orgHeader($organization));
}

function attachUpload(User $user, Organization $organization, string $morphAlias, string $uuid, string $key): TestResponse
{
    return test()->actingAs($user, 'sanctum')->postJson('/api/v1/media/attach', [
        'key' => $key,
        'name' => 'Sunset',
        'model_type' => $morphAlias,
        'model_uuid' => $uuid,
    ], orgHeader($organization));
}

// ---------------------------------------------------------------------------
// Criterion 1 — an explorer can upload to what they authored
// ---------------------------------------------------------------------------

test('an explorer is issued an upload url for a post they authored', function (): void {
    stubPresign();

    requestUploadUrl($this->author, $this->organization, 'stourify_post', $this->post->uuid)
        ->assertCreated()
        ->assertJsonPath('data.url', 'https://spaces.example.com/signed-put');
});

test('an explorer is issued an upload url for a spot they authored', function (): void {
    stubPresign();

    requestUploadUrl($this->author, $this->organization, 'stourify_spot', $this->spot->uuid)
        ->assertCreated();
});

test('an explorer attaches an uploaded photo to a post they authored', function (): void {
    Storage::fake('local');

    attachUpload($this->author, $this->organization, 'stourify_post', $this->post->uuid, pendingUpload())
        ->assertCreated()
        ->assertJsonPath('data.name', 'Sunset');

    expect($this->post->fresh()->getMedia('attachments'))->toHaveCount(1);
});

test('an explorer attaches an uploaded photo to a spot they authored', function (): void {
    Storage::fake('local');

    attachUpload($this->author, $this->organization, 'stourify_spot', $this->spot->uuid, pendingUpload())
        ->assertCreated();

    expect($this->spot->fresh()->getMedia('attachments'))->toHaveCount(1);
});

// ---------------------------------------------------------------------------
// Criterion 3 — the grant is not a licence over other people's hosts
// ---------------------------------------------------------------------------

test('an explorer cannot get an upload url for someone else\'s post', function (): void {
    stubPresign();

    requestUploadUrl($this->author, $this->organization, 'stourify_post', $this->strangersPost->uuid)
        ->assertForbidden();
});

test('an explorer cannot get an upload url for someone else\'s spot', function (): void {
    stubPresign();

    requestUploadUrl($this->author, $this->organization, 'stourify_spot', $this->strangersSpot->uuid)
        ->assertForbidden();
});

test('an explorer cannot attach a photo to someone else\'s post', function (): void {
    Storage::fake('local');

    attachUpload($this->author, $this->organization, 'stourify_post', $this->strangersPost->uuid, pendingUpload())
        ->assertForbidden();

    expect($this->strangersPost->fresh()->getMedia('attachments'))->toHaveCount(0);
});

test('an explorer cannot attach a photo to someone else\'s spot', function (): void {
    Storage::fake('local');

    attachUpload($this->author, $this->organization, 'stourify_spot', $this->strangersSpot->uuid, pendingUpload())
        ->assertForbidden();

    expect($this->strangersSpot->fresh()->getMedia('attachments'))->toHaveCount(0);
});

// ---------------------------------------------------------------------------
// The moderator tier survives the ownership rule
// ---------------------------------------------------------------------------

test('a moderator may still attach a photo to a post they did not author', function (): void {
    Storage::fake('local');

    Role::findOrCreate(RoleEnum::ORG_ADMIN->value, 'web');
    $moderator = $this->createUserWithRole($this->organization, RoleEnum::ORG_ADMIN);

    attachUpload($moderator, $this->organization, 'stourify_post', $this->post->uuid, pendingUpload())
        ->assertCreated();
});

// ---------------------------------------------------------------------------
// Criterion 2 — the grant lives in the seeder/sync path
// ---------------------------------------------------------------------------

test('the explorer role grants media create on both photo hosts', function (): void {
    $granted = config('roles.explorer.permissions');

    expect($granted)->toContain(
        'posts.media.view',
        'posts.media.create',
        'spots.media.view',
        'spots.media.create',
    );
});
