<?php

use Illuminate\Process\Pool;
use Illuminate\Support\Facades\Process;
use Medilies\RmQ\Enums\Status;
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
        ->and($result->output())->toContain('Deleted 1 staged file(s), 0 failed.')
        ->and($file)->not->toBeFile()
        ->and(RmqFile::query()->sole()->status)->toBe(Status::Deleted);
});

it('processes each row exactly once with concurrent sweeps', function () {
    $files = $this->temporaryFiles(5000);
    RmQ::stage($files);

    $results = Process::pool(function (Pool $pool) {
        foreach (range(1, 4) as $worker) {
            $pool->as("worker-{$worker}")
                ->path($this->packageRoot())
                ->env($this->processEnvironment() + ['RM_Q_AFTER' => '0'])
                ->command([PHP_BINARY, 'vendor/bin/testbench', 'rm-q:delete']);
        }
    })->start()->wait();

    $deletedPerWorker = [];

    foreach ($results->collect() as $name => $result) {
        expect($result->successful())->toBeTrue("{$name}: {$result->errorOutput()}");

        expect(preg_match('/Deleted (\d+) staged file\(s\), (\d+) failed\./', $result->output(), $matches))->toBe(1, $result->output());
        $deletedPerWorker[$name] = (int) $matches[1];

        expect((int) $matches[2])->toBe(0);
    }

    // The workers' counts add up exactly: no row was deleted twice.
    expect(array_sum($deletedPerWorker))->toBe(5000)
        ->and(RmqFile::query()->where('status', Status::Deleted)->count())->toBe(5000)
        ->and(RmqFile::query()->where('attempts', '!=', 1)->count())->toBe(0);

    foreach ($files as $file) {
        expect($file)->not->toBeFile();
    }
});
