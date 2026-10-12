<?php

use Illuminate\Support\Facades\Exceptions;
use Medilies\RmQ\Enums\Status;
use Medilies\RmQ\Exceptions\RmqException;
use Medilies\RmQ\Models\RmqFile;
use TestApp\Jobs\StageFilesJob;

beforeEach(function () {
    config(['queue.failed.driver' => 'null']);
});

it('deletes the files staged by the job', function () {
    $files = $this->temporaryFiles(2);

    dispatch_sync(new StageFilesJob($files));

    expect($files[0])->not->toBeFile()
        ->and($files[1])->not->toBeFile()
        ->and(RmqFile::query()->where('status', Status::Deleted)->count())->toBe(2);
});

it('deletes files staged outside a transaction even when the job fails', function () {
    $file = $this->temporaryFile();

    expect(fn () => dispatch_sync(new StageFilesJob([$file], 'throw')))->toThrow(RuntimeException::class);

    expect($file)->not->toBeFile();
});

it('keeps the files of work the job rolled back', function () {
    $file = $this->temporaryFile();

    expect(fn () => dispatch_sync(new StageFilesJob([$file], 'rollback')))->toThrow(RuntimeException::class);

    expect($file)->toBeFile()
        ->and(RmqFile::query()->count())->toBe(0);
});

it('reports deletion errors without failing the job', function () {
    Exceptions::fake();
    $file = $this->temporaryFile();
    config(['rm-q.chunk' => 0]); // makes the sweep throw

    dispatch_sync(new StageFilesJob([$file]));

    expect($file)->toBeFile();

    Exceptions::assertReported(RmqException::class);
});
