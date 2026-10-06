<?php

use App\Http\Controllers\AboutController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AppearanceManagementController;
use App\Http\Controllers\AppearanceExportsController;
use App\Http\Controllers\CutieMarksController;
use App\Http\Controllers\DiscordController;
use App\Http\Controllers\EventEntriesController;
use App\Http\Controllers\AppearancesController;
use App\Http\Controllers\Auth\SigninController;
use App\Http\Controllers\Auth\SignupController;
use App\Http\Controllers\Auth\VerificationController;
use App\Http\Controllers\ColorGuideController;
use App\Http\Controllers\ColorGroupsController;
use App\Http\Controllers\ConfigController;
use App\Http\Controllers\Testing\TestFixturesController;
use App\Http\Controllers\Testing\TestLoginController;
use App\Http\Controllers\NoticesController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\EventsController;
use App\Http\Controllers\PersonalGuideController;
use App\Http\Controllers\PostManagementController;
use App\Http\Controllers\PostsController;
use App\Http\Controllers\ShowController;
use App\Http\Controllers\ShowManagementController;
use App\Http\Controllers\TagsController;
use App\Http\Controllers\UsefulLinksController;
use App\Http\Controllers\UserProfileController;
use App\Http\Controllers\UserPrefsController;
use App\Http\Controllers\UserEmailController;
use App\Http\Controllers\UsersController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\App;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

// The contract suite fires hundreds of requests, rate limits only apply outside the testing environment
$throttle = fn(int $per_minute) => App::environment('testing') ? 'throttle:100000,1' : "throttle:$per_minute,1";

Route::middleware($throttle(12))->group(function () {
    Route::prefix('users')->group(function () {
        Route::get('email/verify/{id}/{hash}', [VerificationController::class, 'verify'])->name('verification.verify');

        Route::post('signin', [SigninController::class, 'viaPassword'])->name('signin_password');
        Route::post('/', [SignupController::class, 'viaPassword'])->name('signup_password');
        Route::post('/oauth/signup/{provider}', [SignupController::class, 'viaSocialite']);
        Route::post('/oauth/signin/{provider}', [SigninController::class, 'viaSocialite']);
    });
});

if (App::environment('testing')) {
    Route::post('test/login/{id}', [TestLoginController::class, 'login'])->where('id', '[0-9]+');

    // The browser UI tests: cookie session sign-in and the fake DeviantArt (see TestFixturesController)
    Route::get('test/session-login/{id}', [TestFixturesController::class, 'session'])->where('id', '[0-9]+')
        ->middleware('web');
    Route::put('test/deviations/{id}', [TestFixturesController::class, 'deviation']);
    Route::delete('test/deviations/{id}', [TestFixturesController::class, 'forgetDeviation']);
    Route::put('test/club-gallery/{id}', [TestFixturesController::class, 'acceptIntoClub']);
    Route::delete('test/club-gallery/{id}', [TestFixturesController::class, 'rejectFromClub']);
}

Route::get('config', [ConfigController::class, 'get']);

Route::prefix('about')->group(function () {
    if (!App::isProduction()) {
        Route::get('sleep', [AboutController::class, 'sleep']);
    }

    Route::get('connection', [AboutController::class, 'serverInfo']);
    Route::get('members', [AboutController::class, 'members']);
});

Route::middleware([App::environment('testing') ? 'throttle:100000,1' : 'throttle:api', 'optional.auth'])->group(function () {
    Route::prefix('users')->group(function () {
        // Route::get('oauth/signup/{provider}', [SignupController::class, 'socialiteRedirect']);
        Route::get('oauth/signin/{provider}', [SigninController::class, 'socialiteRedirect']);

        Route::post('email/resend', [VerificationController::class, 'resend'])->name('verification.resend');

        Route::get('{id}/profile', [UserProfileController::class, 'profile'])->whereNumber('id');
        Route::get('{id}/contributions/{type}', [UserProfileController::class, 'contributions'])->whereNumber('id');
        Route::delete('{id}/contributions/cache', [UserProfileController::class, 'purgeContributionsCache'])->whereNumber('id')->middleware(['auth:sanctum', 'role:staff']);
        Route::get('{id}/personal-guide/appearances', [PersonalGuideController::class, 'appearances'])->whereNumber('id');

        Route::middleware('auth:sanctum')->group(function () {
            Route::get('{id}/personal-guide/point-history', [PersonalGuideController::class, 'pointHistory'])->whereNumber('id');
            Route::get('{id}/personal-guide/slots', [PersonalGuideController::class, 'slots'])->whereNumber('id');
            Route::post('{id}/personal-guide/point-history/recalculation', [PersonalGuideController::class, 'recalculate'])->whereNumber('id')->middleware('role:developer');
            Route::get('{id}/personal-guide/points', [PersonalGuideController::class, 'points'])->whereNumber('id')->middleware('role:staff');
            Route::post('{id}/personal-guide/points', [PersonalGuideController::class, 'givePoints'])->whereNumber('id')->middleware('role:staff');
            Route::post('{user_id}/discord/sync', [DiscordController::class, 'sync'])->whereNumber('user_id');
            Route::delete('{user_id}/discord', [DiscordController::class, 'unlink'])->whereNumber('user_id');
            Route::get('da-uuid/{uuid}', [UsersController::class, 'getByDaUuid'])->middleware('role:developer');
            Route::put('{id}/role', [UsersController::class, 'setRole'])->whereNumber('id')->middleware('role:staff');
            Route::get('{id}/preferences', [UserPrefsController::class, 'index'])->whereNumber('id');
            Route::get('{id}/preferences/{key}', [UserPrefsController::class, 'show'])->whereNumber('id');
            Route::put('{id}/preferences/{key}', [UserPrefsController::class, 'update'])->whereNumber('id');
            Route::get('me', [UsersController::class, 'me']);
            Route::post('{id}/email-changes', [UserEmailController::class, 'request'])->whereNumber('id')->middleware('role:staff');
            Route::post('email/verify', [UserEmailController::class, 'verify'])->middleware('role:staff');
            Route::post('me/password', [UsersController::class, 'setPassword'])->middleware('role:staff');
            Route::post('signout', [UsersController::class, 'signout']);
            Route::get('sessions', [UsersController::class, 'sessions']);
            Route::delete('sessions/{id}', [UsersController::class, 'deleteSession'])->where('id', '[0-9a-f]{64}');
            Route::get('tokens', [UsersController::class, 'tokens']);
            Route::delete('tokens/{token_id}', [UsersController::class, 'deleteToken']);

            Route::get('/', [UsersController::class, 'list'])->middleware('role:staff');
        });

        Route::get('{user}', [UsersController::class, 'getById'])->where('user', '[0-9]+');
        Route::get('da/{username}', [UsersController::class, 'getByName']);
    });

    Route::prefix('appearances')->group(function () {
        Route::get('/', [AppearancesController::class, 'queryPublic']);
        Route::get('full', [AppearancesController::class, 'queryFullPublic'])->name('appearances_full')->middleware('cacheResponse:300');
        Route::get('pinned', [AppearancesController::class, 'pinned'])->name('pinned_appearance')->middleware('cacheResponse:60');
        Route::get('autocomplete', [AppearancesController::class, 'autocomplete'])->name('appearances_autocomplete')->middleware('cacheResponse:180');
        Route::get('{appearance}', [AppearancesController::class, 'get']);
        Route::get('{appearance}/locate', [AppearancesController::class, 'locate']);
        Route::get('{appearance}/sprite', [AppearancesController::class, 'sprite'])->name('appearance_sprite');
        Route::get('{appearance}/color-groups', [AppearancesController::class, 'colorGroups']);
    });

    Route::middleware('optional.auth')->get('cutie-marks/{cutieMarkId}/{disposition}', [AppearanceExportsController::class, 'cutieMarkById'])
        ->whereNumber('cutieMarkId')->whereIn('disposition', ['image', 'download']);

    Route::middleware('optional.auth')->prefix('appearances')->group(function () {
        Route::get('{id}/palette', [AppearanceExportsController::class, 'palette'])->whereNumber('id');
        Route::get('{id}/image', [AppearanceExportsController::class, 'image'])->whereNumber('id');
        Route::get('{id}/cutie-marks/{cutieMarkId}/download', [AppearanceExportsController::class, 'cutieMark'])->whereNumber(['id', 'cutieMarkId']);
    });

    Route::middleware('auth:sanctum')->prefix('appearances')->group(function () {
        $manage = AppearanceManagementController::class;
        Route::post('/', [$manage, 'create']);
        Route::put('order', [$manage, 'order'])->middleware('role:staff');
        Route::get('{id}/metadata', [$manage, 'metadata'])->whereNumber('id');
        Route::put('{id}', [$manage, 'update'])->whereNumber('id');
        Route::delete('{id}', [$manage, 'destroy'])->whereNumber('id');
        Route::post('{id}/sprite', [$manage, 'uploadSprite'])->whereNumber('id');
        Route::delete('{id}/sprite', [$manage, 'deleteSprite'])->whereNumber('id');
        Route::post('{id}/template', [$manage, 'template'])->whereNumber('id');
        Route::delete('{id}/contents', [$manage, 'clear'])->whereNumber('id');
        Route::get('{id}/color-groups/order', [$manage, 'colorGroupOrder'])->whereNumber('id');
        Route::put('{id}/color-groups/order', [$manage, 'setColorGroupOrder'])->whereNumber('id');
        Route::get('{id}/relations', [$manage, 'relations'])->whereNumber('id');
        Route::put('{id}/relations', [$manage, 'setRelations'])->whereNumber('id');
        Route::get('{id}/tags', [$manage, 'tags'])->whereNumber('id');
        Route::put('{id}/tags', [$manage, 'setTags'])->whereNumber('id');
        Route::get('{id}/cutie-marks', [CutieMarksController::class, 'index'])->whereNumber('id');
        Route::put('{id}/cutie-marks', [CutieMarksController::class, 'replace'])->whereNumber('id');
        Route::post('{id}/sanitize-svg', [CutieMarksController::class, 'sanitize'])->whereNumber('id');

        Route::middleware('role:staff')->group(function () use ($manage) {
            Route::get('{id}/tag-changes', [$manage, 'tagChanges'])->whereNumber('id');
            Route::get('{id}/shows', [$manage, 'shows'])->whereNumber('id');
            Route::put('{id}/shows', [$manage, 'setShows'])->whereNumber('id');
            Route::post('{id}/pin', [$manage, 'pin'])->whereNumber('id');
            Route::delete('{id}/pin', [$manage, 'pin'])->whereNumber('id');
        });
    });

    Route::prefix('color-guide')->group(function () {
        Route::get('/', [ColorGuideController::class, 'index']);
        Route::get('major-changes', [ColorGuideController::class, 'majorChanges']);
    });

    Route::prefix('useful-links')->group(function () {
        Route::get('sidebar', [UsefulLinksController::class, 'sidebar'])->middleware('optional.auth');

        Route::middleware(['auth:sanctum', 'role:staff'])->group(function () {
            Route::get('/', [UsefulLinksController::class, 'index']);
            Route::post('/', [UsefulLinksController::class, 'create']);
            Route::put('order', [UsefulLinksController::class, 'order']);
            Route::get('{id}', [UsefulLinksController::class, 'show'])->whereNumber('id');
            Route::put('{id}', [UsefulLinksController::class, 'update'])->whereNumber('id');
            Route::delete('{id}', [UsefulLinksController::class, 'destroy'])->whereNumber('id');
        });
    });

    Route::middleware('auth:sanctum')->prefix('color-groups')->group(function () {
        Route::post('/', [ColorGroupsController::class, 'create']);
        Route::get('{id}', [ColorGroupsController::class, 'show'])->whereNumber('id');
        Route::put('{id}', [ColorGroupsController::class, 'update'])->whereNumber('id');
        Route::delete('{id}', [ColorGroupsController::class, 'destroy'])->whereNumber('id');
    });

    Route::middleware(['auth:sanctum', 'role:staff'])->prefix('admin')->group(function () {
        Route::get('pcg-appearances', [AdminController::class, 'pcgAppearances']);
        Route::get('logs', [AdminController::class, 'logs']);
        Route::get('posts/recent', [AdminController::class, 'recentPosts']);
        Route::get('logs/{id}', [AdminController::class, 'logDetail'])->whereNumber('id');
    });

    Route::get('admin/search-status', [AdminController::class, 'searchStatus'])->middleware(['auth:sanctum', 'role:developer']);

    Route::middleware(['auth:sanctum', 'role:developer'])->prefix('color-guide')->group(function () {
        Route::post('reindex', [AdminController::class, 'reindex']);
    });
    // The old site published this as a static file that other tools read, so it is public (and kept for an hour, building it takes a while)
    Route::get('color-guide/export', [AdminController::class, 'export'])->middleware('cacheResponse:3600');
    Route::middleware('auth:sanctum')->post('notifications/{id}/read', [AdminController::class, 'readNotification'])->whereNumber('id');

    Route::prefix('tags')->group(function () {
        Route::get('/', [TagsController::class, 'index']);

        Route::middleware(['auth:sanctum', 'role:staff'])->group(function () {
            Route::get('autocomplete', [TagsController::class, 'autocomplete']);
            Route::post('/', [TagsController::class, 'create']);
            Route::post('recount-uses', [TagsController::class, 'recountUses']);
            Route::get('{id}', [TagsController::class, 'show'])->whereNumber('id');
            Route::put('{id}', [TagsController::class, 'update'])->whereNumber('id');
            Route::delete('{id}', [TagsController::class, 'destroy'])->whereNumber('id');
            Route::put('{id}/synonym', [TagsController::class, 'makeSynonym'])->whereNumber('id');
            Route::delete('{id}/synonym', [TagsController::class, 'removeSynonym'])->whereNumber('id');
        });
    });

    Route::prefix('posts')->group(function () {
        $manage = PostManagementController::class;
        Route::get('/', [PostsController::class, 'index']);
        Route::get('{id}/location', [$manage, 'location'])->whereNumber('id');
        Route::get('{id}/reload', [$manage, 'reload'])->whereNumber('id');
        Route::get('{id}/deviation', [PostsController::class, 'deviation'])->whereNumber('id');

        Route::middleware('auth:sanctum')->group(function () use ($manage) {
            Route::get('requests/suggestion', [PostsController::class, 'suggestion']);
            Route::post('/', [$manage, 'create']);
            Route::post('check-image', [$manage, 'checkImageEndpoint']);
            Route::get('{id}', [$manage, 'show'])->whereNumber('id');
            Route::put('{id}', [$manage, 'update'])->whereNumber('id');
            Route::put('{id}/image', [$manage, 'setImage'])->whereNumber('id');
            Route::delete('requests/{id}', [$manage, 'destroyRequest'])->whereNumber('id');

            Route::middleware('role:member')->group(function () use ($manage) {
                Route::post('{id}/reservation', [$manage, 'reserve'])->whereNumber('id');
                Route::delete('{id}/reservation', [$manage, 'unreserve'])->whereNumber('id');
                Route::post('{id}/approval', [$manage, 'approve'])->whereNumber('id');
                Route::delete('{id}/approval', [$manage, 'unapprove'])->whereNumber('id');
                Route::put('{id}/finish', [$manage, 'finish'])->whereNumber('id');
                Route::delete('{id}/finish', [$manage, 'unfinish'])->whereNumber('id');
            });

            Route::middleware('role:staff')->group(function () use ($manage) {
                Route::post('reservations', [$manage, 'addReservation']);
                Route::post('{id}/unbreak', [$manage, 'unbreak'])->whereNumber('id');
            });
        });
    });

    Route::prefix('events')->group(function () {
        Route::get('/', [EventsController::class, 'index']);
        Route::get('{id}', [EventsController::class, 'show'])->whereNumber('id');
        Route::get('{id}/finished-image', [EventsController::class, 'finishedImage'])->whereNumber('id');

        // Managing events and receiving entries is switched off in Winterchilla, so these only answer 501 after the permission checks
        Route::middleware(['auth:sanctum', 'role:staff'])->group(function () {
            Route::post('/', [EventsController::class, 'disabled']);
            Route::put('{id}', [EventsController::class, 'disabled'])->whereNumber('id');
            Route::delete('{id}', [EventsController::class, 'disabled'])->whereNumber('id');
            Route::post('{id}/finalize', [EventsController::class, 'disabled'])->whereNumber('id');
        });
        Route::post('{id}/entries/check', [EventsController::class, 'disabled'])->whereNumber('id')->middleware('auth:sanctum');
        // Winterchilla routes these to the entry controller without an entry id, so they only ever answer 404 to signed in users
        Route::match(['get', 'put', 'delete'], '{id}/entries', [EventEntriesController::class, 'placeholder'])->whereNumber('id')->middleware('auth:sanctum');
    });

    Route::middleware('auth:sanctum')->prefix('event-entries')->group(function () {
        Route::get('{entryid}', [EventEntriesController::class, 'show'])->whereNumber('entryid');
        Route::put('{entryid}', [EventEntriesController::class, 'update'])->whereNumber('entryid');
        Route::delete('{entryid}', [EventEntriesController::class, 'destroy'])->whereNumber('entryid');
    });

    Route::prefix('notices')->group(function () {
        Route::get('current', [NoticesController::class, 'current']);

        Route::middleware(['auth:sanctum', 'role:staff'])->group(function () {
            Route::get('/', [NoticesController::class, 'index']);
            Route::post('/', [NoticesController::class, 'create']);
            Route::get('{id}', [NoticesController::class, 'show'])->whereNumber('id');
            Route::put('{id}', [NoticesController::class, 'update'])->whereNumber('id');
            Route::delete('{id}', [NoticesController::class, 'destroy'])->whereNumber('id');
        });
    });

    Route::middleware(['auth:sanctum', 'role:staff'])->prefix('settings')->group(function () {
        Route::get('{key}', [SettingsController::class, 'show']);
        Route::put('{key}', [SettingsController::class, 'update']);
    });

    Route::prefix('user-prefs')->group(function () {
        Route::get('me', [UserPrefsController::class, 'me']);
    });

    Route::prefix('show')->group(function () {
        $manage = ShowManagementController::class;
        Route::get('/', [ShowController::class, 'index']);
        Route::get('latest', [$manage, 'latest']);
        Route::get('next', [$manage, 'next']);
        Route::get('reservation-info', [$manage, 'reservationInfo']);
        Route::get('upcoming', [ShowController::class, 'upcoming']);
        Route::get('{id}', [$manage, 'show'])->whereNumber('id');
        Route::get('{id}/adjacent', [$manage, 'adjacent'])->whereNumber('id');
        Route::get('{id}/vote', [$manage, 'votes'])->whereNumber('id');

        Route::middleware('auth:sanctum')->group(function () use ($manage) {
            Route::post('{id}/vote', [$manage, 'vote'])->whereNumber('id');

            Route::middleware('role:staff')->group(function () use ($manage) {
                Route::get('prefill', [$manage, 'prefill']);
                Route::post('/', [$manage, 'create']);
                Route::put('{id}', [$manage, 'update'])->whereNumber('id');
                Route::delete('{id}', [$manage, 'destroy'])->whereNumber('id');
                Route::get('{id}/appearances', [$manage, 'appearances'])->whereNumber('id');
                Route::put('{id}/appearances', [$manage, 'setAppearances'])->whereNumber('id');
            });
        });
    });
});
