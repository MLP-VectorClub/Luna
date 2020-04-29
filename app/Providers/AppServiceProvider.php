<?php

namespace App\Providers;

use App\EloquentFixes\CustomDateGrammar;
use App\EloquentFixes\DBAL\Types\Citext;
use Carbon\Carbon;
use DateInterval;
use DateTime;
use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\Types\Type;
use Illuminate\Support\Facades\Date;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     * @throws DBALException
     */
    public function register()
    {
        if (!Type::hasType(Citext::CITEXT)) {
            Type::addType(Citext::CITEXT, Citext::class);
        }
        $conn = DB::connection(DB::getDefaultConnection());
        $platform = $conn->getDoctrineConnection()->getDatabasePlatform();
        if (!$platform->hasDoctrineTypeMappingFor('citext')) {
            $platform->registerDoctrineTypeMapping('citext', Citext::CITEXT);
        }
        $grammar = new class($platform->getDateTimeTzFormatString()) extends PostgresGrammar {
            protected string $format_string;

            public function __construct(string $format_string)
            {
                $this->format_string = $format_string;
            }

            public function getDateFormat()
            {
                return $this->format_string;
            }
        };
        $grammar::macro('typeCitext', fn() => 'citext');
        $conn->setQueryGrammar($grammar);
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
         * @param  DateInterval  $interval
         * @return int
         */
        Date::macro('intervalInSeconds', function (DateInterval $interval): int {
            return (new DateTime())->setTimeStamp(0)->add($interval)->getTimeStamp();
        });

        /**
         * Convert a potentially null Carbon timestamp to string
         *
         * @param  Carbon|null $date
         * @return string|null
         */
        Date::macro('maybeToString', function (?Carbon $date): ?string {
            return $date !== null ? $date->toISOString() : null;
        });
    }
}
