<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Medilies\RmQ\Facades\RmQ;
use Medilies\RmQ\Models\RmqFile;

it('indexes the grace period column', function () {
    $indexes = collect(Schema::getIndexes((new RmqFile)->getTable()))->pluck('columns')->all();

    expect($indexes)->toContain(['staged_at']);
});

it('stores long paths', function () {
    Storage::fake('s3');
    $path = str_repeat('nested-directory/', 88).'file.txt'; // 1504 characters

    RmQ::stage($path, 's3');

    expect(RmqFile::query()->sole()->path)->toBe($path);
});

it('creates and drops the table named in the config, on the configured connection', function () {
    config([
        'database.connections.rmq' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        'rm-q.connection' => 'rmq',
        'rm-q.table' => 'custom_rm_q_files',
    ]);
    $options = ['--database' => 'rmq', '--path' => realpath(__DIR__.'/../../database/migrations'), '--realpath' => true];

    $this->artisan('migrate', $options)->assertSuccessful();

    expect(Schema::connection('rmq')->hasTable('custom_rm_q_files'))->toBeTrue();

    $this->artisan('migrate:rollback', $options)->assertSuccessful();

    expect(Schema::connection('rmq')->hasTable('custom_rm_q_files'))->toBeFalse();
});

it('does not depend on the package classes, so it still runs once the package is removed', function () {
    $migrations = glob(__DIR__.'/../../database/migrations/*.php') ?: [];

    expect($migrations)->not->toBeEmpty();

    foreach ($migrations as $migration) {
        expect(file_get_contents($migration))->not->toContain('Medilies\\');
    }
});
