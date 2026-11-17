<?php

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Medilies\RmQ\Facades\RmQ;
use Medilies\RmQ\Models\RmqFile;

beforeEach(function () {
    config([
        'database.connections.rmq' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        'rm-q.connection' => 'rmq',
    ]);

    $this->artisan('migrate', [
        '--database' => 'rmq',
        '--path' => realpath(__DIR__.'/../../database/migrations'),
        '--realpath' => true,
    ])->assertSuccessful();
});

it('stages on the configured connection', function () {
    RmQ::stage($this->temporaryFile());

    expect(DB::connection('rmq')->table(RmqFile::tableName())->count())->toBe(1)
        ->and(DB::connection()->table(RmqFile::tableName())->count())->toBe(0);
});

it('runs RmQ::transaction() on the configured connection', function () {
    $file = $this->temporaryFile();

    expect(fn () => RmQ::transaction(function (Connection $connection) use ($file) {
        expect($connection->getName())->toBe('rmq');

        RmQ::stage($file);

        throw new RuntimeException('Rolled back');
    }))->toThrow(RuntimeException::class);

    expect(DB::connection('rmq')->table(RmqFile::tableName())->count())->toBe(0);
});

it('cannot roll back with a transaction on another connection', function () {
    // Documents the limitation: rm-q.connection must be the connection of your business transactions.
    expect(fn () => DB::connection()->transaction(function () {
        RmQ::stage($this->temporaryFile());

        throw new RuntimeException('Rolled back');
    }))->toThrow(RuntimeException::class);

    expect(DB::connection('rmq')->table(RmqFile::tableName())->count())->toBe(1);
});

it('deletes through the configured connection', function () {
    $file = $this->temporaryFile();
    RmQ::stage($file);

    RmQ::delete();

    expect($file)->not->toBeFile();
});
