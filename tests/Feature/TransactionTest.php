<?php

use Illuminate\Support\Facades\DB;
use Medilies\RmQ\Facades\RmQ;
use Medilies\RmQ\Models\RmqFile;

it('rolls the staging back with the transaction', function () {
    $file = $this->temporaryFile();

    expect(fn () => DB::transaction(function () use ($file) {
        RmQ::stage($file);

        throw new RuntimeException('Rolled back');
    }))->toThrow(RuntimeException::class);

    RmQ::deleteAll(after: 0);

    expect(RmqFile::query()->count())->toBe(0)
        ->and($file)->toBeFile();
});

it('keeps nothing from a nested transaction whose parent rolls back', function () {
    $file = $this->temporaryFile();

    expect(fn () => DB::transaction(function () use ($file) {
        DB::transaction(fn () => RmQ::stage($file)); // the savepoint is released...

        throw new RuntimeException('...but the outer transaction rolls back');
    }))->toThrow(RuntimeException::class);

    RmQ::deleteAll(after: 0);

    expect(RmqFile::query()->count())->toBe(0)
        ->and($file)->toBeFile();
});

it('keeps the outer staging when a nested transaction rolls back', function () {
    [$outer, $inner] = $this->temporaryFiles(2);

    DB::transaction(function () use ($outer, $inner) {
        RmQ::stage($outer);

        try {
            DB::transaction(function () use ($inner) {
                RmQ::stage($inner);

                throw new RuntimeException('Inner rollback');
            });
        } catch (RuntimeException) {
        }
    });

    expect(RmqFile::query()->pluck('path')->all())->toBe([$outer]);

    RmQ::deleteAll(after: 0);

    expect($outer)->not->toBeFile()
        ->and($inner)->toBeFile();
});
