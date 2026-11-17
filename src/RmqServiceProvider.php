<?php

namespace Medilies\RmQ;

use Illuminate\Support\ServiceProvider;
use Medilies\RmQ\Commands\RmqDeleteCommand;
use Medilies\RmQ\Commands\RmqRetryCommand;
use Medilies\RmQ\Commands\RmqStatsCommand;

class RmqServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/rm-q.php', 'rm-q');
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'rm-q-migrations');

        $this->publishes([
            __DIR__.'/../config/rm-q.php' => config_path('rm-q.php'),
        ], 'rm-q-config');

        $this->commands([
            RmqDeleteCommand::class,
            RmqStatsCommand::class,
            RmqRetryCommand::class,
        ]);
    }
}
