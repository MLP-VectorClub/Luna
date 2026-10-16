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
use App\Http\Controllers\Auth\PasskeySigninController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\TwoFactorSigninController;
use App\Http\Controllers\Auth\VerificationController;
use App\Http\Controllers\ColorGuideController;
use App\Http\Controllers\ColorGroupsController;
use App\Http\Controllers\ConfigController;
use App\Http\Controllers\Testing\TestFixturesController;
use App\Http\Controllers\Testing\TestLoginController;
use App\Http\Controllers\NoticesController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\EventsController;
use App\Http\Controllers\PasskeysController;
use App\Http\Controllers\PersonalGuideController;
use App\Http\Controllers\PostManagementController;
use App\Http\Controllers\PostsController;
use App\Http\Controllers\ShowController;
use App\Http\Controllers\ShowManagementController;
use App\Http\Controllers\TagsController;
use Spatie\ResponseCache\Middlewares\CacheResponse;
use App\Http\Controllers\TwoFactorController;
use App\Http\Controllers\UsefulLinksController;
use App\Http\Controllers\UserProfileController;
use App\Http\Controllers\UserPrefsController;
use App\Http\Controllers\UserEmailController;
use App\Http\Controllers\UsersController;
use App\Http\Controllers\WebsocketController;
use App\Utils\RoutePatterns;
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

// The websocket server (Muffins) checks the tokens of browsers here, with the shared key
Route::post('internal/websocket/validate', [WebsocketController::class, 'validateToken'])->middleware($throttle(300));

Route::middleware($throttle(12))->group(function () {
    Route::prefix('users')->group(function () {
        Route::get('email/verify/{id}/{hash}', [VerificationController::class, 'verify'])->name('verification.verify');

        Route::post('signin', [SigninController::class, 'viaPassword'])->name('signin_password');
        Route::post('signin/two-factor', [TwoFactorSigninController::class, 'verify']);
        Route::post('signin/two-factor/passkey/options', [TwoFactorSigninController::class, 'passkeyOptions']);
        Route::post('signin/two-factor/passkey', [TwoFactorSigninController::class, 'passkeyVerify']);
        Route::post('signin/passkey/options', [PasskeySigninController::class, 'options']);
        Route::post('signin/passkey', [PasskeySigninController::class, 'verify']);
        Route::post('password/forgot', [PasswordResetController::class, 'forgot']);
        Route::post('password/reset', [PasswordResetController::class, 'reset']);
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
        Route::get('oauth/signin/{provider}', [SigninController::class, 'socialiteRedirect']);

        Route::post('email/resend', [VerificationController::class, 'resend'])->name('verification.resend');

        Route::get('{id}/profile', [UserProfileController::class, 'profile'])->where('id', RoutePatterns::ID);
        Route::get('{id}/contributions/{type}', [UserProfileController::class, 'contributions'])->where('id', RoutePatterns::ID);
        Route::delete('{id}/contributions/cache', [UserProfileController::class, 'purgeContributionsCache'])->where('id', RoutePatterns::ID)->middleware(['auth:sanctum', 'role:staff']);
        Route::get('{id}/personal-guide/appearances', [PersonalGuideController::class, 'appearances'])->where('id', RoutePatterns::ID);

        Route::middleware('auth:sanctum')->group(function () {
            Route::get('{id}/personal-guide/point-history', [PersonalGuideController::class, 'pointHistory'])->where('id', RoutePatterns::ID);
            Route::get('{id}/personal-guide/slots', [PersonalGuideController::class, 'slots'])->where('id', RoutePatterns::ID);
            Route::post('{id}/personal-guide/point-history/recalculation', [PersonalGuideController::class, 'recalculate'])->where('id', RoutePatterns::ID)->middleware('role:developer');
            Route::get('{id}/personal-guide/points', [PersonalGuideController::class, 'points'])->where('id', RoutePatterns::ID)->middleware('role:staff');
            Route::post('{id}/personal-guide/points', [PersonalGuideController::class, 'givePoints'])->where('id', RoutePatterns::ID)->middleware('role:staff');
            Route::post('{user_id}/discord', [DiscordController::class, 'link'])->where('user_id', RoutePatterns::ID);
            Route::post('{user_id}/discord/sync', [DiscordController::class, 'sync'])->where('user_id', RoutePatterns::ID);
            Route::delete('{user_id}/discord', [DiscordController::class, 'unlink'])->where('user_id', RoutePatterns::ID);
            Route::get('da-uuid/{uuid}', [UsersController::class, 'getByDaUuid'])->middleware('role:developer');
            Route::put('{id}/role', [UsersController::class, 'setRole'])->where('id', RoutePatterns::ID)->middleware('role:staff');
            Route::get('{id}/preferences', [UserPrefsController::class, 'index'])->where('id', RoutePatterns::ID);
            Route::get('{id}/preferences/{key}', [UserPrefsController::class, 'show'])->where('id', RoutePatterns::ID);
            Route::put('{id}/preferences/{key}', [UserPrefsController::class, 'update'])->where('id', RoutePatterns::ID);
            Route::get('me', [UsersController::class, 'me']);
            Route::post('{id}/email-changes', [UserEmailController::class, 'request'])->where('id', RoutePatterns::ID)->middleware('role:staff');
            Route::post('email/verify', [UserEmailController::class, 'verify']);
            Route::post('me/password', [UsersController::class, 'setPassword'])->middleware('role:staff');
            Route::post('me/socket-token', [WebsocketController::class, 'token']);
            Route::get('me/two-factor', [TwoFactorController::class, 'show']);
            Route::post('me/two-factor', [TwoFactorController::class, 'start']);
            Route::post('me/two-factor/confirm', [TwoFactorController::class, 'confirm']);
            Route::post('me/two-factor/recovery-codes', [TwoFactorController::class, 'recoveryCodes']);
            Route::delete('me/two-factor', [TwoFactorController::class, 'disable']);
            Route::get('me/passkeys', [PasskeysController::class, 'index']);
            Route::post('me/passkeys/options', [PasskeysController::class, 'options']);
            Route::post('me/passkeys', [PasskeysController::class, 'register']);
            Route::patch('me/passkeys/{id}', [PasskeysController::class, 'rename'])->where('id', RoutePatterns::ID);
            Route::delete('me/passkeys/{id}', [PasskeysController::class, 'destroy'])->where('id', RoutePatterns::ID);
            Route::post('signout', [UsersController::class, 'signout']);
            Route::get('sessions', [UsersController::class, 'sessions']);
            Route::get('{id}/sessions', [UsersController::class, 'sessionsOf'])->where('id', RoutePatterns::ID);
            Route::delete('{id}/sessions/{sessionId}', [UsersController::class, 'deleteSessionOf'])->where('id', RoutePatterns::ID)->where('sessionId', '[0-9a-f]{64}');
            Route::delete('{id}/sessions', [UsersController::class, 'deleteSessionsOf'])->where('id', RoutePatterns::ID);
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
        Route::get('full', [AppearancesController::class, 'queryFullPublic'])->name('appearances_full')->middleware(CacheResponse::for(lifetime: 300));
        Route::get('pinned', [AppearancesController::class, 'pinned'])->name('pinned_appearance')->middleware(CacheResponse::for(lifetime: 60));
        Route::get('autocomplete', [AppearancesController::class, 'autocomplete'])->name('appearances_autocomplete')->middleware(CacheResponse::for(lifetime: 180));
        Route::get('{appearance}', [AppearancesController::class, 'get'])->where('appearance', RoutePatterns::ID);
        Route::get('{appearance}/locate', [AppearancesController::class, 'locate'])->where('appearance', RoutePatterns::ID);
        Route::get('{appearance}/sprite', [AppearancesController::class, 'sprite'])->name('appearance_sprite')->where('appearance', RoutePatterns::ID);
        Route::get('{appearance}/color-groups', [AppearancesController::class, 'colorGroups'])->where('appearance', RoutePatterns::ID);
    });

    Route::middleware('optional.auth')->get('cutie-marks/{cutieMarkId}/{disposition}', [AppearanceExportsController::class, 'cutieMarkById'])
        ->where('cutieMarkId', RoutePatterns::ID)->whereIn('disposition', ['image', 'download']);

    Route::middleware('optional.auth')->prefix('appearances')->group(function () {
        Route::get('{id}/palette', [AppearanceExportsController::class, 'palette'])->where('id', RoutePatterns::ID);
        Route::get('{id}/image', [AppearanceExportsController::class, 'image'])->where('id', RoutePatterns::ID);
        Route::get('{id}/sprite/colors', [AppearanceExportsController::class, 'spriteColors'])->where('id', RoutePatterns::ID);
        Route::get('{id}/cutie-marks/{cutieMarkId}/download', [AppearanceExportsController::class, 'cutieMark'])->where(['id' => RoutePatterns::ID, 'cutieMarkId' => RoutePatterns::ID]);
    });

    Route::middleware('auth:sanctum')->prefix('appearances')->group(function () {
        $manage = AppearanceManagementController::class;
        Route::post('/', [$manage, 'create']);
        Route::put('order', [$manage, 'order'])->middleware('role:staff');
        Route::get('{id}/metadata', [$manage, 'metadata'])->where('id', RoutePatterns::ID);
        Route::put('{id}', [$manage, 'update'])->where('id', RoutePatterns::ID);
        Route::delete('{id}', [$manage, 'destroy'])->where('id', RoutePatterns::ID);
        Route::post('{id}/sprite', [$manage, 'uploadSprite'])->where('id', RoutePatterns::ID);
        Route::delete('{id}/sprite', [$manage, 'deleteSprite'])->where('id', RoutePatterns::ID);
        Route::post('{id}/template', [$manage, 'template'])->where('id', RoutePatterns::ID);
        Route::delete('{id}/contents', [$manage, 'clear'])->where('id', RoutePatterns::ID);
        Route::get('{id}/color-groups/order', [$manage, 'colorGroupOrder'])->where('id', RoutePatterns::ID);
        Route::put('{id}/color-groups/order', [$manage, 'setColorGroupOrder'])->where('id', RoutePatterns::ID);
        Route::get('{id}/relations', [$manage, 'relations'])->where('id', RoutePatterns::ID);
        Route::put('{id}/relations', [$manage, 'setRelations'])->where('id', RoutePatterns::ID);
        Route::get('{id}/tags', [$manage, 'tags'])->where('id', RoutePatterns::ID);
        Route::put('{id}/tags', [$manage, 'setTags'])->where('id', RoutePatterns::ID);
        Route::get('{id}/cutie-marks', [CutieMarksController::class, 'index'])->where('id', RoutePatterns::ID);
        Route::put('{id}/cutie-marks', [CutieMarksController::class, 'replace'])->where('id', RoutePatterns::ID);
        Route::post('{id}/sanitize-svg', [CutieMarksController::class, 'sanitize'])->where('id', RoutePatterns::ID);

        Route::middleware('role:staff')->group(function () use ($manage) {
            Route::get('{id}/tag-changes', [$manage, 'tagChanges'])->where('id', RoutePatterns::ID);
            Route::get('{id}/shows', [$manage, 'shows'])->where('id', RoutePatterns::ID);
            Route::put('{id}/shows', [$manage, 'setShows'])->where('id', RoutePatterns::ID);
            Route::post('{id}/pin', [$manage, 'pin'])->where('id', RoutePatterns::ID);
            Route::delete('{id}/pin', [$manage, 'pin'])->where('id', RoutePatterns::ID);
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
            Route::get('{id}', [UsefulLinksController::class, 'show'])->where('id', RoutePatterns::ID);
            Route::put('{id}', [UsefulLinksController::class, 'update'])->where('id', RoutePatterns::ID);
            Route::delete('{id}', [UsefulLinksController::class, 'destroy'])->where('id', RoutePatterns::ID);
        });
    });

    Route::middleware('auth:sanctum')->prefix('color-groups')->group(function () {
        Route::post('/', [ColorGroupsController::class, 'create']);
        Route::get('{id}', [ColorGroupsController::class, 'show'])->where('id', RoutePatterns::ID);
        Route::put('{id}', [ColorGroupsController::class, 'update'])->where('id', RoutePatterns::ID);
        Route::delete('{id}', [ColorGroupsController::class, 'destroy'])->where('id', RoutePatterns::ID);
    });

    Route::middleware(['auth:sanctum', 'role:staff'])->prefix('admin')->group(function () {
        Route::get('pcg-appearances', [AdminController::class, 'pcgAppearances']);
        Route::get('logs', [AdminController::class, 'logs']);
        Route::get('posts/recent', [AdminController::class, 'recentPosts']);
        Route::get('logs/{id}', [AdminController::class, 'logDetail'])->where('id', RoutePatterns::ID);
    });

    Route::get('admin/search-status', [AdminController::class, 'searchStatus'])->middleware(['auth:sanctum', 'role:developer']);

    Route::middleware(['auth:sanctum', 'role:developer'])->prefix('color-guide')->group(function () {
        Route::post('reindex', [AdminController::class, 'reindex']);
    });
    // The old site published this as a static file that other tools read, so it is public (and kept for an hour, building it takes a while)
    Route::get('color-guide/export', [AdminController::class, 'export'])->middleware(CacheResponse::for(lifetime: 3600));
    Route::middleware('auth:sanctum')->get('notifications', [AdminController::class, 'notifications']);
    Route::middleware('auth:sanctum')->post('notifications/{id}/read', [AdminController::class, 'readNotification'])->where('id', RoutePatterns::ID);

    Route::prefix('tags')->group(function () {
        Route::get('/', [TagsController::class, 'index']);

        Route::middleware(['auth:sanctum', 'role:staff'])->group(function () {
            Route::get('autocomplete', [TagsController::class, 'autocomplete']);
            Route::post('/', [TagsController::class, 'create']);
            Route::post('recount-uses', [TagsController::class, 'recountUses']);
            Route::get('{id}', [TagsController::class, 'show'])->where('id', RoutePatterns::ID);
            Route::put('{id}', [TagsController::class, 'update'])->where('id', RoutePatterns::ID);
            Route::delete('{id}', [TagsController::class, 'destroy'])->where('id', RoutePatterns::ID);
            Route::put('{id}/synonym', [TagsController::class, 'makeSynonym'])->where('id', RoutePatterns::ID);
            Route::delete('{id}/synonym', [TagsController::class, 'removeSynonym'])->where('id', RoutePatterns::ID);
        });
    });

    Route::prefix('posts')->group(function () {
        $manage = PostManagementController::class;
        Route::get('/', [PostsController::class, 'index']);
        Route::get('legacy/{kind}/{oldId}', [$manage, 'legacyId'])->whereIn('kind', ['request', 'reservation'])->where('oldId', RoutePatterns::ID);
        Route::get('{id}/location', [$manage, 'location'])->where('id', RoutePatterns::ID);
        Route::get('{id}/reload', [$manage, 'reload'])->where('id', RoutePatterns::ID);
        Route::get('{id}/deviation', [PostsController::class, 'deviation'])->where('id', RoutePatterns::ID);

        Route::middleware('auth:sanctum')->group(function () use ($manage) {
            Route::get('requests/suggestion', [PostsController::class, 'suggestion']);
            Route::post('/', [$manage, 'create']);
            Route::post('check-image', [$manage, 'checkImageEndpoint']);
            Route::get('{id}', [$manage, 'show'])->where('id', RoutePatterns::ID);
            Route::put('{id}', [$manage, 'update'])->where('id', RoutePatterns::ID);
            Route::put('{id}/image', [$manage, 'setImage'])->where('id', RoutePatterns::ID);
            Route::delete('requests/{id}', [$manage, 'destroyRequest'])->where('id', RoutePatterns::ID);

            Route::middleware('role:member')->group(function () use ($manage) {
                Route::post('{id}/reservation', [$manage, 'reserve'])->where('id', RoutePatterns::ID);
                Route::delete('{id}/reservation', [$manage, 'unreserve'])->where('id', RoutePatterns::ID);
                Route::post('{id}/approval', [$manage, 'approve'])->where('id', RoutePatterns::ID);
                Route::delete('{id}/approval', [$manage, 'unapprove'])->where('id', RoutePatterns::ID);
                Route::put('{id}/finish', [$manage, 'finish'])->where('id', RoutePatterns::ID);
                Route::delete('{id}/finish', [$manage, 'unfinish'])->where('id', RoutePatterns::ID);
            });

            Route::middleware('role:staff')->group(function () use ($manage) {
                Route::post('reservations', [$manage, 'addReservation']);
                Route::post('{id}/unbreak', [$manage, 'unbreak'])->where('id', RoutePatterns::ID);
            });
        });
    });

    Route::prefix('events')->group(function () {
        Route::get('/', [EventsController::class, 'index']);
        Route::get('{id}', [EventsController::class, 'show'])->where('id', RoutePatterns::ID);
        Route::get('{id}/finished-image', [EventsController::class, 'finishedImage'])->where('id', RoutePatterns::ID);

        // Managing events and receiving entries is switched off in Winterchilla, so these only answer 501 after the permission checks
        Route::middleware(['auth:sanctum', 'role:staff'])->group(function () {
            Route::post('/', [EventsController::class, 'disabled']);
            Route::put('{id}', [EventsController::class, 'disabled'])->where('id', RoutePatterns::ID);
            Route::delete('{id}', [EventsController::class, 'disabled'])->where('id', RoutePatterns::ID);
            Route::post('{id}/finalize', [EventsController::class, 'disabled'])->where('id', RoutePatterns::ID);
        });
        Route::post('{id}/entries/check', [EventsController::class, 'disabled'])->where('id', RoutePatterns::ID)->middleware('auth:sanctum');
        // Winterchilla routes these to the entry controller without an entry id, so they only ever answer 404 to signed in users
        Route::match(['get', 'put', 'delete'], '{id}/entries', [EventEntriesController::class, 'placeholder'])->where('id', RoutePatterns::ID)->middleware('auth:sanctum');
    });

    Route::middleware('auth:sanctum')->prefix('event-entries')->group(function () {
        Route::get('{entryid}', [EventEntriesController::class, 'show'])->where('entryid', RoutePatterns::ID);
        Route::put('{entryid}', [EventEntriesController::class, 'update'])->where('entryid', RoutePatterns::ID);
        Route::delete('{entryid}', [EventEntriesController::class, 'destroy'])->where('entryid', RoutePatterns::ID);
    });

    Route::prefix('notices')->group(function () {
        Route::get('current', [NoticesController::class, 'current']);

        Route::middleware(['auth:sanctum', 'role:staff'])->group(function () {
            Route::get('/', [NoticesController::class, 'index']);
            Route::post('/', [NoticesController::class, 'create']);
            Route::get('{id}', [NoticesController::class, 'show'])->where('id', RoutePatterns::ID);
            Route::put('{id}', [NoticesController::class, 'update'])->where('id', RoutePatterns::ID);
            Route::delete('{id}', [NoticesController::class, 'destroy'])->where('id', RoutePatterns::ID);
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
        Route::get('{id}', [$manage, 'show'])->where('id', RoutePatterns::ID);
        Route::get('{id}/adjacent', [$manage, 'adjacent'])->where('id', RoutePatterns::ID);
        Route::get('{id}/vote', [$manage, 'votes'])->where('id', RoutePatterns::ID);

        Route::middleware('auth:sanctum')->group(function () use ($manage) {
            Route::post('{id}/vote', [$manage, 'vote'])->where('id', RoutePatterns::ID);

            Route::middleware('role:staff')->group(function () use ($manage) {
                Route::get('prefill', [$manage, 'prefill']);
                Route::post('/', [$manage, 'create']);
                Route::put('{id}', [$manage, 'update'])->where('id', RoutePatterns::ID);
                Route::delete('{id}', [$manage, 'destroy'])->where('id', RoutePatterns::ID);
                Route::get('{id}/appearances', [$manage, 'appearances'])->where('id', RoutePatterns::ID);
                Route::put('{id}/appearances', [$manage, 'setAppearances'])->where('id', RoutePatterns::ID);
            });
        });
    });
});
