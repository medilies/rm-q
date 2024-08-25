<?php

namespace Tests;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Medilies\RmQ\RmqServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use TestApp\Models\Picture;
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

        (new class extends Migration
        {
            public function up(): void
            {
                Schema::create((new Picture)->getTable(), function (Blueprint $table) {
                    $table->id();
                    $table->timestamps();
                    $table->string('path');
                });
            }
        })->up();

    }
}
