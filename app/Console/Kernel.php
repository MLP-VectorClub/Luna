<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        //
    ];

    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        $schedule->command('cloudflare:reload')->daily();
        // Fills the throughput and wait time graphs of the Horizon dashboard
        $schedule->command('horizon:snapshot')->everyFiveMinutes();
        // Everything below writes to the database, which a read-only site must not do (the GDPR retention periods resume with the writes)
        if (config('app.read_only')) {
            return;
        }
        $schedule->command('deviantart:warm-deviations')->everyTenMinutes()->withoutOverlapping();
        // GDPR retention, these were Winterchilla's cron scripts
        $schedule->command('gdpr:anonymize-logged-ips')->daily();
        $schedule->command('gdpr:prune-email-verifications')->hourly();
        // Guests keep a session for a day, not for the 30 days of SESSION_LIFETIME
        $schedule->command('sessions:prune-guests')->hourly();
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
