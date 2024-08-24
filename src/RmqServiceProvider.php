<?php

namespace Medilies\RmQ;

use Illuminate\Support\ServiceProvider;
use Medilies\RmQ\Commands\RmqDeleteCommand;

class RmqServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/rm-q.php', 'rm-q');

        $this->app->singleton(RmQ::class, RmQ::class);
    }

    public function boot(): void
    {
        $this->registerPublishing();
        $this->registerCommands();
    }

    protected function registerPublishing(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $publishesMigrationsMethod = method_exists($this, 'publishesMigrations')
            ? 'publishesMigrations'
            : 'publishes';

        $this->{$publishesMigrationsMethod}([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'rm-q-migrations');

        $this->publishes([
            __DIR__.'/../config/rm-q.php' => config_path('rm-q.php'),
        ], 'rm-q-config');
    }

    protected function registerCommands(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            RmqDeleteCommand::class,
        ]);
    }
}
