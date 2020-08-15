<?php

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
        Route::post('signin', 'Auth\SigninController@viaPassword')->name('signin_password');
        Route::post('/', 'Auth\SignupController@viaPassword')->name('signup_password');
        Route::post('/oauth/signup/{provider}', 'Auth\SignupController@viaSocialite');
        Route::post('/oauth/signin/{provider}', 'Auth\SigninController@viaSocialite');
    });
});

Route::prefix('about')->group(function () {
    if (!App::isProduction()) {
        Route::get('sleep', 'AboutController@sleep');
    }

    Route::get('connection', 'AboutController@serverInfo');
});

Route::middleware('throttle:60,1')->group(function () {
    Route::prefix('users')->group(function () {
        Route::get('oauth/signup/{provider}', 'Auth\SignupController@socialiteRedirect');
        Route::get('oauth/signin/{provider}', 'Auth\SigninController@socialiteRedirect');

        Route::get('email/verify/{id}/{hash}', 'Auth\VerificationController@verify')->name('verification.verify');
        Route::post('email/resend', 'Auth\VerificationController@resend')->name('verification.resend');

        Route::middleware('auth:sanctum')->group(function () {
            Route::get('me', 'UsersController@me');
            Route::post('signout', 'UsersController@signout');
            Route::get('tokens', 'UsersController@tokens');
            Route::delete('tokens/{token_id}', 'UsersController@deleteToken');
        });

        Route::get('{user}', 'UsersController@get');
        Route::get('da/{username}', 'UsersController@getByName');
    });

    Route::prefix('appearances')->group(function () {
        Route::get('/', 'AppearancesController@queryPublic');
        Route::get('all', 'AppearancesController@queryAll')->name('appearances_all')->middleware('cacheResponse:300');
        Route::get('{appearance}/sprite', 'AppearancesController@sprite');
        Route::get('{appearance}/color-groups', 'AppearancesController@getColorGroups');
    });
});
