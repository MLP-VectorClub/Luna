<?php

namespace App\Console\Commands;

use App\Models\DeviantartUser;
use App\Utils\DeviantArtTokens;
use Illuminate\Console\Command;

/**
 * Background replacement for Winterchilla's per-request DeviantArt token refresh (scripts/access_token_refresher.php): tokens that were
 * not refreshed for a while are refreshed, and a user whose token DeviantArt refuses is signed out everywhere and has to sign in with DeviantArt again
 */
class DeviantArtRefreshTokens extends Command
{
    protected $signature = 'deviantart:refresh-tokens {--days=7 : Refresh the tokens that were not refreshed for this many days} {--force : Run even though DEVIANTART_TOKEN_SYNC is off}';

    protected $description = 'Refresh DeviantArt tokens and sign out users whose token DeviantArt refuses';

    public function handle(): int
    {
        if (!DeviantArtTokens::enabled() && !$this->option('force')) {
            $this->info('DEVIANTART_TOKEN_SYNC is off, nothing done');

            return self::SUCCESS;
        }

        $counts = [DeviantArtTokens::OK => 0, DeviantArtTokens::REVOKED => 0, DeviantArtTokens::UNAVAILABLE => 0];
        DeviantartUser::whereNotNull('refresh')
            ->where('updated_at', '<', now()->subDays((int) $this->option('days')))
            ->each(function (DeviantartUser $record) use (&$counts) {
                $result = DeviantArtTokens::refresh($record);
                if ($result === DeviantArtTokens::REVOKED) {
                    DeviantArtTokens::signOut($record);
                }
                $counts[$result]++;
            });

        $this->info("{$counts[DeviantArtTokens::OK]} refreshed, {$counts[DeviantArtTokens::REVOKED]} signed out, {$counts[DeviantArtTokens::UNAVAILABLE]} skipped (DeviantArt unavailable)");

        return self::SUCCESS;
    }
}
