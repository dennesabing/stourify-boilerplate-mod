<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Stourify\Http\Controllers\Api\V1\PostApiController;
use Modules\Stourify\Http\Controllers\Api\V1\ReviewApiController;
use Modules\Stourify\Http\Controllers\Api\V1\SpotApiController;

/*
|--------------------------------------------------------------------------
| Stourify API (v1)
|--------------------------------------------------------------------------
|
| ModuleBaseServiceProvider::loadModuleRoutes() loads this file with no
| prefix, name or middleware of its own, so the group below declares all
| three — see saas-boilerplate/docs/system-wide-docs/system-routing.md.
|
| `set_organization_from_header` resolves X-Organization-Id into the
| OrganizationContext. Without it every model's BelongsToOrganization scope
| has no tenant to scope to. Stourify sends the single public organization's
| UUID, which the client reads from the login response and never hardcodes.
|
*/

Route::middleware(['api', 'auth:sanctum', 'set_organization_from_header'])
    ->prefix('api/v1')
    ->name('api.v1.')
    ->group(function (): void {

        Route::prefix('spots')->name('spots.')->group(function (): void {
            // Declared before the /{spot} wildcard so "nearby" is not swallowed
            // as a UUID and rejected by the route model binding.
            Route::get('/nearby', [SpotApiController::class, 'nearby'])->name('nearby');

            Route::get('/', [SpotApiController::class, 'index'])->name('index');
            Route::post('/', [SpotApiController::class, 'store'])->name('store');
            Route::get('/{spot}', [SpotApiController::class, 'show'])->name('show');
            Route::match(['put', 'patch'], '/{spot}', [SpotApiController::class, 'update'])->name('update');
            Route::delete('/{spot}', [SpotApiController::class, 'destroy'])->name('destroy');
        });

        Route::prefix('reviews')->name('reviews.')->group(function (): void {
            Route::get('/', [ReviewApiController::class, 'index'])->name('index');
            Route::post('/', [ReviewApiController::class, 'store'])->name('store');
            Route::get('/{review}', [ReviewApiController::class, 'show'])->name('show');
            Route::match(['put', 'patch'], '/{review}', [ReviewApiController::class, 'update'])->name('update');
            Route::delete('/{review}', [ReviewApiController::class, 'destroy'])->name('destroy');
        });

        Route::prefix('posts')->name('posts.')->group(function (): void {
            Route::get('/', [PostApiController::class, 'index'])->name('index');
            Route::post('/', [PostApiController::class, 'store'])->name('store');
            Route::get('/{post}', [PostApiController::class, 'show'])->name('show');
            Route::match(['put', 'patch'], '/{post}', [PostApiController::class, 'update'])->name('update');
            Route::delete('/{post}', [PostApiController::class, 'destroy'])->name('destroy');

            // Its own route, not a PATCH field: publishing is a one-way
            // transition with its own ability. See PostUpdateRequest.
            Route::post('/{post}/publish', [PostApiController::class, 'publish'])->name('publish');
        });
    });
