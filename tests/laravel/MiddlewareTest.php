<?php

use Medilies\RmQ\Models\RmqFile;
use Tests\OrchestraTestCase;

test('/test-middleware', function () {
    /** @var OrchestraTestCase $this */
    $files = populateFiles(3);
    $filesCount = count($files);

    $this->post('/test-middleware', ['files' => $files])
        ->assertStatus(200);

    $this->assertDatabaseCount(RmqFile::tableName(), $filesCount)
        ->assertDatabaseHas(RmqFile::tableName(), [
            'path' => $files[0],
            'status' => RmqFile::DELETED,
        ])
        ->assertFileDoesNotExist($files[0]);
});

test('/test-middleware-exception', function () {
    /** @var OrchestraTestCase $this */
    $files = populateFiles(3);
    $filesCount = count($files);

    $this->post('/test-middleware-exception', ['files' => $files])
        ->assertStatus(500);

    $this->assertDatabaseCount(RmqFile::tableName(), $filesCount)
        ->assertDatabaseHas(RmqFile::tableName(), [
            'path' => $files[0],
            'status' => RmqFile::DELETED,
        ])
        ->assertFileDoesNotExist($files[0]);

    depopulateFiles($files);
});
