<?php

namespace App\Providers;

use App\Utils\CamelCaseJsonResponseFactory;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Support\ServiceProvider;

class CamelCaseJsonResponseServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        $view = $this->app->make('view');
        $redirect = $this->app->make('redirect');
        $this->app->singleton(ResponseFactory::class, function () use ($view, $redirect) {
            return new CamelCaseJsonResponseFactory($view, $redirect);
        });
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }
}
