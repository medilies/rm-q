<?php

use Illuminate\Support\Facades\Process;
use Medilies\RmQ\Facades\RmQ;
use Medilies\RmQ\Models\RmqFile;

it('deletes from a fresh process', function () {
    $file = $this->temporaryFile();
    RmQ::stage($file);

    $result = Process::path($this->packageRoot())
        ->env($this->processEnvironment() + ['RM_Q_AFTER' => '0'])
        ->run([PHP_BINARY, 'vendor/bin/testbench', 'rm-q:delete']);

    expect($result->errorOutput())->toBe('')
        ->and($result->successful())->toBeTrue()
        ->and($result->output())->toMatch('/\|\s*Deleted\s*\|\s*1\s*\|/')
        ->and($file)->not->toBeFile()
        ->and(RmqFile::query()->count())->toBe(0);
});
