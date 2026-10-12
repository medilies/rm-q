<?php

namespace Medilies\RmQ;

use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Medilies\RmQ\Commands\RmqCleanupCommand;
use Medilies\RmQ\Commands\RmqDeleteCommand;
use Medilies\RmQ\Commands\RmqStatsCommand;
use Medilies\RmQ\Middleware\RmqMiddleware;

class RmqServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/rm-q.php', 'rm-q');

        // One instance per request (Octane) or job (queue worker).
        $this->app->scoped(RmQ::class);
    }

    public function boot(): void
    {
        $this->callAfterResolving('router', function (Router $router): void {
            $router->aliasMiddleware('rm-q', RmqMiddleware::class);
        });

        $this->registerPublishing();
        $this->registerCommands();
    }

    protected function registerPublishing(): void
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
    }

    protected function registerCommands(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            RmqDeleteCommand::class,
            RmqCleanupCommand::class,
            RmqStatsCommand::class,
        ]);
    }
}
