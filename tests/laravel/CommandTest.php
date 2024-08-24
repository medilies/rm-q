<?php

use Illuminate\Support\Facades\Config;
use Medilies\RmQ\Commands\RmqDeleteCommand;
use Medilies\RmQ\Facades\RmQ;
use Medilies\RmQ\Models\RmqFile;
use Tests\OrchestraTestCase;

$signature = (new RmqDeleteCommand)->signature;

test($signature.' before reaching the after config', function () use ($signature) {
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

    $this->artisan($signature)
        ->assertExitCode(0);

    $dbAssertion()->assertFileExists($files[0]);

    depopulateFiles($files);
});

test($signature.' after reaching the after config', function () use ($signature) {
    /** @var OrchestraTestCase $this */
    Config::set('rm-q.after', 0);

    $files = populateFiles();
    $filesCount = count($files);

    RmQ::stage($files);

    $this->assertDatabaseCount(RmqFile::tableName(), $filesCount)
        ->assertDatabaseHas(RmqFile::tableName(), [
            'path' => $files[0],
            'status' => RmqFile::STAGED,
        ]);

    $this->artisan($signature)
        ->assertExitCode(0);

    $this->assertDatabaseCount(RmqFile::tableName(), $filesCount)
        ->assertDatabaseHas(RmqFile::tableName(), [
            'path' => $files[0],
            'status' => RmqFile::DELETED,
        ])
        ->assertFileDoesNotExist($files[0]);
});
