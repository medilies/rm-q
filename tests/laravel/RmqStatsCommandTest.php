<?php

use Medilies\RmQ\Commands\RmqStatsCommand;
use Medilies\RmQ\Facades\RmQ;
use Medilies\RmQ\Models\RmqFile;
use Tests\OrchestraTestCase;

$signature = (new RmqStatsCommand)->signature;

test($signature, function () use ($signature) {
    /** @var OrchestraTestCase $this */
    $files = populateFiles(2);
    $filesCount = count($files);

    RmQ::stage($files);
    Rmq::delete();

    $this->assertDatabaseCount(RmqFile::tableName(), $filesCount)
        ->assertDatabaseHas(RmqFile::tableName(), [
            'path' => $files[0],
            'status' => RmqFile::DELETED,
        ]);

    RmQ::stage($files[1]);

    $this->assertDatabaseHas(RmqFile::tableName(), [
        'path' => $files[1],
        'status' => RmqFile::STAGED,
    ]);

    $this->artisan($signature)->assertExitCode(0);

    depopulateFiles($files);
});
