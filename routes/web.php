<?php

use Illuminate\Support\Facades\Route;
use Modules\Stourify\Http\Controllers\Admin\ModerationController;
use Modules\Stourify\Http\Controllers\Admin\SpotAdminController;
use Modules\Stourify\Http\Controllers\Admin\UserFollowController;

Route::prefix('admin/stourify')
    ->middleware(['web', 'auth', 'admin'])
    ->name('stourify.admin.')
    ->group(function () {

        Route::middleware('can:stourify.spots.manage')->group(function () {
            Route::get('spots', [SpotAdminController::class, 'index'])->name('spots.index');
            Route::get('spots/create', [SpotAdminController::class, 'create'])->name('spots.create');
            Route::post('spots', [SpotAdminController::class, 'store'])->name('spots.store');
            Route::get('spots/{spot}', [SpotAdminController::class, 'show'])->name('spots.show');
            Route::put('spots/{spot}', [SpotAdminController::class, 'update'])->name('spots.update');
            Route::delete('spots/{spot}', [SpotAdminController::class, 'destroy'])->name('spots.destroy');
            Route::post('spots/{spot}/merge', [SpotAdminController::class, 'merge'])->name('spots.merge');

            Route::get('users/{user}/follow-graph', [UserFollowController::class, 'show'])->name('users.follow-graph');
        });

        Route::middleware('can:stourify.moderation.manage')->group(function () {
            Route::get('moderation/queue', [ModerationController::class, 'queue'])->name('moderation.queue');
            Route::get('moderation/posts', [ModerationController::class, 'posts'])->name('moderation.posts');
            Route::get('moderation/comments', [ModerationController::class, 'comments'])->name('moderation.comments');
            Route::delete('moderation/reports/{report}/dismiss', [ModerationController::class, 'dismiss'])->name('moderation.dismiss');
            Route::delete('moderation/{type}/{id}/warn', [ModerationController::class, 'deleteAndWarn'])->name('moderation.warn');
        });
    });
