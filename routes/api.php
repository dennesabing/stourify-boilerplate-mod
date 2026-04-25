<?php

use Illuminate\Support\Facades\Route;
use Modules\Stourify\Http\Controllers\Api\V1\SpotController;

Route::prefix('api/v1')->middleware(['api', 'auth:sanctum'])->group(function () {
    Route::get('spots', [SpotController::class, 'index'])->name('api.v1.spots.index');
    Route::post('spots', [SpotController::class, 'store'])->name('api.v1.spots.store');
    Route::get('spots/{spot}', [SpotController::class, 'show'])->name('api.v1.spots.show');
    Route::put('spots/{spot}', [SpotController::class, 'update'])->name('api.v1.spots.update');
    Route::get('spots/{spot}/posts', [SpotController::class, 'posts'])->name('api.v1.spots.posts');
    Route::get('spots/{spot}/media', [SpotController::class, 'media'])->name('api.v1.spots.media');
});
