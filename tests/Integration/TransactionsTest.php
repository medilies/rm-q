<?php

use Medilies\RmQ\Enums\Status;
use Medilies\RmQ\Facades\RmQ;
use Medilies\RmQ\Models\RmqFile;

// No test transaction wraps these: they exercise the real transaction levels and callback manager.

it('stages exactly once when a transaction is retried after a deadlock', function () {
    $file = $this->temporaryFile();
    $attempts = 0;

    RmQ::transaction(function () use ($file, &$attempts) {
        RmQ::stage($file);

        if (++$attempts === 1) {
            throw new RuntimeException('Deadlock found when trying to get lock; try restarting transaction');
        }
    }, attempts: 3);

    expect($attempts)->toBe(2)
        ->and(RmqFile::query()->count())->toBe(1);
});

it('defers delete() until the commit', function () {
    $file = $this->temporaryFile();

    RmQ::transaction(function () use ($file) {
        RmQ::stage($file);
        RmQ::delete();

        expect($file)->toBeFile();
    });

    expect($file)->not->toBeFile()
        ->and(RmqFile::query()->sole()->status)->toBe(Status::Deleted);
});

it('drops a deferred delete() on rollback', function () {
    $file = $this->temporaryFile();

    expect(fn () => RmQ::transaction(function () use ($file) {
        RmQ::stage($file);
        RmQ::delete();

        throw new RuntimeException('Rolled back');
    }))->toThrow(RuntimeException::class);

    expect($file)->toBeFile()
        ->and(RmqFile::query()->count())->toBe(0);
});

it('runs deleteAll() outside of a transaction', function () {
    $file = $this->temporaryFile();
    RmQ::stage($file);

    expect(RmQ::deleteAll(after: 0)->deleted)->toBe(1)
        ->and($file)->not->toBeFile();
});
