<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Medilies\RmQ\Exceptions\RmqException;
use Medilies\RmQ\Facades\RmQ;
use Medilies\RmQ\Models\RmqFile;
use TestApp\Models\Picture;

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

    expect(DB::connection('rmq')->table((new RmqFile)->getTable())->count())->toBe(1)
        ->and(DB::connection()->table((new RmqFile)->getTable())->count())->toBe(0);
});

it('rolls back with a transaction on the configured connection', function () {
    expect(fn () => DB::connection('rmq')->transaction(function () {
        RmQ::stage($this->temporaryFile());

        throw new RuntimeException('Rolled back');
    }))->toThrow(RuntimeException::class);

    expect(DB::connection('rmq')->table((new RmqFile)->getTable())->count())->toBe(0);
});

it('cannot roll back with a transaction on another connection', function () {
    // Documents the limitation: rm-q.connection must be the connection of your business transactions.
    expect(fn () => DB::connection()->transaction(function () {
        RmQ::stage($this->temporaryFile());

        throw new RuntimeException('Rolled back');
    }))->toThrow(RuntimeException::class);

    expect(DB::connection('rmq')->table((new RmqFile)->getTable())->count())->toBe(1);
});

it('refuses to delete a model on another connection than the rm-q table', function () {
    $file = $this->temporaryFile();
    $picture = Picture::query()->create(['path' => $file]);

    expect(fn () => $picture->delete())->toThrow(RmqException::class, 'connection and the rm-q table on [rmq]');

    expect(Picture::query()->count())->toBe(1)
        ->and(DB::connection('rmq')->table((new RmqFile)->getTable())->count())->toBe(0);
});

it('runs deleteAll() while another connection has a transaction open', function () {
    $file = $this->temporaryFile();
    RmQ::stage($file);

    $report = DB::connection()->transaction(fn () => RmQ::deleteAll(after: 0));

    expect($report->deleted)->toBe(1)
        ->and(Storage::path($file))->not->toBeFile();
});
