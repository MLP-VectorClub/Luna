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
use App\Http\Controllers\HoneypotController;
use Illuminate\Support\Facades\Route;

Route::get('/about/server-info', [AboutController::class, 'serverInfo']);

// Answers 204 for a session in good standing; one that touched a honeypot never gets this far (RejectFlaggedSession), the front end's server asks
Route::get('internal/session-status', fn() => response()->noContent());

// The front end passes the paths it lists as disallowed in robots.txt and nobody has a reason to ask for (`/.env`, `/wp-admin`, `/admin.php`) on to
// these, every method; the name only goes to the log, which front end paths lead here is decided over there
Route::any('honeypot/{trap}', [HoneypotController::class, 'trap'])->where('trap', '[a-z0-9._-]{1,40}');
