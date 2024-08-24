<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Medilies\RmQ\Facades\RmQ;
use Medilies\RmQ\Models\RmqFile;
use Tests\OrchestraTestCase;

test('stage() adds records to DB', function () {
    /** @var OrchestraTestCase $this */
    RmQ::stage('');

    $this->assertDatabaseCount(RmqFile::tableName(), 1)
        ->assertDatabaseHas(RmqFile::tableName(), [
            'path' => '',
            'status' => RmqFile::STAGED,
        ]);
});

test('stage() and delete()', function () {
    /** @var OrchestraTestCase $this */
    $files = populateFiles();
    $filesCount = count($files);

    RmQ::stage($files);

    $this->assertDatabaseCount(RmqFile::tableName(), $filesCount)
        ->assertDatabaseHas(RmqFile::tableName(), [
            'path' => $files[0],
            'status' => RmqFile::STAGED,
        ]);

    RmQ::delete();

    $this->assertDatabaseCount(RmqFile::tableName(), $filesCount)
        ->assertDatabaseHas(RmqFile::tableName(), [
            'path' => $files[0],
            'status' => RmqFile::DELETED,
        ])
        ->assertFileDoesNotExist($files[0]);
});

test('delete() non existing file', function () {
    /** @var OrchestraTestCase $this */
    $file = __FILE__.'xyz';

    RmQ::stage($file);

    $this->assertDatabaseCount(RmqFile::tableName(), 1)
        ->assertDatabaseHas(RmqFile::tableName(), [
            'path' => $file,
            'status' => RmqFile::STAGED,
        ]);

    RmQ::delete();

    $this->assertDatabaseCount(RmqFile::tableName(), 1)
        ->assertDatabaseHas(RmqFile::tableName(), [
            'path' => $file,
            'status' => RmqFile::FAILED,
        ]);
});

test('deleteAll() before reaching the after config', function () {
    /** @var OrchestraTestCase $this */
    $files = populateFiles();
    $filesCount = count($files);

    RmQ::stage($files);

    $dbAssertion = fn () => $this->assertDatabaseCount(RmqFile::tableName(), $filesCount)
        ->assertDatabaseHas(RmqFile::tableName(), [
            'path' => $files[0],
            'status' => RmqFile::STAGED,
        ]);

    $dbAssertion();

    RmQ::deleteAll();

    $dbAssertion()->assertFileExists($files[0]);

    depopulateFiles($files);
});

test('deleteAll() after reaching the after config', function () {
    /** @var OrchestraTestCase $this */
    $files = populateFiles();
    $filesCount = count($files);
    Config::set('rm-q.after', 0);

    RmQ::stage($files);

    $this->assertDatabaseCount(RmqFile::tableName(), $filesCount)
        ->assertDatabaseHas(RmqFile::tableName(), [
            'path' => $files[0],
            'status' => RmqFile::STAGED,
        ]);

    RmQ::delete();

    $this->assertDatabaseCount(RmqFile::tableName(), $filesCount)
        ->assertDatabaseHas(RmqFile::tableName(), [
            'path' => $files[0],
            'status' => RmqFile::DELETED,
        ])
        ->assertFileDoesNotExist($files[0]);
});

test('Failed transaction results in no staging', function () {
    /** @var OrchestraTestCase $this */
    $files = populateFiles();

    try {
        DB::transaction(function () use ($files) {
            RmQ::stage($files);

            throw new Exception('foo');
        });
    } catch (Throwable) {
    }

    $this->assertDatabaseCount(RmqFile::tableName(), 0);

    depopulateFiles($files);
});
