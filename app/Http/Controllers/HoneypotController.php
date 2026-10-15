<?php

namespace App\Http\Controllers;

use App\Http\Middleware\RejectFlaggedSession;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
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
        // Staff looking at the decoys (the session list of their account would fill up with refused sessions, and they would be locked out of their own
        // site) are refused like everybody else but not flagged
        if (Auth::user()?->isStaff()) {
            Log::info('Honeypot hit by a signed in staff member, session not flagged', ['trap' => $trap, 'user' => Auth::id(), 'ip' => $request->ip()]);

            return RejectFlaggedSession::refusal();
        }

        RejectFlaggedSession::flag($request);
        Log::info('Honeypot hit, session flagged', ['trap' => $trap, 'ip' => $request->ip(), 'agent' => $request->userAgent()]);

        return RejectFlaggedSession::refusal();
    }
}
