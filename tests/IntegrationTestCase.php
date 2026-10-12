<?php

namespace Tests;

/**
 * No wrapping transaction (DatabaseMigrations), and a database that child processes can share:
 * a SQLite file unless DB_CONNECTION points at a database server.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        if ($app['config']->get('database.default') !== 'testing') {
            return;
        }

        $database = dirname(__DIR__).'/build/integration.sqlite';

        if (! is_dir(dirname($database))) {
            mkdir(dirname($database), recursive: true);
        }

        touch($database);

        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite.database', $database);
    }

    /**
     * Environment for child processes, so they use the same database as the test.
     *
     * @return array<string, string>
     */
    protected function processEnvironment(): array
    {
        $connection = (string) config('database.default');

        return array_filter([
            'DB_CONNECTION' => $connection,
            'DB_DATABASE' => $connection === 'sqlite' ? (string) config('database.connections.sqlite.database') : null,
        ]);
    }

    protected function packageRoot(): string
    {
        return dirname(__DIR__);
    }
}
