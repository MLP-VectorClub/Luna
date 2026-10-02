<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Port of Winterchilla's scripts/clear_old_logged_ips.php: IP addresses are only kept for 3 months
 */
class GdprAnonymizeLoggedIps extends Command
{
    /** Same placeholder as Winterchilla's GDPR_IP_PLACEHOLDER, so the shared logs table stays consistent */
    public const IP_PLACEHOLDER = '127.168.80.82';

    protected $signature = 'gdpr:anonymize-logged-ips';

    protected $description = 'Replace the IP address of log entries older than 3 months and delete failed authentication attempts older than 3 months';

    public function handle(): int
    {
        $cutoff = now()->subMonths(3);

        $logs = DB::table('logs')->where('ip', '!=', self::IP_PLACEHOLDER)->where('created_at', '<', $cutoff)->update(['ip' => self::IP_PLACEHOLDER]);
        $this->info("$logs log ".($logs === 1 ? 'entry' : 'entries').' updated');

        // The table is written by Winterchilla only
        $attempts = Schema::hasTable('failed_auth_attempts') ? DB::table('failed_auth_attempts')->where('created_at', '<', $cutoff)->delete() : 0;
        $this->info("$attempts failed auth ".($attempts === 1 ? 'attempt' : 'attempts').' deleted');

        return self::SUCCESS;
    }
}
