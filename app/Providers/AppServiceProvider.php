<?php

namespace App\Providers;

use App\EloquentFixes\DBAL\Types\CitextType;
use Carbon\Carbon;
use DateInterval;
use DateTime;
use Illuminate\Database\Grammar;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        // Custom column types, resolved by the schema grammar as `type<Name>` methods
        Grammar::macro('typeCitext', fn() => CitextType::CITEXT);

        $conn = DB::connection(DB::getDefaultConnection());
        $conn->setQueryGrammar(new class($conn) extends PostgresGrammar {
            /** Store timestamps with fractional seconds and the timezone offset */
            public function getDateFormat()
            {
                return 'Y-m-d H:i:s.uP';
            }
        });
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        // Requests that arrive through the local nginx (the front end's /api proxy and its server side renders) carry the visitor's address in
        // X-Forwarded-For. Cloudflare's ranges are trusted by Monicahq's middleware, which merges these in. Only a process on this machine can be the
        // 127.0.0.1 hop, so the header cannot be spoofed from outside
        \Illuminate\Http\Middleware\TrustProxies::at(['127.0.0.1', '::1']);

        // Reads are cheap and a single page view makes many of them, so they get a much larger allowance than writes
        RateLimiter::for('api', function (Request $request) {
            $by = $request->user()?->id ?? $request->ip();

            return $request->isMethodSafe()
                ? Limit::perMinute((int) config('app.throttle_reads', 1200))->by("read:$by")
                : Limit::perMinute((int) config('app.throttle_writes', 60))->by("write:$by");
        });

        /**
         * Get total number of seconds contained in a DateInterval
         *
         * @param DateInterval $interval
         * @return int
         */
        Date::macro('intervalInSeconds', function (DateInterval $interval): int {
            return (new DateTime())->setTimeStamp(0)->add($interval)->getTimeStamp();
        });

        /**
         * Convert a potentially null Carbon timestamp to string
         *
         * @param Carbon|null $date
         * @return string|null
         */
        Date::macro('maybeToString', function (?Carbon $date): ?string {
            return $date !== null ? $date->toISOString() : null;
        });
    }
}
