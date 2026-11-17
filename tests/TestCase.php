<?php

namespace Tests;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;
use Medilies\RmQ\RmqServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use TestApp\TestServiceProvider;

use function Orchestra\Testbench\load_migration_paths;

abstract class TestCase extends Orchestra
{
    /** @var list<string> */
    private array $temporaryDirectories = [];

    protected function getPackageProviders($app): array
    {
        return [
            RmqServiceProvider::class,
            TestServiceProvider::class,
        ];
    }

    /** Config only: models must not be booted before the event dispatcher exists. */
    protected function defineEnvironment($app): void
    {
        $connection = env('DB_CONNECTION', 'testing');

        $app['config']->set('database.default', $connection);

        // CI runs the database jobs with a skewed session timezone: grace periods must not depend on it.
        if (is_string($timezone = env('RM_Q_TEST_DB_TIMEZONE'))) {
            $app['config']->set("database.connections.{$connection}.timezone", $timezone);
        }
    }

    /** Registered on the migrator, so both RefreshDatabase and DatabaseMigrations run them with migrate:fresh. */
    protected function defineDatabaseMigrations(): void
    {
        load_migration_paths($this->app, [
            __DIR__.'/../database/migrations',
            __DIR__.'/test-app/database/migrations',
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            (new Filesystem)->deleteDirectory($directory);
        }

        parent::tearDown();
    }

    /** A new empty directory, removed after the test. */
    public function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'rm-q-'.bin2hex(random_bytes(8));

        mkdir($directory);

        return $this->temporaryDirectories[] = $directory;
    }

    /**
     * Files with content, in a new temporary directory.
     *
     * @return list<string>
     */
    public function temporaryFiles(int $count = 1): array
    {
        $directory = $this->temporaryDirectory();
        $files = [];

        for ($i = 0; $i < $count; $i++) {
            $files[] = $file = $directory.DIRECTORY_SEPARATOR."file-{$i}.txt";

            file_put_contents($file, 'rm-q');
        }

        return $files;
    }

    public function temporaryFile(): string
    {
        return $this->temporaryFiles()[0];
    }

    /** Simulates what Octane and queue workers do between two requests or jobs. */
    public function newLifecycle(): void
    {
        $this->app?->forgetScopedInstances();

        Facade::clearResolvedInstances();
    }
}
