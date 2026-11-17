<?php

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Medilies\RmQ\Facades\RmQ;
use Medilies\RmQ\Models\RmqFile;

it('deletes from a fresh process', function () {
    // The child process doesn't know the test's default disk: use the testbench app's local disk.
    $file = 'rm-q-'.bin2hex(random_bytes(8)).'.txt';
    Storage::disk('local')->put($file, 'rm-q');
    RmQ::stage($file, 'local');

    $result = Process::path($this->packageRoot())
        ->env($this->processEnvironment() + ['RM_Q_AFTER' => '0'])
        ->run([PHP_BINARY, 'vendor/bin/testbench', 'rm-q:delete']);

    expect($result->errorOutput())->toBe('')
        ->and($result->successful())->toBeTrue()
        ->and($result->output())->toMatch('/\|\s*Deleted\s*\|\s*1\s*\|/')
        ->and(Storage::disk('local')->exists($file))->toBeFalse()
        ->and(RmqFile::query()->count())->toBe(0);
});
