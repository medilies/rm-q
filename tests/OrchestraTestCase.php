<?php

namespace Tests;

use Medilies\RmQ\RmQServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class OrchestraTestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [
            RmQServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        config()->set('database.default', 'testing');

        /*
        $migration = include __DIR__.'/../database/migrations/create_rm_q_table.php';
        $migration->up();
        */
    }
}
