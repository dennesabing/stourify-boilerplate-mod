<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Stourify\Enums\PostVisibility;
use Modules\Stourify\Enums\SpotStatus;
use Modules\Stourify\Models\ExplorerProfile;
use Modules\Stourify\Models\Post;
use Modules\Stourify\Models\Spot;
use Tests\Traits\InteractsWithTestSetup;

uses(RefreshDatabase::class, InteractsWithTestSetup::class);

/**
 * Your photo and your display name, as the rest of the app sees them
 * (STOURIFY-307).
 *
 * Both are written by platform routes this module does not own — `PUT /me` and
 * `POST|DELETE /me/avatar` — and both are READ by this module in two places a
 * change has to reach:
 *
 * - **Your profile header** (`GET /profile`). It had a name and no photo, so a
 *   phone that uploaded one had nowhere to read it back from after its next
 *   sign-in: login answers with a bare user that carries no photo at all.
 * - **Every cached list with an author in it.** A page of posts is saved in the
 *   cache with each author's name and photo URL copied inside it. Nothing
 *   cleared those pages when a name or a photo changed, so your own Posts tab
 *   went on showing the old ones for up to the cache's hour.
 *
 * The list tests read the SAME list twice — once before the change, so the page
 * is really in the cache, and once after. A test that only read it afterwards
 * would pass against the bug, because the first read of a cold cache is always
 * fresh.
 *
 * @var list<string>
 */
const PHOTO_AND_NAME_PERMISSIONS = ['stourify.posts.view'];

beforeEach(function (): void {
    Storage::fake('media');

    $this->organization = $this->setUpTestOrganization();
    $this->seedPermissions(PHOTO_AND_NAME_PERMISSIONS);

    $this->explorer = $this->createUserWithPermissions($this->organization, PHOTO_AND_NAME_PERMISSIONS);
    $this->explorer->forceFill(['name' => 'Ramil Santos'])->save();

    ExplorerProfile::factory()->for($this->organization)->create([
        'user_id' => $this->explorer->id, 'username' => 'santos_ramil',
    ]);

    $spot = Spot::factory()->for($this->organization)->create([
        'user_id' => $this->explorer->id, 'status' => SpotStatus::Published,
    ]);

    Post::factory()->for($this->organization)->create([
        'user_id' => $this->explorer->id,
        'spot_id' => $spot->id,
        'visibility' => PostVisibility::Public,
        'published_at' => now()->subHour(),
    ]);

    Sanctum::actingAs($this->explorer);
});

/**
 * The author block of the first post on your own Posts tab.
 *
 * @return array<string, mixed>
 */
function myFirstPostAuthor(mixed $organization): array
{
    return test()->getJson('/api/v1/posts?mine=1', orgHeader($organization))
        ->assertOk()
        ->json('data.0.author');
}

function uploadMyAvatar(mixed $organization): string
{
    return test()->post(
        '/api/v1/me/avatar',
        ['avatar' => UploadedFile::fake()->image('me.png', 64, 64)],
        [...orgHeader($organization), 'Accept' => 'application/json'],
    )->assertOk()->json('data.profile_photo_url');
}

// ---------------------------------------------------------------------------
// The profile header
// ---------------------------------------------------------------------------

test('GET /profile carries your photo once you upload one, and null once you remove it', function (): void {
    $before = $this->getJson('/api/v1/profile', orgHeader($this->organization))->assertOk()->json('data');

    // The key is there even with no photo, so a client can tell "no photo"
    // from "this server does not say".
    expect($before)->toHaveKey('avatar_url')
        ->and($before['avatar_url'])->toBeNull();

    $uploaded = uploadMyAvatar($this->organization);

    expect($uploaded)->not->toBeNull();
    $this->getJson('/api/v1/profile', orgHeader($this->organization))
        ->assertOk()
        ->assertJsonPath('data.avatar_url', $uploaded);

    $this->deleteJson('/api/v1/me/avatar', [], orgHeader($this->organization))->assertOk();

    $this->getJson('/api/v1/profile', orgHeader($this->organization))
        ->assertOk()
        ->assertJsonPath('data.avatar_url', null);
});

test('another explorer sees your photo on your profile too — it is already public on every post', function (): void {
    $uploaded = uploadMyAvatar($this->organization);

    $other = $this->createUserWithPermissions($this->organization, PHOTO_AND_NAME_PERMISSIONS);
    Sanctum::actingAs($other);

    $this->getJson("/api/v1/profiles/{$this->explorer->uuid}", orgHeader($this->organization))
        ->assertOk()
        ->assertJsonPath('data.avatar_url', $uploaded);
});

// ---------------------------------------------------------------------------
// The cached lists with an author in them
// ---------------------------------------------------------------------------

test('a new display name shows on your Posts list at once, not when the cache expires', function (): void {
    expect(myFirstPostAuthor($this->organization)['name'])->toBe('Ramil Santos');

    $this->putJson('/api/v1/me', ['name' => 'Ramil S.'], orgHeader($this->organization))->assertOk();

    expect(myFirstPostAuthor($this->organization)['name'])->toBe('Ramil S.');
});

test('a new photo shows on your Posts list at once', function (): void {
    expect(myFirstPostAuthor($this->organization)['avatar_url'])->toBeNull();

    $uploaded = uploadMyAvatar($this->organization);

    expect(myFirstPostAuthor($this->organization)['avatar_url'])->toBe($uploaded);
});

test('a removed photo disappears from your Posts list at once', function (): void {
    uploadMyAvatar($this->organization);
    expect(myFirstPostAuthor($this->organization)['avatar_url'])->not->toBeNull();

    $this->deleteJson('/api/v1/me/avatar', [], orgHeader($this->organization))->assertOk();

    expect(myFirstPostAuthor($this->organization)['avatar_url'])->toBeNull();
});
