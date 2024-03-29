<?php

namespace App\Providers;

use App\EloquentFixes\DBAL\Types\CitextType;
use App\EloquentFixes\DBAL\Types\MlpGenerationType;
use Carbon\Carbon;
use DateInterval;
use DateTime;
use Doctrine\DBAL\Exception as DBALException;
use Doctrine\DBAL\Types\Type;
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
     * @throws DBALException
     */
    public function register()
    {
        if (!Type::hasType(CitextType::CITEXT)) {
            Type::addType(CitextType::CITEXT, CitextType::class);
        }
        if (!Type::hasType(MlpGenerationType::MLP_GENERATION)) {
            Type::addType(MlpGenerationType::MLP_GENERATION, MlpGenerationType::class);
        }
        $conn = DB::connection(DB::getDefaultConnection());
        $platform = $conn->getDoctrineConnection()->getDatabasePlatform();
        if (!$platform->hasDoctrineTypeMappingFor(CitextType::CITEXT)) {
            $platform->registerDoctrineTypeMapping(CitextType::CITEXT, CitextType::CITEXT);
        }
        if (!$platform->hasDoctrineTypeMappingFor(MlpGenerationType::MLP_GENERATION)) {
            $platform->registerDoctrineTypeMapping(
                MlpGenerationType::MLP_GENERATION,
                MlpGenerationType::MLP_GENERATION
            );
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
        $grammar::macro('typeCitext', fn() => CitextType::CITEXT);
        $grammar::macro('typeMlp_generation', fn() => MlpGenerationType::MLP_GENERATION);
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
