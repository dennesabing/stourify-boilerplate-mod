<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Stourify\Models\Spot;
use Modules\Stourify\Models\SpotCategory;
use Modules\Stourify\Models\SpotTag;

uses(RefreshDatabase::class);

test('spot has a uuid on creation', function () {
    $spot = Spot::factory()->create();
    expect($spot->uuid)->not->toBeNull()->toBeString();
});

test('spot belongs to category', function () {
    $category = SpotCategory::factory()->create();
    $spot = Spot::factory()->create(['category_id' => $category->id]);
    expect($spot->category->id)->toBe($category->id);
});

test('spot can have tags', function () {
    $spot = Spot::factory()->create();
    $tag = SpotTag::factory()->create();
    $spot->tags()->attach($tag);
    expect($spot->tags()->count())->toBe(1);
});
