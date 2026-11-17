<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Medilies\RmQ\Enums\Status;
use Medilies\RmQ\Exceptions\RmqException;
use Medilies\RmQ\Facades\RmQ;
use Medilies\RmQ\Models\RmqFile;

it('returns the value of the callback', function () {
    expect(RmQ::transaction(fn () => 'result'))->toBe('result');
});

it('rolls the staging back with an RmQ transaction', function () {
    $file = $this->temporaryFile();

    expect(fn () => RmQ::transaction(function () use ($file) {
        RmQ::stage($file);

        throw new RuntimeException('Rolled back');
    }))->toThrow(RuntimeException::class);

    RmQ::delete();

    expect(RmqFile::query()->count())->toBe(0)
        ->and($file)->toBeFile();
});

it('rolls the staging back with a DB transaction', function () {
    $file = $this->temporaryFile();

    expect(fn () => DB::transaction(function () use ($file) {
        RmQ::stage($file);

        throw new RuntimeException('Rolled back');
    }))->toThrow(RuntimeException::class);

    RmQ::delete();

    expect(RmqFile::query()->count())->toBe(0)
        ->and($file)->toBeFile();
});

it('keeps nothing from a nested transaction whose parent rolls back', function () {
    $file = $this->temporaryFile();

    expect(fn () => DB::transaction(function () use ($file) {
        RmQ::transaction(fn () => RmQ::stage($file)); // the savepoint is released...

        throw new RuntimeException('...but the outer transaction rolls back');
    }))->toThrow(RuntimeException::class);

    RmQ::delete();

    expect(RmqFile::query()->count())->toBe(0)
        ->and($file)->toBeFile();
});

it('keeps the outer staging when a nested transaction rolls back', function () {
    [$outer, $inner] = $this->temporaryFiles(2);

    RmQ::transaction(function () use ($outer, $inner) {
        RmQ::stage($outer);

        try {
            RmQ::transaction(function () use ($inner) {
                RmQ::stage($inner);

                throw new RuntimeException('Inner rollback');
            });
        } catch (RuntimeException) {
        }
    });

    expect(RmqFile::query()->pluck('path')->all())->toBe([$outer]);

    RmQ::delete();

    expect($outer)->not->toBeFile()
        ->and($inner)->toBeFile();
});

it('defers delete() until the outermost commit', function () {
    $file = $this->temporaryFile();

    RmQ::transaction(function () use ($file) {
        RmQ::transaction(function () use ($file) {
            RmQ::stage($file);
            RmQ::delete();
        });

        expect($file)->toBeFile(); // the inner commit is only a savepoint
    });

    expect($file)->not->toBeFile()
        ->and(RmqFile::query()->sole()->status)->toBe(Status::Deleted);
});

it('sweeps once when delete() is deferred twice', function () {
    $file = $this->temporaryFile();

    RmQ::transaction(function () use ($file) {
        RmQ::stage($file);
        RmQ::delete();
        RmQ::delete();
    });

    expect($file)->not->toBeFile()
        ->and(RmqFile::query()->sole())
        ->status->toBe(Status::Deleted)
        ->attempts->toBe(1);
});

it('drops a deferred delete() when the transaction rolls back', function () {
    [$before, $inside] = $this->temporaryFiles(2);
    RmQ::stage($before);

    expect(fn () => RmQ::transaction(function () use ($inside) {
        RmQ::stage($inside);
        RmQ::delete();

        throw new RuntimeException('Rolled back');
    }))->toThrow(RuntimeException::class);

    expect($before)->toBeFile()
        ->and($inside)->toBeFile();

    // Rows committed before the transaction are still swept by a later call.
    RmQ::delete();

    expect($before)->not->toBeFile()
        ->and($inside)->toBeFile()
        ->and(RmqFile::query()->pluck('path')->all())->toBe([$before]);
});

it('reports a failing deferred delete() instead of throwing from the commit', function () {
    Exceptions::fake();
    $file = $this->temporaryFile();

    $result = RmQ::transaction(function () use ($file) {
        RmQ::stage($file);
        RmQ::delete();
        config(['rm-q.chunk' => 0]); // makes the deferred sweep throw

        return 'committed';
    });

    expect($result)->toBe('committed')
        ->and($file)->toBeFile()
        ->and(RmqFile::query()->sole()->status)->toBe(Status::Staged);

    Exceptions::assertReported(RmqException::class);
});

it('waits for its own transaction when another connection commits first', function () {
    config(['database.connections.other' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    $file = $this->temporaryFile();

    RmQ::transaction(function () use ($file) {
        RmQ::stage($file);

        DB::connection('other')->transaction(function () {
            // The after-commit callback attaches to the most recent transaction, on any connection.
            RmQ::delete();
        });

        expect($file)->toBeFile();
    });

    expect($file)->not->toBeFile();
});

it('rejects fewer than one attempt', function () {
    expect(fn () => RmQ::transaction(fn () => null, 0))->toThrow(RmqException::class, '[attempts]');
});
