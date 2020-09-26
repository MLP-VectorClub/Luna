<?php

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

use App\Http\Controllers\AboutController;
use Illuminate\Support\Facades\Route;

// TODO Utilize
Route::get('/about/server-info', [AboutController::class, 'serverInfo']);
