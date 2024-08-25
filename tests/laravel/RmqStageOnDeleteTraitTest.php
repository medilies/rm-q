<?php

use Medilies\RmQ\Facades\RmQ;
use Medilies\RmQ\Models\RmqFile;
use TestApp\Models\Picture;

it('stages', function () {
    /** @var OrchestraTestCase $this */
    $files = populateFiles();
    $filesCount = count($files);

    $picture = Picture::create(['path' => $files[0]]);

    $this->assertDatabaseCount((new Picture)->getTable(), 1);

    $picture->delete();

    $this->assertDatabaseCount((new Picture)->getTable(), 0);

    // TODO: fix event trigger
    // $this->assertDatabaseCount(RmqFile::tableName(), $filesCount)
    //     ->assertDatabaseHas(RmqFile::tableName(), [
    //         'path' => $files[0],
    //         'status' => RmqFile::STAGED,
    //     ]);

    // RmQ::delete();

    // $this->assertDatabaseCount(RmqFile::tableName(), $filesCount)
    //     ->assertDatabaseHas(RmqFile::tableName(), [
    //         'path' => $files[0],
    //         'status' => RmqFile::DELETED,
    //     ])
    //     ->assertFileDoesNotExist($files[0]);
});
