<?php

use Illuminate\Support\Facades\DB;
use Medilies\RmQ\Exceptions\RmqException;
use Medilies\RmQ\Facades\RmQ;
use Medilies\RmQ\Models\RmqFile;

// No test transaction wraps these: they exercise the real transaction levels and callback manager.

it('stages exactly once when a transaction is retried after a deadlock', function () {
    $file = $this->temporaryFile();
    $attempts = 0;

    DB::transaction(function () use ($file, &$attempts) {
        RmQ::stage($file);

        if (++$attempts === 1) {
            throw new RuntimeException('Deadlock found when trying to get lock; try restarting transaction');
        }
    }, attempts: 3);

    expect($attempts)->toBe(2)
        ->and(RmqFile::query()->count())->toBe(1);
});

it('refuses deleteAll() inside a transaction', function () {
    expect(fn () => DB::transaction(fn () => RmQ::deleteAll(after: 0)))
        ->toThrow(RmqException::class, 'cannot run inside a transaction');
});

it('runs deleteAll() outside of a transaction', function () {
    $file = $this->temporaryFile();
    RmQ::stage($file);

    expect(RmQ::deleteAll(after: 0)->deleted)->toBe(1)
        ->and($file)->not->toBeFile();
});
