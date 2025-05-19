<?php

use App\Http\Controllers\AboutController;
use App\Http\Controllers\AppearancesController;
use App\Http\Controllers\Auth\SigninController;
use App\Http\Controllers\Auth\SignupController;
use App\Http\Controllers\Auth\VerificationController;
use App\Http\Controllers\ColorGuideController;
use App\Http\Controllers\ShowController;
use App\Http\Controllers\UsefulLinksController;
use App\Http\Controllers\UserPrefsController;
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

Route::middleware('throttle:12,1')->group(function () {
    Route::prefix('users')->group(function () {
        Route::get('email/verify/{id}/{hash}', [VerificationController::class, 'verify'])->name('verification.verify');

        Route::post('signin', [SigninController::class, 'viaPassword'])->name('signin_password');
        Route::post('/', [SignupController::class, 'viaPassword'])->name('signup_password');
        Route::post('/oauth/signup/{provider}', [SignupController::class, 'viaSocialite']);
        Route::post('/oauth/signin/{provider}', [SigninController::class, 'viaSocialite']);
    });
});

Route::prefix('about')->group(function () {
    if (!App::isProduction()) {
        Route::get('sleep', [AboutController::class, 'sleep']);
    }

    Route::get('connection', [AboutController::class, 'serverInfo']);
    Route::get('members', [AboutController::class, 'members']);
});

Route::middleware('throttle:60,1')->group(function () {
    Route::prefix('users')->group(function () {
        // Route::get('oauth/signup/{provider}', [SignupController::class, 'socialiteRedirect']);
        Route::get('oauth/signin/{provider}', [SigninController::class, 'socialiteRedirect']);

        Route::post('email/resend', [VerificationController::class, 'resend'])->name('verification.resend');

        Route::middleware('auth:sanctum')->group(function () {
            Route::get('me', [UsersController::class, 'me']);
            Route::post('signout', [UsersController::class, 'signout']);
            Route::get('tokens', [UsersController::class, 'tokens']);
            Route::delete('tokens/{token_id}', [UsersController::class, 'deleteToken']);

            Route::get('/', [UsersController::class, 'list']);
        });

        Route::get('{user}', [UsersController::class, 'getById']);
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

    Route::prefix('color-guide')->group(function () {
        Route::get('/', [ColorGuideController::class, 'index']);
        Route::get('major-changes', [ColorGuideController::class, 'majorChanges']);
    });

    Route::prefix('useful-links')->group(function () {
        Route::get('sidebar', [UsefulLinksController::class, 'sidebar']);
    });

    Route::prefix('user-prefs')->group(function () {
        Route::get('me', [UserPrefsController::class, 'me']);
    });

    Route::prefix('show')->group(function () {
        Route::get('/', [ShowController::class, 'index']);
    });
});
