<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Social\Events\PostCreated;
use Modules\Social\Models\Post;
use Modules\Stourify\Models\Spot;

uses(RefreshDatabase::class);

test('PostCreated event creates a spot when location data is provided', function () {
    $user = User::factory()->create();
    $post = Post::factory()->create(['user_id' => $user->id, 'spot_id' => null]);

    $postData = [
        'spot_name'      => 'Test Beach',
        'spot_latitude'  => 6.1,
        'spot_longitude' => 125.1,
    ];

    event(new PostCreated($post, $postData));

    $post->refresh();

    expect($post->spot_id)->not->toBeNull();
    expect(Spot::where('name', 'Test Beach')->exists())->toBeTrue();
});

test('PostCreated event links to existing spot when coordinates match', function () {
    $user = User::factory()->create();
    $existing = Spot::factory()->create([
        'name'       => 'Test Beach',
        'latitude'   => 6.1,
        'longitude'  => 125.1,
        'created_by' => $user->id,
    ]);

    $post = Post::factory()->create(['user_id' => $user->id, 'spot_id' => null]);

    $postData = [
        'spot_name'      => 'Test Beach',
        'spot_latitude'  => 6.1,
        'spot_longitude' => 125.1,
    ];

    event(new PostCreated($post, $postData));

    $post->refresh();

    expect($post->spot_id)->toBe($existing->id);
    expect(Spot::count())->toBe(1);
});

test('PostCreated event skips spot creation when spot_id already set', function () {
    $user = User::factory()->create();
    $spot = Spot::factory()->create(['created_by' => $user->id]);
    $post = Post::factory()->create(['user_id' => $user->id, 'spot_id' => $spot->id]);

    event(new PostCreated($post, ['spot_id' => $spot->uuid]));

    expect(Spot::count())->toBe(1);
});

test('PostCreated event skips when no location data provided', function () {
    $user = User::factory()->create();
    $post = Post::factory()->create(['user_id' => $user->id, 'spot_id' => null]);

    event(new PostCreated($post, []));

    $post->refresh();

    expect($post->spot_id)->toBeNull();
    expect(Spot::count())->toBe(0);
});
