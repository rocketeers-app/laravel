<?php

namespace Rocketeers\Laravel;

use Exception;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Log\LogManager;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Monolog\Logger;
use Rocketeers\Laravel\Console\Commands\TestRocketeersCommand;
use Rocketeers\Rocketeers;

class RocketeersLoggerServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     */
    public function boot()
    {
        $this->publishes([
            __DIR__.'/../config/rocketeers.php' => config_path('rocketeers.php'),
        ], 'config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                TestRocketeersCommand::class,
            ]);
        }
    }

    /**
     * Register the application services.
     */
    public function register()
    {
        $source = realpath($raw = __DIR__.'/../config/rocketeers.php') ?: $raw;

        $this->mergeConfigFrom($source, 'rocketeers');

        $this->app->register(RocketeersEventServiceProvider::class);

        $this->app->singleton('rocketeers.client', function () {
            return new Rocketeers(config('rocketeers.api_token'));
        });

        $this->app->bind(Rocketeers::class, 'rocketeers.client');

        $this->app->singleton('rocketeers.logger', function ($app) {
            $handler = new RocketeersLoggerHandler($app->make('rocketeers.client'));

            $logger = new Logger('Rocketeers');
            $logger->pushHandler($handler);

            return $logger;
        });

        if ($this->app['log'] instanceof LogManager) {
            Log::extend('rocketeers', function ($app) {
                return $app['rocketeers.logger'];
            });
        } else {
            $this->app['log']->listen(function (MessageLogged $messageLogged) {
                try {
                    $this->app['rocketeers.logger']->report([
                        'level' => $messageLogged->level,
                        'message' => $messageLogged->message,
                        'context' => $messageLogged->context,
                    ]);
                } catch (Exception $exception) {
                    return;
                }
            });
        }
    }
}
