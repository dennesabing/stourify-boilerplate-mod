<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Stourify\Enums\SpotStatus;
use Modules\Stourify\Models\ExplorerProfile;
use Modules\Stourify\Models\Review;
use Modules\Stourify\Models\Spot;
use Tests\Traits\InteractsWithTestSetup;

uses(RefreshDatabase::class, InteractsWithTestSetup::class);

/**
 * @var list<string>
 */
const REVIEWER_PERMISSIONS = [
    'stourify.reviews.view',
    'stourify.reviews.create',
    'stourify.reviews.update',
    'stourify.reviews.delete',
];

beforeEach(function (): void {
    $this->organization = $this->setUpTestOrganization();
    $this->seedPermissions([...REVIEWER_PERMISSIONS, 'stourify.reviews.manage']);

    $this->reviewer = $this->createUserWithPermissions($this->organization, REVIEWER_PERMISSIONS);
    $this->spot = Spot::factory()->for($this->organization)->create([
        'user_id' => $this->reviewer->id,
        'status' => SpotStatus::Published,
    ]);
});

function actingAsReviewer(User $user): void
{
    Sanctum::actingAs($user);
}

// ---------------------------------------------------------------------------
// CRUD
// ---------------------------------------------------------------------------

test('an explorer writes a review', function (): void {
    actingAsReviewer($this->reviewer);

    $this->postJson('/api/v1/reviews', [
        'spot_uuid' => $this->spot->uuid,
        'rating' => 4,
        'body' => 'Worth the walk. Go before noon.',
    ], orgHeader($this->organization))
        ->assertCreated()
        ->assertJsonPath('data.rating', 4)
        ->assertJsonPath('data.spot_uuid', $this->spot->uuid);

    $this->assertDatabaseHas('sto_reviews', [
        'spot_id' => $this->spot->id,
        'user_id' => $this->reviewer->id,
        'rating' => 4,
    ]);
});

test('a review is shown, updated and deleted by its author', function (): void {
    actingAsReviewer($this->reviewer);
    $review = Review::factory()->for($this->organization)->create([
        'user_id' => $this->reviewer->id,
        'spot_id' => $this->spot->id,
        'rating' => 3,
    ]);

    $this->getJson("/api/v1/reviews/{$review->uuid}", orgHeader($this->organization))
        ->assertOk()
        ->assertJsonPath('data.uuid', $review->uuid);

    $this->patchJson("/api/v1/reviews/{$review->uuid}", [
        'rating' => 5,
    ], orgHeader($this->organization))
        ->assertOk()
        ->assertJsonPath('data.rating', 5);

    $this->deleteJson("/api/v1/reviews/{$review->uuid}", [], orgHeader($this->organization))
        ->assertOk();

    $this->assertSoftDeleted('sto_reviews', ['id' => $review->id]);
});

test('the list filters by spot and by author', function (): void {
    $other = $this->createUserWithPermissions($this->organization, REVIEWER_PERMISSIONS);
    $otherSpot = Spot::factory()->for($this->organization)
        ->create(['user_id' => $other->id, 'status' => SpotStatus::Published]);

    $mine = Review::factory()->for($this->organization)
        ->create(['user_id' => $this->reviewer->id, 'spot_id' => $this->spot->id]);
    Review::factory()->for($this->organization)
        ->create(['user_id' => $other->id, 'spot_id' => $otherSpot->id]);

    actingAsReviewer($this->reviewer);

    $bySpot = $this->getJson("/api/v1/reviews?spot_uuid={$this->spot->uuid}", orgHeader($this->organization))
        ->assertOk()->json('data');
    expect($bySpot)->toHaveCount(1)->and($bySpot[0]['uuid'])->toBe($mine->uuid);

    $mineOnly = $this->getJson('/api/v1/reviews?mine=1', orgHeader($this->organization))
        ->assertOk()->json('data');
    expect($mineOnly)->toHaveCount(1)->and($mineOnly[0]['uuid'])->toBe($mine->uuid);
});

// ---------------------------------------------------------------------------
// Reviewer identity — a reviews list must not need one profile fetch per row
// ---------------------------------------------------------------------------

test('a review carries its author\'s identity for the list row', function (): void {
    ExplorerProfile::factory()->for($this->organization)->create([
        'user_id' => $this->reviewer->id,
        'username' => 'wanderer',
    ]);
    Review::factory()->for($this->organization)->create([
        'user_id' => $this->reviewer->id, 'spot_id' => $this->spot->id,
    ]);

    actingAsReviewer($this->reviewer);
    $review = $this->getJson('/api/v1/reviews', orgHeader($this->organization))->assertOk()->json('data.0');

    expect($review['author'])->not->toBeNull()
        ->and($review['author']['uuid'])->toBe($this->reviewer->uuid)
        ->and($review['author']['name'])->toBe($this->reviewer->name)
        ->and($review['author']['username'])->toBe('wanderer')
        // Backward compatibility: mobile already consumes author_uuid directly.
        ->and($review['author_uuid'])->toBe($this->reviewer->uuid);
});

test('a review reports a null username when the reviewer has no ExplorerProfile yet', function (): void {
    Review::factory()->for($this->organization)->create([
        'user_id' => $this->reviewer->id, 'spot_id' => $this->spot->id,
    ]);

    actingAsReviewer($this->reviewer);
    $review = $this->getJson('/api/v1/reviews', orgHeader($this->organization))->assertOk()->json('data.0');

    expect($review['author']['username'])->toBeNull()
        ->and($review['author']['uuid'])->toBe($this->reviewer->uuid);
});

test('the review list page query count does not grow with the number of reviews, only with the number of distinct authors (no N+1)', function (): void {
    Storage::fake('media');

    // Five distinct authors, each with an ExplorerProfile and avatar — the
    // identity data ReviewResource::author renders. This set is fixed across
    // both measurements below; only the review count changes.
    $authors = collect(range(1, 5))->map(function (int $i): User {
        $author = $this->createUserWithPermissions($this->organization, REVIEWER_PERMISSIONS);
        ExplorerProfile::factory()->for($this->organization)->create([
            'user_id' => $author->id, 'username' => "explorer{$i}",
        ]);
        $author->addMedia(UploadedFile::fake()->image("avatar{$i}.jpg", 100, 100))->toMediaCollection('avatar');

        return $author;
    });

    $sharedSpot = Spot::factory()->for($this->organization)->create([
        'user_id' => $authors->first()->id, 'status' => SpotStatus::Published,
    ]);

    // Round 1: one review per author (5 reviews, 5 authors).
    $authors->each(fn (User $author) => Review::factory()->for($this->organization)->create([
        'user_id' => $author->id, 'spot_id' => $sharedSpot->id,
    ]));

    actingAsReviewer($this->reviewer);

    DB::enableQueryLog();
    $this->getJson('/api/v1/reviews?per_page=25', orgHeader($this->organization))
        ->assertOk()->assertJsonCount(5, 'data');
    $queriesFiveReviews = count(DB::getQueryLog());
    DB::flushQueryLog();
    DB::disableQueryLog();

    // The array cache store used in tests has no tag support, so the
    // round-1 response for this exact query string would otherwise still be
    // cached — see the identical note in SpotApiTest's N+1 test.
    Cache::flush();

    // Round 2: three more reviews per author, each on a fresh spot (an
    // explorer may only review a given spot once — the sto_reviews
    // (spot_id, user_id) unique index), 20 reviews total, same 5 authors.
    $authors->each(function (User $author): void {
        foreach (range(1, 3) as $j) {
            $spot = Spot::factory()->for($this->organization)->create([
                'user_id' => $author->id, 'status' => SpotStatus::Published,
            ]);
            Review::factory()->for($this->organization)->create([
                'user_id' => $author->id, 'spot_id' => $spot->id,
            ]);
        }
    });

    DB::enableQueryLog();
    $this->getJson('/api/v1/reviews?per_page=25', orgHeader($this->organization))
        ->assertOk()->assertJsonCount(20, 'data');
    $queriesTwentyReviews = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queriesTwentyReviews)->toBeLessThanOrEqual($queriesFiveReviews + 2,
        "Expected no N+1: {$queriesFiveReviews} queries for 5 reviews, {$queriesTwentyReviews} for 20 reviews — same 5 authors."
    );
});

// ---------------------------------------------------------------------------
// Rating aggregation
// ---------------------------------------------------------------------------

test('the spot rating average and count track its reviews', function (): void {
    $a = $this->createUserWithPermissions($this->organization, REVIEWER_PERMISSIONS);
    $b = $this->createUserWithPermissions($this->organization, REVIEWER_PERMISSIONS);

    Review::factory()->for($this->organization)
        ->create(['user_id' => $a->id, 'spot_id' => $this->spot->id, 'rating' => 5]);
    Review::factory()->for($this->organization)
        ->create(['user_id' => $b->id, 'spot_id' => $this->spot->id, 'rating' => 2]);

    expect($this->spot->fresh()->rating_average)->toBe(3.5)
        ->and($this->spot->fresh()->reviews_count)->toBe(2);
});

test('editing a rating recomputes the spot average', function (): void {
    $review = Review::factory()->for($this->organization)
        ->create(['user_id' => $this->reviewer->id, 'spot_id' => $this->spot->id, 'rating' => 1]);

    expect($this->spot->fresh()->rating_average)->toBe(1.0);

    actingAsReviewer($this->reviewer);
    $this->patchJson("/api/v1/reviews/{$review->uuid}", ['rating' => 5], orgHeader($this->organization))
        ->assertOk();

    expect($this->spot->fresh()->rating_average)->toBe(5.0)
        ->and($this->spot->fresh()->reviews_count)->toBe(1);
});

test('deleting a review removes it from the spot average', function (): void {
    $other = $this->createUserWithPermissions($this->organization, REVIEWER_PERMISSIONS);
    $mine = Review::factory()->for($this->organization)
        ->create(['user_id' => $this->reviewer->id, 'spot_id' => $this->spot->id, 'rating' => 5]);
    Review::factory()->for($this->organization)
        ->create(['user_id' => $other->id, 'spot_id' => $this->spot->id, 'rating' => 1]);

    expect($this->spot->fresh()->rating_average)->toBe(3.0);

    actingAsReviewer($this->reviewer);
    $this->deleteJson("/api/v1/reviews/{$mine->uuid}", [], orgHeader($this->organization))->assertOk();

    expect($this->spot->fresh()->rating_average)->toBe(1.0)
        ->and($this->spot->fresh()->reviews_count)->toBe(1);
});

test('a spot with no reviews reports a zero average, not null', function (): void {
    expect($this->spot->fresh()->rating_average)->toBe(0.0)
        ->and($this->spot->fresh()->reviews_count)->toBe(0);
});

// ---------------------------------------------------------------------------
// The one-per-explorer rule
// ---------------------------------------------------------------------------

test('a second review of the same spot is rejected as a validation error', function (): void {
    Review::factory()->for($this->organization)
        ->create(['user_id' => $this->reviewer->id, 'spot_id' => $this->spot->id]);

    actingAsReviewer($this->reviewer);

    $this->postJson('/api/v1/reviews', [
        'spot_uuid' => $this->spot->uuid,
        'rating' => 5,
    ], orgHeader($this->organization))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['spot_uuid']);
});

test('two different explorers may review the same spot', function (): void {
    $other = $this->createUserWithPermissions($this->organization, REVIEWER_PERMISSIONS);

    actingAsReviewer($this->reviewer);
    $this->postJson('/api/v1/reviews', [
        'spot_uuid' => $this->spot->uuid, 'rating' => 4,
    ], orgHeader($this->organization))->assertCreated();

    actingAsReviewer($other);
    $this->postJson('/api/v1/reviews', [
        'spot_uuid' => $this->spot->uuid, 'rating' => 2,
    ], orgHeader($this->organization))->assertCreated();

    expect($this->spot->fresh()->reviews_count)->toBe(2);
});

// ---------------------------------------------------------------------------
// Permissions
// ---------------------------------------------------------------------------

test('review endpoints reject an unauthenticated caller', function (string $method, string $uri): void {
    $this->json($method, $uri, [], orgHeader($this->organization))->assertUnauthorized();
})->with([
    ['get', '/api/v1/reviews'],
    ['post', '/api/v1/reviews'],
]);

test('listing reviews is denied without the view permission', function (): void {
    actingAsReviewer($this->createUserWithPermissions($this->organization, []));

    $this->getJson('/api/v1/reviews', orgHeader($this->organization))->assertForbidden();
});

test('one explorer cannot edit or delete another explorer\'s review', function (): void {
    $other = $this->createUserWithPermissions($this->organization, REVIEWER_PERMISSIONS);
    $review = Review::factory()->for($this->organization)
        ->create(['user_id' => $other->id, 'spot_id' => $this->spot->id]);

    actingAsReviewer($this->reviewer);

    $this->patchJson("/api/v1/reviews/{$review->uuid}", ['rating' => 1], orgHeader($this->organization))
        ->assertForbidden();
    $this->deleteJson("/api/v1/reviews/{$review->uuid}", [], orgHeader($this->organization))
        ->assertForbidden();
});

test('a moderator edits any review', function (): void {
    $moderator = $this->createUserWithPermissions(
        $this->organization, [...REVIEWER_PERMISSIONS, 'stourify.reviews.manage'],
    );
    $review = Review::factory()->for($this->organization)
        ->create(['user_id' => $this->reviewer->id, 'spot_id' => $this->spot->id]);

    actingAsReviewer($moderator);

    $this->patchJson("/api/v1/reviews/{$review->uuid}", ['rating' => 1], orgHeader($this->organization))
        ->assertOk()
        ->assertJsonPath('data.rating', 1);
});

// ---------------------------------------------------------------------------
// Validation
// ---------------------------------------------------------------------------

test('a review requires a spot and a rating in range', function (): void {
    actingAsReviewer($this->reviewer);

    $this->postJson('/api/v1/reviews', [], orgHeader($this->organization))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['spot_uuid', 'rating']);

    $this->postJson('/api/v1/reviews', [
        'spot_uuid' => $this->spot->uuid, 'rating' => 9,
    ], orgHeader($this->organization))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['rating']);
});

test('a review cannot be moved to a different spot', function (): void {
    $otherSpot = Spot::factory()->for($this->organization)
        ->create(['user_id' => $this->reviewer->id, 'status' => SpotStatus::Published]);
    $review = Review::factory()->for($this->organization)
        ->create(['user_id' => $this->reviewer->id, 'spot_id' => $this->spot->id]);

    actingAsReviewer($this->reviewer);
    $this->patchJson("/api/v1/reviews/{$review->uuid}", [
        'spot_uuid' => $otherSpot->uuid,
    ], orgHeader($this->organization))->assertOk();

    expect($review->fresh()->spot_id)->toBe($this->spot->id);
});

test('helpful_count is not writable by the author', function (): void {
    $review = Review::factory()->for($this->organization)
        ->create(['user_id' => $this->reviewer->id, 'spot_id' => $this->spot->id, 'helpful_count' => 0]);

    actingAsReviewer($this->reviewer);
    $this->patchJson("/api/v1/reviews/{$review->uuid}", [
        'helpful_count' => 999,
    ], orgHeader($this->organization))->assertOk();

    expect($review->fresh()->helpful_count)->toBe(0);
});

/**
 * STOURIFY-23 — see SpotApiTest for the ordering this asserts.
 */
test('creating a review is denied without the create permission, whatever the payload', function (): void {
    actingAsReviewer($this->createUserWithPermissions($this->organization, ['stourify.reviews.view']));

    $before = Review::query()->count();

    $this->postJson('/api/v1/reviews', [
        'spot_uuid' => $this->spot->uuid,
        'rating' => 5,
    ], orgHeader($this->organization))->assertForbidden();

    $this->postJson('/api/v1/reviews', ['rating' => 99], orgHeader($this->organization))
        ->assertForbidden();

    expect(Review::query()->count())->toBe($before);
});
