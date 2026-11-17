<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Medilies\RmQ\Exceptions\RmqException;
use Medilies\RmQ\Facades\RmQ;
use Medilies\RmQ\Models\RmqFile;

it('stages one row per path, on the default disk', function () {
    $files = $this->temporaryFiles(2);

    expect(RmQ::stage($files))->toBe(2);

    expect(RmqFile::query()->orderBy('id')->get())
        ->toHaveCount(2)
        ->sequence(
            fn ($row) => $row->path->toBe($files[0]),
            fn ($row) => $row->path->toBe($files[1]),
        )
        ->each(fn ($row) => $row
            ->disk->toBe('temp')
            ->attempts->toBe(0)
            ->error->toBeNull());

    Storage::assertExists($files);
});

it('accepts a string, a Stringable, collections and generators', function () {
    [$a, $b, $c, $d] = $this->temporaryFiles(4);

    $generator = (function () use ($d) {
        yield $d;
    })();

    expect(RmQ::stage($a))->toBe(1)
        ->and(RmQ::stage(str($b)))->toBe(1)
        ->and(RmQ::stage(collect([$c])))->toBe(1)
        ->and(RmQ::stage($generator))->toBe(1)
        ->and(RmqFile::query()->pluck('path')->sort()->values()->all())->toBe(collect([$a, $b, $c, $d])->sort()->values()->all());
});

it('stages duplicates once and nothing for an empty list', function () {
    $file = $this->temporaryFile();

    DB::enableQueryLog();

    expect(RmQ::stage([]))->toBe(0)
        ->and(DB::getQueryLog())->toBe([])
        ->and(RmQ::stage([$file, $file]))->toBe(1)
        ->and(RmqFile::query()->count())->toBe(1);
});

it('stages paths on a disk', function () {
    Storage::fake('s3');

    expect(RmQ::stage(['avatars/1.jpg', 'avatars/2.jpg'], 's3'))->toBe(2)
        ->and(RmqFile::query()->pluck('disk')->unique()->all())->toBe(['s3']);
});

it('rejects invalid paths and stages nothing', function (mixed $paths, string $message) {
    expect(fn () => RmQ::stage($paths))->toThrow(RmqException::class, $message)
        ->and(RmqFile::query()->count())->toBe(0);
})->with([
    'empty' => ['', 'must not be empty'],
    'parent segment' => ['storage/../.env', 'must not contain ".." segments'],
    'parent segment, backslashes' => ['www\\..\\secret.txt', 'must not contain ".." segments'],
    'NUL byte' => ["file\0.txt", 'must not contain NUL bytes'],
    'stream wrapper' => ['phar:///tmp/archive.phar/file.txt', 'must not use a stream wrapper'],
    'not a string' => [[123], 'must be a string'],
    'null in a list' => [[null], 'must be a string'],
    'one bad path among good ones' => [['fine.txt', ''], 'must not be empty'],
]);

it('rejects unknown disks', function () {
    expect(fn () => RmQ::stage('file.txt', 'nope'))->toThrow(RmqException::class, 'disk [nope]: the disk is not configured');
});

it('validates its config', function (array $config, string $exception, string $key) {
    config($config);

    expect(fn () => RmQ::stage('file.txt'))->toThrow($exception, $key);
})->with([
    'no default disk' => [['filesystems.default' => null], RmqException::class, 'filesystems.default'],
    'unknown default disk' => [['filesystems.default' => 'nope'], RmqException::class, 'disk [nope]'],
    'connection not a name' => [['rm-q.connection' => ['mysql']], RmqException::class, 'rm-q.connection'],
    'table not a string' => [['rm-q.table' => null], InvalidArgumentException::class, 'rm-q.table'],
]);

it('stores the default disk by name, so changing it later does not move staged files', function () {
    config(['filesystems.disks.other' => ['driver' => 'local', 'root' => $this->temporaryDirectory()]]);
    $file = $this->temporaryFile();
    RmQ::stage($file);

    config(['filesystems.default' => 'other']);
    RmQ::deleteAll(after: 0);

    Storage::disk('temp')->assertMissing($file);
});

it('stores the staging time from PHP, not from the database clock', function () {
    $this->travelTo(now()->subYears(5)->startOfSecond());

    RmQ::stage($this->temporaryFile());

    expect(RmqFile::query()->sole()->staged_at->equalTo(now()))->toBeTrue();
});
