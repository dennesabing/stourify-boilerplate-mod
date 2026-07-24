<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Stourify\Enums\PostVisibility;
use Modules\Stourify\Enums\ReportReason;
use Modules\Stourify\Enums\ReportStatus;
use Modules\Stourify\Enums\SpotStatus;
use Modules\Stourify\Models\Post;
use Modules\Stourify\Models\Report;
use Modules\Stourify\Models\Review;
use Modules\Stourify\Models\Spot;
use Tests\Traits\InteractsWithTestSetup;

uses(RefreshDatabase::class, InteractsWithTestSetup::class);

/**
 * @var list<string>
 */
const REPORTER_PERMISSIONS = ['stourify.reports.create'];

/**
 * @var list<string>
 */
const MODERATOR_PERMISSIONS = ['stourify.reports.create', 'stourify.reports.manage'];

beforeEach(function (): void {
    $this->organization = $this->setUpTestOrganization();
    $this->seedPermissions(['stourify.reports.create', 'stourify.reports.manage']);

    $this->reporter = $this->createUserWithPermissions($this->organization, REPORTER_PERMISSIONS);
    $this->moderator = $this->createUserWithPermissions($this->organization, MODERATOR_PERMISSIONS);

    $this->spot = Spot::factory()->for($this->organization)->create([
        'user_id' => $this->createUserWithPermissions($this->organization, [])->id,
        'status' => SpotStatus::Published,
    ]);
});

function actingAsReporter(User $user): void
{
    Sanctum::actingAs($user);
}

// ---------------------------------------------------------------------------
// Filing — the polymorphic subject
// ---------------------------------------------------------------------------

test('an explorer reports a spot', function (): void {
    actingAsReporter($this->reporter);

    $this->postJson('/api/v1/reports', [
        'reportable_type' => 'spot',
        'reportable_uuid' => $this->spot->uuid,
        'reason' => ReportReason::Spam->value,
    ], orgHeader($this->organization))
        ->assertCreated()
        ->assertJsonPath('data.subject.type', 'spot')
        ->assertJsonPath('data.subject.uuid', $this->spot->uuid)
        ->assertJsonPath('data.status', ReportStatus::Pending->value);

    $this->assertDatabaseHas('sto_reports', [
        'user_id' => $this->reporter->id,
        'reportable_type' => 'stourify_spot',
        'reportable_id' => $this->spot->id,
        'reason' => ReportReason::Spam->value,
    ]);
});

test('reports cover posts, reviews and users through one flow', function (string $token, callable $make): void {
    $subject = $make($this);

    actingAsReporter($this->reporter);
    $this->postJson('/api/v1/reports', [
        'reportable_type' => $token,
        'reportable_uuid' => $subject->uuid,
        'reason' => ReportReason::Inappropriate->value,
    ], orgHeader($this->organization))
        ->assertCreated()
        ->assertJsonPath('data.subject.type', $token)
        ->assertJsonPath('data.subject.uuid', $subject->uuid);
})->with([
    'post' => ['post', fn ($t) => Post::factory()->for($t->organization)->create([
        'user_id' => $t->spot->user_id, 'spot_id' => $t->spot->id,
        'visibility' => PostVisibility::Public, 'published_at' => now(),
    ])],
    'review' => ['review', fn ($t) => Review::factory()->for($t->organization)->create([
        'user_id' => $t->reporter->id, 'spot_id' => $t->spot->id,
    ])],
    'user' => ['user', fn ($t) => $t->spot->user],
]);

test('the stored morph value is an alias for module models and an fqcn for users', function (): void {
    $target = $this->createUserWithPermissions($this->organization, []);

    actingAsReporter($this->reporter);
    $this->postJson('/api/v1/reports', [
        'reportable_type' => 'user',
        'reportable_uuid' => $target->uuid,
        'reason' => ReportReason::Harassment->value,
    ], orgHeader($this->organization))->assertCreated();

    $this->assertDatabaseHas('sto_reports', [
        'reportable_type' => (new User)->getMorphClass(),
        'reportable_id' => $target->id,
    ]);
});

// ---------------------------------------------------------------------------
// Idempotency
// ---------------------------------------------------------------------------

test('reporting the same thing twice returns the existing report, not an error', function (): void {
    actingAsReporter($this->reporter);

    $first = $this->postJson('/api/v1/reports', [
        'reportable_type' => 'spot', 'reportable_uuid' => $this->spot->uuid,
        'reason' => ReportReason::Spam->value,
    ], orgHeader($this->organization))->assertCreated()->json('data.uuid');

    $second = $this->postJson('/api/v1/reports', [
        'reportable_type' => 'spot', 'reportable_uuid' => $this->spot->uuid,
        'reason' => ReportReason::Harassment->value,
    ], orgHeader($this->organization))->assertOk()->json('data.uuid');

    expect($second)->toBe($first);
    expect(Report::query()->where('user_id', $this->reporter->id)->count())->toBe(1);
});

test('two explorers may report the same thing', function (): void {
    $other = $this->createUserWithPermissions($this->organization, REPORTER_PERMISSIONS);

    actingAsReporter($this->reporter);
    $this->postJson('/api/v1/reports', [
        'reportable_type' => 'spot', 'reportable_uuid' => $this->spot->uuid,
        'reason' => ReportReason::Spam->value,
    ], orgHeader($this->organization))->assertCreated();

    actingAsReporter($other);
    $this->postJson('/api/v1/reports', [
        'reportable_type' => 'spot', 'reportable_uuid' => $this->spot->uuid,
        'reason' => ReportReason::Spam->value,
    ], orgHeader($this->organization))->assertCreated();

    expect(Report::query()->count())->toBe(2);
});

// ---------------------------------------------------------------------------
// The queue and resolution — moderators only
// ---------------------------------------------------------------------------

test('a moderator sees the queue; a reporter cannot', function (): void {
    Report::factory()->for($this->organization)->create([
        'user_id' => $this->reporter->id,
        'reportable_type' => 'stourify_spot', 'reportable_id' => $this->spot->id,
    ]);

    actingAsReporter($this->reporter);
    $this->getJson('/api/v1/reports', orgHeader($this->organization))->assertForbidden();

    actingAsReporter($this->moderator);
    $this->getJson('/api/v1/reports', orgHeader($this->organization))
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

test('the open filter hides resolved reports', function (): void {
    Report::factory()->for($this->organization)->create([
        'user_id' => $this->reporter->id,
        'reportable_type' => 'stourify_spot', 'reportable_id' => $this->spot->id,
    ]);
    Report::factory()->for($this->organization)->actioned()->create([
        'user_id' => $this->createUserWithPermissions($this->organization, [])->id,
        'reportable_type' => 'stourify_spot', 'reportable_id' => $this->spot->id,
    ]);

    actingAsReporter($this->moderator);
    $this->getJson('/api/v1/reports?open=1', orgHeader($this->organization))
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

test('a moderator actions a report, stamping who and when', function (): void {
    $report = Report::factory()->for($this->organization)->create([
        'user_id' => $this->reporter->id,
        'reportable_type' => 'stourify_spot', 'reportable_id' => $this->spot->id,
    ]);

    actingAsReporter($this->moderator);
    $this->postJson("/api/v1/reports/{$report->uuid}/resolve", [
        'status' => ReportStatus::Actioned->value,
        'resolution_note' => 'Removed the spot.',
    ], orgHeader($this->organization))
        ->assertOk()
        ->assertJsonPath('data.status', ReportStatus::Actioned->value)
        ->assertJsonPath('data.resolution.note', 'Removed the spot.')
        ->assertJsonPath('data.resolution.resolved_by_uuid', $this->moderator->uuid);

    expect($report->fresh()->resolved_at)->not->toBeNull();
});

test('moving a report to reviewing clears any resolution stamp', function (): void {
    $report = Report::factory()->for($this->organization)->actioned()->create([
        'user_id' => $this->reporter->id, 'resolved_by_id' => $this->moderator->id,
        'reportable_type' => 'stourify_spot', 'reportable_id' => $this->spot->id,
    ]);

    actingAsReporter($this->moderator);
    $this->postJson("/api/v1/reports/{$report->uuid}/resolve", [
        'status' => ReportStatus::Reviewing->value,
    ], orgHeader($this->organization))->assertOk();

    expect($report->fresh()->resolved_at)->toBeNull()
        ->and($report->fresh()->resolved_by_id)->toBeNull();
});

test('a reporter cannot resolve a report', function (): void {
    $report = Report::factory()->for($this->organization)->create([
        'user_id' => $this->reporter->id,
        'reportable_type' => 'stourify_spot', 'reportable_id' => $this->spot->id,
    ]);

    actingAsReporter($this->reporter);
    $this->postJson("/api/v1/reports/{$report->uuid}/resolve", [
        'status' => ReportStatus::Dismissed->value,
        'resolution_note' => 'Nothing to see here.',
    ], orgHeader($this->organization))->assertForbidden();
});

// ---------------------------------------------------------------------------
// Anonymity
// ---------------------------------------------------------------------------

test('the reporter identity reaches moderators but never rides on the subject', function (): void {
    $report = Report::factory()->for($this->organization)->create([
        'user_id' => $this->reporter->id,
        'reportable_type' => 'stourify_spot', 'reportable_id' => $this->spot->id,
    ]);

    // A moderator viewing the queue can see who filed it.
    actingAsReporter($this->moderator);
    $this->getJson("/api/v1/reports/{$report->uuid}", orgHeader($this->organization))
        ->assertOk()
        ->assertJsonPath('data.reporter_uuid', $this->reporter->uuid);

    // The report never exposes the reporter's email anywhere.
    $this->getJson("/api/v1/reports/{$report->uuid}", orgHeader($this->organization))
        ->assertJsonMissing(['email' => $this->reporter->email]);
});

// ---------------------------------------------------------------------------
// Validation and permission
// ---------------------------------------------------------------------------

test('an unknown reportable type is rejected', function (): void {
    actingAsReporter($this->reporter);

    $this->postJson('/api/v1/reports', [
        'reportable_type' => 'city',
        'reportable_uuid' => $this->spot->uuid,
        'reason' => ReportReason::Spam->value,
    ], orgHeader($this->organization))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['reportable_type']);
});

test('a missing subject is a 404', function (): void {
    actingAsReporter($this->reporter);

    $this->postJson('/api/v1/reports', [
        'reportable_type' => 'spot',
        'reportable_uuid' => '00000000-0000-4000-8000-000000000000',
        'reason' => ReportReason::Spam->value,
    ], orgHeader($this->organization))->assertNotFound();
});

test('the other reason requires details', function (): void {
    actingAsReporter($this->reporter);

    $this->postJson('/api/v1/reports', [
        'reportable_type' => 'spot', 'reportable_uuid' => $this->spot->uuid,
        'reason' => ReportReason::Other->value,
    ], orgHeader($this->organization))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['details']);

    $this->postJson('/api/v1/reports', [
        'reportable_type' => 'spot', 'reportable_uuid' => $this->spot->uuid,
        'reason' => ReportReason::Other->value,
        'details' => 'The photos are of a different place entirely.',
    ], orgHeader($this->organization))->assertCreated();
});

test('resolving requires a note for terminal outcomes and forbids one otherwise', function (): void {
    $report = Report::factory()->for($this->organization)->create([
        'user_id' => $this->reporter->id,
        'reportable_type' => 'stourify_spot', 'reportable_id' => $this->spot->id,
    ]);

    actingAsReporter($this->moderator);

    // Actioned with no note is rejected.
    $this->postJson("/api/v1/reports/{$report->uuid}/resolve", [
        'status' => ReportStatus::Actioned->value,
    ], orgHeader($this->organization))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['resolution_note']);

    // Reviewing with a note is rejected.
    $this->postJson("/api/v1/reports/{$report->uuid}/resolve", [
        'status' => ReportStatus::Reviewing->value,
        'resolution_note' => 'should not be here',
    ], orgHeader($this->organization))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['resolution_note']);
});

test('a report cannot be pushed back to pending', function (): void {
    $report = Report::factory()->for($this->organization)->create([
        'user_id' => $this->reporter->id,
        'reportable_type' => 'stourify_spot', 'reportable_id' => $this->spot->id,
    ]);

    actingAsReporter($this->moderator);
    $this->postJson("/api/v1/reports/{$report->uuid}/resolve", [
        'status' => ReportStatus::Pending->value,
    ], orgHeader($this->organization))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status']);
});

test('report endpoints reject an unauthenticated caller', function (string $method, string $uri): void {
    $this->json($method, $uri, [], orgHeader($this->organization))->assertUnauthorized();
})->with([
    ['get', '/api/v1/reports'],
    ['post', '/api/v1/reports'],
]);

test('filing is denied without the create permission', function (): void {
    actingAsReporter($this->createUserWithPermissions($this->organization, []));

    $this->postJson('/api/v1/reports', [
        'reportable_type' => 'spot', 'reportable_uuid' => $this->spot->uuid,
        'reason' => ReportReason::Spam->value,
    ], orgHeader($this->organization))->assertForbidden();
});
