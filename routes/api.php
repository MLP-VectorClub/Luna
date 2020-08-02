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
    Route::post('/users/login', 'Auth\LoginController@viaPassword');
});

Route::prefix('about')->group(function () {
    if (!App::isProduction()) {
        Route::get('sleep', 'AboutController@sleep');
    }
});

Route::middleware('throttle:60,1')->group(function () {
    Route::prefix('users')->group(function () {
        Route::post('/', 'Auth\RegisterController@viaPassword');

        Route::middleware('auth:sanctum')->group(function () {
            Route::get('me', 'UsersController@me');
            Route::post('logout', 'UsersController@logout');
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
