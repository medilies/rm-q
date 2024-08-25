<?php

use Medilies\RmQ\Commands\RmqCleanupCommand;
use Medilies\RmQ\Facades\RmQ;
use Medilies\RmQ\Models\RmqFile;
use Tests\OrchestraTestCase;

$signature = (new RmqCleanupCommand)->signature;

test($signature, function () use ($signature) {
    /** @var OrchestraTestCase $this */
    $files = populateFiles();

    RmQ::stage($files[0]);
    Rmq::delete();

    $this->assertDatabaseHas(RmqFile::tableName(), [
        'path' => $files[0],
        'status' => RmqFile::DELETED,
    ]);

    $this->artisan($signature)->assertExitCode(0);

    $this->assertDatabaseCount(RmqFile::tableName(), 0);
});
