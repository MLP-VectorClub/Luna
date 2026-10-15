<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Sessions of visitors who never signed in hold nothing but a CSRF token: a day is plenty, where Laravel keeps every session for as long as `SESSION_LIFETIME` says (30 days) */
class PruneGuestSessions extends Command
{
    protected $signature = 'sessions:prune-guests {--hours=24 : how long a guest session is kept after its last request}';

    protected $description = 'Delete the sessions of visitors who are not signed in and have not been seen for a day';

    public function handle(): int
    {
        $deleted = DB::table(config('session.table'))
            ->whereNull('user_id')
            ->where('last_activity', '<', now()->subHours((int) $this->option('hours'))->getTimestamp())
            ->delete();
        $this->info("$deleted guest session".($deleted === 1 ? '' : 's').' deleted');

        return self::SUCCESS;
    }
}
