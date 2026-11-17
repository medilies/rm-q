<?php

use Illuminate\Support\Facades\Exceptions;
use Medilies\RmQ\Enums\Status;
use Medilies\RmQ\Exceptions\RmqException;
use Medilies\RmQ\Middleware\RmqMiddleware;
use Medilies\RmQ\Models\RmqFile;

it('deletes the files staged during the request', function () {
    $files = $this->temporaryFiles(3);

    $this->post('/stage', ['files' => $files])->assertOk();

    foreach ($files as $file) {
        expect($file)->not->toBeFile();
    }

    expect(RmqFile::query()->where('status', Status::Deleted)->count())->toBe(3);
});

it('keeps the files when the transaction that staged them rolls back', function () {
    $files = $this->temporaryFiles(2);

    $this->post('/stage-in-rolled-back-transaction', ['files' => $files])->assertServerError();

    foreach ($files as $file) {
        expect($file)->toBeFile();
    }

    expect(RmqFile::query()->count())->toBe(0);
});

it('deletes files staged outside a transaction even when the request fails', function () {
    $files = $this->temporaryFiles(2);

    $this->post('/stage-then-throw', ['files' => $files])->assertServerError();

    foreach ($files as $file) {
        expect($file)->not->toBeFile();
    }
});

it('reports deletion errors without affecting the response', function () {
    Exceptions::fake();
    $file = $this->temporaryFile();
    config(['rm-q.chunk' => 0]); // makes the sweep throw

    $this->post('/stage', ['files' => [$file]])->assertOk();

    expect($file)->toBeFile();

    Exceptions::assertReported(RmqException::class);
});

it('is registered as the rm-q middleware alias', function () {
    expect(app('router')->getMiddleware())->toHaveKey('rm-q', RmqMiddleware::class);
});
