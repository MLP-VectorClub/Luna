<?php

use Illuminate\Support\Facades\Route;

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

Route::prefix('v0')->group(function () {
    Route::middleware('throttle:12,1')->group(function () {
        Route::post('/users/login', 'Auth\LoginController@viaPassword');
    });

    Route::middleware('throttle:60,1')->group(function () {
        Route::prefix('users')->group(function () {
            Route::post('/', 'UsersController@create');

            Route::middleware('auth:api')->group(function () {
                Route::get('/me', 'UsersController@index');
            });
        });
    });
});
