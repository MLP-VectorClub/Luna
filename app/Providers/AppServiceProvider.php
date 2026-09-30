<?php

namespace App\Providers;

use App\EloquentFixes\DBAL\Types\CitextType;
use App\EloquentFixes\DBAL\Types\MlpGenerationType;
use Carbon\Carbon;
use DateInterval;
use DateTime;
use Illuminate\Database\Grammar;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
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
        Grammar::macro('typeMlp_generation', fn() => MlpGenerationType::MLP_GENERATION);

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
