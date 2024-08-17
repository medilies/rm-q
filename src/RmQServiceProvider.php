<?php

namespace Medilies\RmQ;

use Illuminate\Support\ServiceProvider;
use Medilies\RmQ\Commands\RmQCommand;

class RmQServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/rm-q.php', 'rm-q');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/rm-q.php' => config_path('rm-q.php'),
        ], 'rm-q-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                RmQCommand::class,
            ]);
        }
    }
}
