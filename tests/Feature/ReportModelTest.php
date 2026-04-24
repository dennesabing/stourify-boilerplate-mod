<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Social\Models\Post;
use Modules\Stourify\Models\Report;

uses(RefreshDatabase::class);

test('report can be created with required fields', function () {
    $reporter = User::factory()->create();
    $post     = Post::factory()->create();

    $report = Report::create([
        'reporter_id'     => $reporter->id,
        'reportable_type' => Post::class,
        'reportable_id'   => $post->id,
        'reason'          => 'spam',
        'status'          => 'pending',
    ]);

    expect($report->uuid)->not->toBeNull();
    expect($report->status)->toBe('pending');
    expect($report->reporter_id)->toBe($reporter->id);
});

test('report belongs to reporter', function () {
    $reporter = User::factory()->create();
    $post     = Post::factory()->create();

    $report = Report::create([
        'reporter_id'     => $reporter->id,
        'reportable_type' => Post::class,
        'reportable_id'   => $post->id,
        'reason'          => 'inappropriate',
        'status'          => 'pending',
    ]);

    expect($report->reporter->id)->toBe($reporter->id);
});

test('status defaults to pending when not provided', function () {
    $reporter = User::factory()->create();
    $post     = Post::factory()->create();

    $report = Report::create([
        'reporter_id'     => $reporter->id,
        'reportable_type' => Post::class,
        'reportable_id'   => $post->id,
        'reason'          => 'spam',
    ]);

    expect($report->status)->toBe('pending');
});
