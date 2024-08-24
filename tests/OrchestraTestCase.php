<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Medilies\RmQ\RmqServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use TestApp\TestServiceProvider;

class OrchestraTestCase extends Orchestra
{
    use RefreshDatabase;

    protected function getPackageProviders($app)
    {
        return [
            RmqServiceProvider::class,
            TestServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        Config::set('database.default', 'testing');

        $migration = require __DIR__.'/../database/migrations/2019_04_19_100000_create_rm_q_table.php';
        $migration->up();
    }
}
