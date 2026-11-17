<?php

namespace Tests;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Medilies\RmQ\RmqServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

use function Orchestra\Testbench\load_migration_paths;

abstract class TestCase extends Orchestra
{
    /**
     * Never load the testbench skeleton's .env: concurrent `vendor/bin/testbench` processes can leave theirs behind
     * (DB_CONNECTION=sqlite, CACHE_STORE=database), which would switch every later test to another database.
     */
    protected $loadEnvironmentVariables = false;

    /** @var list<string> */
    private array $temporaryDirectories = [];

    protected function getPackageProviders($app): array
    {
        return [RmqServiceProvider::class];
    }

    /** Config only: models must not be booted before the event dispatcher exists. */
    protected function defineEnvironment($app): void
    {
        $connection = env('DB_CONNECTION', 'testing');

        $app['config']->set('database.default', $connection);

        // Files are staged on a local disk of their own, the default one.
        $app['config']->set('filesystems.disks.temp', ['driver' => 'local', 'root' => $this->temporaryDirectory()]);
        $app['config']->set('filesystems.default', 'temp');

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
     * Files with content, in a new directory of the default disk.
     *
     * @return list<string> Paths on the default disk.
     */
    public function temporaryFiles(int $count = 1): array
    {
        $directory = 'dir-'.bin2hex(random_bytes(8));
        $files = [];

        for ($i = 0; $i < $count; $i++) {
            $files[] = $file = "{$directory}/file-{$i}.txt";

            Storage::put($file, 'rm-q');
        }

        return $files;
    }

    public function temporaryFile(): string
    {
        return $this->temporaryFiles()[0];
    }
}
