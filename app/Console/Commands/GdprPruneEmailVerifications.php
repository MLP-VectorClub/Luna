<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Port of Winterchilla's scripts/clear_old_email_confirmations.php: verification entries are only kept for 24 hours
 */
class GdprPruneEmailVerifications extends Command
{
    protected $signature = 'gdpr:prune-email-verifications';

    protected $description = 'Delete e-mail verification entries older than 24 hours';

    public function handle(): int
    {
        $deleted = DB::table('email_verifications')->where('created_at', '<', now()->subHours(24))->delete();
        $this->info("$deleted verification ".($deleted === 1 ? 'entry' : 'entries').' deleted');

        return self::SUCCESS;
    }
}
