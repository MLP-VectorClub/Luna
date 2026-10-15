<?php

namespace App\Http\Controllers;

use App\Http\Middleware\RejectFlaggedSession;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Traps for scanners that ignore robots.txt: the front end sends `/.env`, `/wp-admin` and `/admin.php` here, all three are listed as disallowed in
 * its robots.txt, so nothing that behaves has a reason to ask for them. Whoever does gets their session flagged, and every request of that
 * session is refused from then on (see `RejectFlaggedSession`)
 */
class HoneypotController extends Controller
{
    public function trap(Request $request, string $trap): Response
    {
        RejectFlaggedSession::flag($request);
        Log::info('Honeypot hit, session flagged', ['trap' => $trap, 'user' => $request->user()?->id, 'ip' => $request->ip(), 'agent' => $request->userAgent()]);

        return RejectFlaggedSession::refusal();
    }
}
