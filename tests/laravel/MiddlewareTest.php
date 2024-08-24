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
        ->assertFileDoesNotExist($files[0]);
});
