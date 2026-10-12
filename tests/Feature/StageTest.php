<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Medilies\RmQ\Enums\Status;
use Medilies\RmQ\Exceptions\RmqException;
use Medilies\RmQ\Facades\RmQ;
use Medilies\RmQ\Models\RmqFile;

it('stages paths as rows of the current instance', function () {
    $files = $this->temporaryFiles(2);

    expect(RmQ::stage($files))->toBe(2);

    expect(RmqFile::query()->orderBy('id')->get())
        ->toHaveCount(2)
        ->sequence(
            fn ($row) => $row->path->toBe($files[0]),
            fn ($row) => $row->path->toBe($files[1]),
        )
        ->each(fn ($row) => $row
            ->status->toBe(Status::Staged)
            ->instance->toBe(RmQ::instanceId())
            ->disk->toBeNull()
            ->attempts->toBe(0)
            ->processed_at->toBeNull());

    expect($files[0])->toBeFile()->and($files[1])->toBeFile();
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
    'relative without a disk' => ['storage/app/file.txt', 'must be absolute when no disk is given'],
    'parent segment' => ['/var/www/storage/../.env', 'must not contain ".." segments'],
    'parent segment, Windows' => ['C:\\www\\..\\secret.txt', 'must not contain ".." segments'],
    'NUL byte' => ["/tmp/file\0.txt", 'must not contain NUL bytes'],
    'stream wrapper' => ['phar:///tmp/archive.phar/file.txt', 'must not use a stream wrapper'],
    'not a string' => [[123], 'must be a string'],
    'null in a list' => [[null], 'must be a string'],
    'one bad path among good ones' => [['/tmp/fine.txt', ''], 'must not be empty'],
]);

it('accepts Windows absolute paths', function (string $path) {
    expect(RmQ::stage($path))->toBe(1);
})->with([
    'drive' => 'C:\\Users\\app\\file.txt',
    'drive, forward slashes' => 'C:/Users/app/file.txt',
    'UNC' => '\\\\server\\share\\file.txt',
]);

it('rejects unknown disks', function () {
    expect(fn () => RmQ::stage('file.txt', 'nope'))->toThrow(RmqException::class, 'disk [nope]: the disk is not configured');
});

it('accepts relative paths on a disk', function () {
    Storage::fake('s3');

    expect(RmQ::stage('relative/file.txt', 's3'))->toBe(1);
});

it('only stages local paths inside the allowed directories', function () {
    $allowed = $this->temporaryDirectory();
    $outside = $this->temporaryFile();

    config(['rm-q.allowed_directories' => [$allowed]]);

    expect(RmQ::stage($allowed.DIRECTORY_SEPARATOR.'file.txt'))->toBe(1)
        ->and(fn () => RmQ::stage($outside))->toThrow(RmqException::class, 'allowed_directories')
        // A sibling sharing the prefix is not inside the directory.
        ->and(fn () => RmQ::stage($allowed.'-evil'.DIRECTORY_SEPARATOR.'file.txt'))->toThrow(RmqException::class, 'allowed_directories');
});

it('does not let a symlinked directory escape the allowed directories', function () {
    $allowed = $this->temporaryDirectory();
    $outside = $this->temporaryDirectory();
    file_put_contents($outside.DIRECTORY_SEPARATOR.'secret.txt', 'secret');
    symlink($outside, $allowed.DIRECTORY_SEPARATOR.'link');
    config(['rm-q.allowed_directories' => [$allowed]]);

    expect(fn () => RmQ::stage($allowed.DIRECTORY_SEPARATOR.'link'.DIRECTORY_SEPARATOR.'secret.txt'))
        ->toThrow(RmqException::class, 'allowed_directories');
})->skipOnWindows();

it('resolves allowed directories that are symlinks', function () {
    $real = $this->temporaryDirectory();
    $alias = $this->temporaryDirectory().DIRECTORY_SEPARATOR.'alias';
    symlink($real, $alias);
    config(['rm-q.allowed_directories' => [$alias]]);

    expect(RmQ::stage($real.DIRECTORY_SEPARATOR.'file.txt'))->toBe(1)
        ->and(RmQ::stage($alias.DIRECTORY_SEPARATOR.'other.txt'))->toBe(1);
})->skipOnWindows();

it('validates its config', function (array $config, string $key) {
    config($config);

    expect(fn () => RmQ::stage($this->temporaryFile()))->toThrow(RmqException::class, $key);
})->with([
    'relative allowed directory' => [['rm-q.allowed_directories' => ['relative/dir']], 'rm-q.allowed_directories'],
    'allowed directories not a list' => [['rm-q.allowed_directories' => '/tmp'], 'rm-q.allowed_directories'],
    'connection not a name' => [['rm-q.connection' => ['mysql']], 'rm-q.connection'],
    'empty table name' => [['rm-q.table' => ''], 'rm-q.table'],
]);

it('stores the staging time from PHP, not from the database clock', function () {
    $this->travelTo(now()->subYears(5)->startOfSecond());

    RmQ::stage($this->temporaryFile());

    expect(RmqFile::query()->sole()->staged_at->equalTo(now()))->toBeTrue();
});
