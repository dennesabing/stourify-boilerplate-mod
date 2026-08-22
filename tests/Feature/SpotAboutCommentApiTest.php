<?php

declare(strict_types=1);

use App\Models\Comment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Stourify\Models\Spot;
use Modules\Stourify\Models\SpotAbout;
use Tests\Traits\InteractsWithTestSetup;

uses(RefreshDatabase::class, InteractsWithTestSetup::class);

/**
 * What an ordinary explorer holds here: the right to see About entries, and
 * the two comment permissions the platform discovers from the model's
 * `HasComments` trait plus its `spot_abouts` permission prefix. Nothing in the
 * codebase types those two names by hand.
 *
 * @var list<string>
 */
const ABOUT_COMMENTER_PERMISSIONS = [
    'stourify.spot_abouts.view',
    'stourify.spot_abouts.create',
    'spot_abouts.comments.view',
    'spot_abouts.comments.create',
];

beforeEach(function (): void {
    $this->organization = $this->setUpTestOrganization();
    $this->seedPermissions(ABOUT_COMMENTER_PERMISSIONS);

    $this->author = $this->createUserWithPermissions($this->organization, ABOUT_COMMENTER_PERMISSIONS);
    $this->commenter = $this->createUserWithPermissions($this->organization, ABOUT_COMMENTER_PERMISSIONS);

    $this->spot = Spot::factory()->create([
        'organization_id' => $this->organization->id,
        'user_id' => $this->author->id,
    ]);

    $this->about = makeCommentableAbout($this->author);
});

/**
 * An About entry on the test's spot, written by the given user.
 */
function makeCommentableAbout(User $author, array $attributes = []): SpotAbout
{
    return SpotAbout::factory()->create([
        'organization_id' => test()->organization->id,
        'spot_id' => test()->spot->id,
        'user_id' => $author->id,
        ...$attributes,
    ]);
}

/**
 * Write a comment row straight onto an entry.
 *
 * `commentable_type` is the fully-qualified class name rather than the
 * `stourify_spot_about` alias, because that is what the adapter under test
 * writes — see `SpotAboutCommentApiController::commentableType()` and
 * STOURIFY-12. A test that seeded the alias would be asserting against rows the
 * application never produces.
 */
function seedAboutComment(SpotAbout $about, User $user, string $body, array $attributes = []): Comment
{
    return Comment::factory()->create([
        'organization_id' => test()->organization->id,
        'commentable_type' => SpotAbout::class,
        'commentable_id' => $about->id,
        'user_id' => $user->id,
        'body' => $body,
        ...$attributes,
    ]);
}

// ---------------------------------------------------------------------------
// Listing a thread
// ---------------------------------------------------------------------------

test('an entry comments are listed newest-first, with the commenter loaded', function (): void {
    Sanctum::actingAs($this->commenter);

    $older = seedAboutComment($this->about, $this->commenter, 'First!', ['created_at' => now()->subMinutes(5)]);
    $newer = seedAboutComment($this->about, $this->author, 'Still true in 2026.', ['created_at' => now()]);

    $response = $this->getJson(
        "/api/v1/spot-abouts/{$this->about->uuid}/comments",
        orgHeader($this->organization),
    )->assertOk();

    $ids = collect($response->json('data'))->pluck('id');

    expect($ids->first())->toBe($newer->uuid)
        ->and($ids->last())->toBe($older->uuid)
        ->and($response->json('data.0.user.id'))->toBe($this->author->uuid)
        ->and($response->json('meta'))->not->toBeNull();
});

test('a thread carries only its own entry comments', function (): void {
    Sanctum::actingAs($this->commenter);

    $other = makeCommentableAbout($this->author);

    seedAboutComment($this->about, $this->commenter, 'On this entry.');
    seedAboutComment($other, $this->commenter, 'On the other entry.');

    $bodies = collect($this->getJson(
        "/api/v1/spot-abouts/{$this->about->uuid}/comments",
        orgHeader($this->organization),
    )->assertOk()->json('data.*.body'));

    expect($bodies->all())->toBe(['On this entry.']);
});

// ---------------------------------------------------------------------------
// Writing a comment
// ---------------------------------------------------------------------------

test('a comment is created on an entry and resolves back to it', function (): void {
    Sanctum::actingAs($this->commenter);

    $response = $this->postJson("/api/v1/spot-abouts/{$this->about->uuid}/comments", [
        'body' => 'The side gate is shut on Sundays.',
    ], orgHeader($this->organization))
        ->assertCreated()
        ->assertJsonPath('data.body', 'The side gate is shut on Sundays.')
        ->assertJsonPath('data.user.id', $this->commenter->uuid);

    $comment = Comment::query()->where('uuid', $response->json('data.id'))->firstOrFail();

    expect($comment->commentable_id)->toBe($this->about->id)
        ->and($comment->commentable)->not->toBeNull()
        ->and($comment->commentable->uuid)->toBe($this->about->uuid);
});

/**
 * A reply is created and read back using only what a client can actually see.
 *
 * The old version of this test asked the DATABASE for the parent's numeric id
 * and sent that. It passed, and it proved nothing a phone could repeat: no
 * response from this API has ever contained a numeric comment id, so no client
 * could ever have constructed the request the test was making (STOURIFY-152).
 * This version uses the parent's uuid, which is the only identifier a client
 * has, in both directions.
 */
test('a reply is created by the parent uuid and comes back carrying it', function (): void {
    Sanctum::actingAs($this->commenter);

    $parent = seedAboutComment($this->about, $this->author, 'Is it open in winter?');

    $response = $this->postJson("/api/v1/spot-abouts/{$this->about->uuid}/comments", [
        'body' => 'Only at weekends.',
        'parent_id' => $parent->uuid,
    ], orgHeader($this->organization))->assertCreated();

    expect($response->json('data.parent_id'))->toBe($parent->uuid);

    $reply = Comment::query()->where('uuid', $response->json('data.id'))->firstOrFail();
    expect($reply->parent_id)->toBe($parent->id);
});

/**
 * The listing is what the app builds its indented thread out of, so the join it
 * performs has to be possible: a reply's `parent_id` must equal some other
 * row's `id` in the very same payload.
 */
test('a listed reply points at a parent that is in the same payload', function (): void {
    Sanctum::actingAs($this->commenter);

    $parent = seedAboutComment($this->about, $this->author, 'Is it open in winter?');
    seedAboutComment($this->about, $this->commenter, 'Only at weekends.', ['parent_id' => $parent->id]);

    $rows = $this->getJson("/api/v1/spot-abouts/{$this->about->uuid}/comments", orgHeader($this->organization))
        ->assertOk()
        ->json('data');

    $ids = array_column($rows, 'id');
    $parentIds = array_filter(array_column($rows, 'parent_id'));

    expect($parentIds)->toHaveCount(1)
        ->and(array_values($parentIds)[0])->toBe($parent->uuid)
        ->and($ids)->toContain(array_values($parentIds)[0]);
});

/**
 * Reading a parent's uuid must not cost a query per row.
 *
 * `CommentResource` reaches through the `parent` relation to report a reply's
 * parent by uuid, which is the classic way a one-line resource change turns one
 * query into sixteen. The controller eager-loads it; this is what notices if
 * that is ever removed.
 *
 * It counts only the statements that read the `comments` table, and asks
 * whether that count GROWS when the thread does. An absolute number would have
 * to be rewritten whenever anything unrelated on this route asked the database
 * a question, and "does not grow with the number of replies" is the property an
 * N+1 actually breaks. The cache is flushed between the two measurements
 * because this endpoint caches its answer, and a second identical request asks
 * the database nothing at all.
 */
test('listing a thread does not read the comments table once per reply', function (): void {
    Sanctum::actingAs($this->commenter);

    $countCommentReads = function (int $replies): int {
        Cache::flush();
        Comment::query()->forceDelete();

        $parent = seedAboutComment($this->about, $this->author, 'Is it open in winter?');

        foreach (range(1, $replies) as $n) {
            seedAboutComment($this->about, $this->commenter, "Reply {$n}.", ['parent_id' => $parent->id]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->getJson("/api/v1/spot-abouts/{$this->about->uuid}/comments", orgHeader($this->organization))
            ->assertOk()
            ->assertJsonCount($replies + 1, 'data');

        $reads = count(array_filter(
            DB::getQueryLog(),
            fn (array $entry): bool => str_contains($entry['query'], 'comments'),
        ));

        DB::disableQueryLog();

        return $reads;
    };

    expect($countCommentReads(6))->toBe($countCommentReads(2));
});

test('a parent_id belonging to another entry is rejected', function (): void {
    Sanctum::actingAs($this->commenter);

    $other = makeCommentableAbout($this->author);
    $foreignParent = seedAboutComment($other, $this->author, 'On a different entry.');

    $this->postJson("/api/v1/spot-abouts/{$this->about->uuid}/comments", [
        'body' => 'Mismatched thread.',
        'parent_id' => $foreignParent->uuid,
    ], orgHeader($this->organization))->assertUnprocessable();

    $this->assertDatabaseMissing('comments', ['body' => 'Mismatched thread.']);
});

test('a parent_id that is not a uuid at all is rejected', function (): void {
    Sanctum::actingAs($this->commenter);

    $parent = seedAboutComment($this->about, $this->author, 'Is it open in winter?');

    $this->postJson("/api/v1/spot-abouts/{$this->about->uuid}/comments", [
        'body' => 'Sent the internal number instead.',
        'parent_id' => $parent->id,
    ], orgHeader($this->organization))->assertUnprocessable();

    $this->assertDatabaseMissing('comments', ['body' => 'Sent the internal number instead.']);
});

test('an empty body is rejected', function (): void {
    Sanctum::actingAs($this->commenter);

    $this->postJson("/api/v1/spot-abouts/{$this->about->uuid}/comments", [
        'body' => '',
    ], orgHeader($this->organization))->assertUnprocessable();
});

// ---------------------------------------------------------------------------
// Who is allowed in
// ---------------------------------------------------------------------------

test('a user who cannot see About entries at all is refused both endpoints', function (): void {
    $outsider = $this->createUserWithPermissions($this->organization, [
        'spot_abouts.comments.view',
        'spot_abouts.comments.create',
    ]);

    Sanctum::actingAs($outsider);

    $this->getJson("/api/v1/spot-abouts/{$this->about->uuid}/comments", orgHeader($this->organization))
        ->assertForbidden();

    $this->postJson("/api/v1/spot-abouts/{$this->about->uuid}/comments", [
        'body' => 'Let me in.',
    ], orgHeader($this->organization))->assertForbidden();
});

test('a user who can see the entry but holds no comment permission is refused', function (): void {
    $reader = $this->createUserWithPermissions($this->organization, ['stourify.spot_abouts.view']);

    Sanctum::actingAs($reader);

    $this->getJson("/api/v1/spot-abouts/{$this->about->uuid}/comments", orgHeader($this->organization))
        ->assertForbidden();

    $this->postJson("/api/v1/spot-abouts/{$this->about->uuid}/comments", [
        'body' => 'Reading only.',
    ], orgHeader($this->organization))->assertForbidden();
});

test('a caller who cannot write comments is refused BEFORE the payload is validated', function (): void {
    $reader = $this->createUserWithPermissions($this->organization, [
        'stourify.spot_abouts.view',
        'spot_abouts.comments.view',
    ]);

    Sanctum::actingAs($reader);

    // The body is invalid as well as unauthorized. A 422 here would mean the
    // server validated first and told an unauthorized caller what it wanted —
    // the ordering defect STOURIFY-23 records.
    $this->postJson("/api/v1/spot-abouts/{$this->about->uuid}/comments", [
        'body' => '',
    ], orgHeader($this->organization))->assertForbidden();
});

// ---------------------------------------------------------------------------
// The count on the entry itself
// ---------------------------------------------------------------------------

test('an entry carries the number of comments written on it', function (): void {
    Sanctum::actingAs($this->commenter);

    foreach (['One.', 'Two.', 'Three.'] as $body) {
        $this->postJson("/api/v1/spot-abouts/{$this->about->uuid}/comments", [
            'body' => $body,
        ], orgHeader($this->organization))->assertCreated();
    }

    $list = $this->getJson(
        '/api/v1/spot-abouts?spot_uuid='.$this->spot->uuid,
        orgHeader($this->organization),
    )->assertOk();

    expect($list->json('data.0.comments_count'))->toBe(3);

    $show = $this->getJson(
        "/api/v1/spot-abouts/{$this->about->uuid}",
        orgHeader($this->organization),
    )->assertOk();

    expect($show->json('data.comments_count'))->toBe(3);
});

test('a fresh entry reports zero comments rather than omitting the field', function (): void {
    Sanctum::actingAs($this->commenter);

    $show = $this->getJson(
        "/api/v1/spot-abouts/{$this->about->uuid}",
        orgHeader($this->organization),
    )->assertOk();

    expect($show->json('data.comments_count'))->toBe(0);
});

test('the comment count does not cost a query per entry', function (): void {
    Sanctum::actingAs($this->commenter);

    for ($i = 0; $i < 5; $i++) {
        $about = makeCommentableAbout($this->author);
        seedAboutComment($about, $this->commenter, "Comment {$i}.");
    }

    DB::enableQueryLog();
    DB::flushQueryLog();

    $this->getJson(
        '/api/v1/spot-abouts?spot_uuid='.$this->spot->uuid,
        orgHeader($this->organization),
    )->assertOk()->assertJsonCount(6, 'data');

    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    // `withCount` is one aggregate over the whole page. A per-row count would
    // put six extra queries on top of what the list already costs.
    expect($queries)->toBeLessThan(20);
});
