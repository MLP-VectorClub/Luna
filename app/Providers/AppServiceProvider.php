<?php

namespace App\Providers;

use App\EloquentFixes\CustomDateGrammar;
use App\EloquentFixes\DBAL\Types\Citext;
use Carbon\Carbon;
use DateInterval;
use DateTime;
use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\Types\Type;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Date;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use OpenApi\Analysis;
use OpenApi\Annotations\Operation;

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
        $grammar::macro('typeCitext', fn () => 'citext');
        $conn->setQueryGrammar($grammar);

        // Generate reasonable looking operation IDs in OpenAPI documentation
        Analysis::registerProcessor(function (Analysis $analysis) {
            /** @var Operation[] $all_operations */
            $all_operations = $analysis->getAnnotationsOfType(Operation::class);

            foreach ($all_operations as $operation) {
                $operation->operationId = ucfirst(
                    Str::camel(
                        trim(
                            preg_replace(
                                '~_{2,}~',
                                '_',
                                preg_replace(
                                    '~[^a-z_]~i',
                                    '_',
                                    implode('_', [$operation->method, $operation->path])
                                )
                            ),
                            '_'
                        )
                    )
                );
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
