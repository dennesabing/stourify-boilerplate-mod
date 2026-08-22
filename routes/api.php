<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Stourify\Http\Controllers\Api\V1\BlockApiController;
use Modules\Stourify\Http\Controllers\Api\V1\FeedApiController;
use Modules\Stourify\Http\Controllers\Api\V1\FollowApiController;
use Modules\Stourify\Http\Controllers\Api\V1\PostApiController;
use Modules\Stourify\Http\Controllers\Api\V1\PostCommentApiController;
use Modules\Stourify\Http\Controllers\Api\V1\ProfileApiController;
use Modules\Stourify\Http\Controllers\Api\V1\ReportApiController;
use Modules\Stourify\Http\Controllers\Api\V1\ReviewApiController;
use Modules\Stourify\Http\Controllers\Api\V1\SearchApiController;
use Modules\Stourify\Http\Controllers\Api\V1\SpotAboutApiController;
use Modules\Stourify\Http\Controllers\Api\V1\SpotAboutCommentApiController;
use Modules\Stourify\Http\Controllers\Api\V1\SpotApiController;
use Modules\Stourify\Http\Controllers\Api\V1\SyncController;
use Modules\Stourify\Http\Controllers\Api\V1\WishlistApiController;

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

        // About entries — what visitors have written about a spot. A top-level
        // resource filtered by `spot_uuid` rather than a path nested under the
        // spot, matching how `posts` and `reviews` already read in this module.
        //
        // There is no `like` route here on purpose: the platform's
        // /api/v1/reactions endpoint addresses any host by type-alias plus UUID,
        // so `stourify_spot_about` works there already. See STOURIFY-145.
        Route::prefix('spot-abouts')->name('spot-abouts.')->group(function (): void {
            Route::get('/', [SpotAboutApiController::class, 'index'])->name('index');
            Route::post('/', [SpotAboutApiController::class, 'store'])->name('store');
            Route::get('/{about}', [SpotAboutApiController::class, 'show'])->name('show');
            Route::match(['put', 'patch'], '/{about}', [SpotAboutApiController::class, 'update'])->name('update');
            Route::delete('/{about}', [SpotAboutApiController::class, 'destroy'])->name('destroy');

            // The conversation in the margin of one entry. Same adapter shape,
            // and for the same reason, as the post pair below: the platform's
            // generic comment surface wants a numeric id and no Stourify
            // response carries one. Removing a comment needs no route here —
            // the platform's DELETE /api/v1/comments/{uuid} is already
            // addressed by uuid. See SpotAboutCommentApiController.
            Route::get('/{about}/comments', [SpotAboutCommentApiController::class, 'index'])->name('comments.index');
            Route::post('/{about}/comments', [SpotAboutCommentApiController::class, 'store'])->name('comments.store');
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

            // Comments live on the boilerplate's generic, polymorphic Comment
            // model; this nested pair addresses the post by uuid like every
            // other Stourify route, rather than the generic
            // /api/v1/comments?commentable_type=<FQCN>&commentable_id=<int>
            // surface, which PostResource has no numeric id to support. See
            // PostCommentApiController.
            Route::get('/{post}/comments', [PostCommentApiController::class, 'index'])->name('comments.index');
            Route::post('/{post}/comments', [PostCommentApiController::class, 'store'])->name('comments.store');
        });

        Route::prefix('follows')->name('follows.')->group(function (): void {
            // Before the /{follow} wildcard so "requests" is not read as a UUID.
            Route::get('/requests', [FollowApiController::class, 'requests'])->name('requests');

            Route::get('/', [FollowApiController::class, 'index'])->name('index');
            Route::post('/', [FollowApiController::class, 'store'])->name('store');
            Route::get('/{follow}', [FollowApiController::class, 'show'])->name('show');

            // Accepting is the followee's alone; unfollow, reject and
            // remove-a-follower are all the same delete from either side.
            Route::post('/{follow}/accept', [FollowApiController::class, 'accept'])->name('accept');
            Route::delete('/{follow}', [FollowApiController::class, 'destroy'])->name('destroy');
        });

        Route::prefix('blocks')->name('blocks.')->group(function (): void {
            // Three operations and no more — and deliberately no way to ask
            // who has blocked you. The index is the caller's own rows; the
            // blocked party has no ability on this resource at all. See
            // BlockApiController.
            Route::get('/', [BlockApiController::class, 'index'])->name('index');
            Route::post('/', [BlockApiController::class, 'store'])->name('store');
            Route::delete('/{block}', [BlockApiController::class, 'destroy'])->name('destroy');
        });

        Route::prefix('wishlist')->name('wishlist.')->group(function (): void {
            Route::get('/', [WishlistApiController::class, 'index'])->name('index');
            Route::post('/', [WishlistApiController::class, 'store'])->name('store');
            Route::get('/{wishlist}', [WishlistApiController::class, 'show'])->name('show');
            Route::match(['put', 'patch'], '/{wishlist}', [WishlistApiController::class, 'update'])->name('update');
            Route::delete('/{wishlist}', [WishlistApiController::class, 'destroy'])->name('destroy');
        });

        // The caller's own profile — singular, an upsert. Declared before the
        // /profiles/{user} reads so "profile" is never taken as a user UUID.
        Route::get('/profile', [ProfileApiController::class, 'me'])->name('profile.me');
        Route::match(['put', 'patch'], '/profile', [ProfileApiController::class, 'update'])->name('profile.update');

        // Any explorer's public header, keyed by their user UUID.
        Route::get('/profiles/{user}', [ProfileApiController::class, 'show'])->name('profiles.show');

        // The home feed — a ranked, cursor-paginated stream of visible posts.
        Route::get('/feed', [FeedApiController::class, 'index'])->name('feed');

        // Discovery search. Namespaced under /discover so it does not collide
        // with the boilerplate's generic /api/v1/search — see SearchApiController.
        Route::get('/discover/search', [SearchApiController::class, 'index'])->name('discover.search');

        // The offline-sync spine — a mobile WatermelonDB client's delta (pull)
        // and push (drain). No dedicated permission on the delta: it returns
        // only the caller's own data, and auth + org membership already gate
        // that. Push authorizes every operation through the same policies a
        // single write would, via CrudService — see SyncController.
        Route::prefix('stourify/sync')->name('stourify.sync.')->group(function (): void {
            Route::get('/delta', [SyncController::class, 'delta'])->name('delta');
            Route::post('/push', [SyncController::class, 'push'])->name('push');
        });

        Route::prefix('reports')->name('reports.')->group(function (): void {
            // Filing is open; the queue and resolution are moderator-gated in
            // ReportPolicy, not by route. See ReportApiController.
            Route::get('/', [ReportApiController::class, 'index'])->name('index');
            Route::post('/', [ReportApiController::class, 'store'])->name('store');
            Route::get('/{report}', [ReportApiController::class, 'show'])->name('show');
            Route::post('/{report}/resolve', [ReportApiController::class, 'resolve'])->name('resolve');
        });
    });
