<?php

namespace App\Utils;

use App\Models\Log;
use Illuminate\Support\Facades\Auth;

/**
 * Writes the audit log shared with Winterchilla (`logs` table), keeping its entry types and data keys so its admin log view can render them
 */
class LogWriter
{
    public static function record(string $entry_type, ?array $data = null, bool $as_webserver = false): Log
    {
        return Log::create([
            'entry_type' => $entry_type,
            'initiator' => $as_webserver ? null : Auth::guard('sanctum')->id() ?? Auth::id(),
            'ip' => request()->ip() ?? '127.0.0.1',
            'data' => $data,
        ]);
    }
}
