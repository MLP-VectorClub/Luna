<?php

namespace App\Providers;

use App\DoctrineExtensions\DBAL\Types\Citext;
use Doctrine\DBAL\Types\Type;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     * @throws \Doctrine\DBAL\DBALException
     */
    public function register()
    {
        Passport::ignoreMigrations();

        Type::addType(Citext::CITEXT, Citext::class);
        $conn = DB::connection(DB::getDefaultConnection());
        $conn->getDoctrineConnection()->getDatabasePlatform()->registerDoctrineTypeMapping('citext', Citext::CITEXT);
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
         * @param  \DateInterval  $interval
         * @return int
         */
        Date::macro('intervalInSeconds', function (\DateInterval $interval): int {
            return (new \DateTime())->setTimeStamp(0)->add($interval)->getTimeStamp();
        });
    }
}
