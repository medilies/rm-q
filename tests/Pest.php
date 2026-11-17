<?php

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnableToDeleteFile;
use Medilies\RmQ\Enums\Status;
use Medilies\RmQ\Models\RmqFile;
use Symfony\Component\Uid\Ulid;
use Tests\IntegrationTestCase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->in('Feature');

// Real transaction levels and child processes: run against file-based SQLite or a database server.
uses(IntegrationTestCase::class, DatabaseMigrations::class)->group('integration')->in('Integration');

/** A configured disk whose deletions fail with the given reason, the way Flysystem reports it. */
function brokenDisk(string $reason, string $name = 'broken', bool $throw = false): void
{
    config(["filesystems.disks.{$name}" => ['driver' => 'local', 'root' => sys_get_temp_dir(), 'throw' => $throw]]);

    $driver = Mockery::mock(FilesystemOperator::class);
    $driver->shouldReceive('delete')->andThrow(new UnableToDeleteFile($reason));

    Storage::set($name, new FilesystemAdapter($driver, new LocalFilesystemAdapter(sys_get_temp_dir()), ['throw' => $throw]));
}

/**
 * A row staged by another request or job, possibly long ago.
 *
 * @param  array<string, mixed>  $attributes
 */
function stagedRow(string $path, array $attributes = []): RmqFile
{
    $id = RmqFile::query()->insertGetId([
        'path' => $path,
        'disk' => null,
        'instance' => (string) new Ulid,
        'status' => Status::Staged->value,
        'attempts' => 0,
        'staged_at' => Date::now(),
        ...$attributes,
    ]);

    return RmqFile::query()->findOrFail($id);
}

/**
 * Makes the next $times claim statements of a sweep fail with the given error, before they reach the database.
 *
 * @return object{count: int} How many claims failed so far.
 */
function failClaims(int $times, string $error): object
{
    $failures = new class
    {
        public int $count = 0;
    };

    DB::connection()->beforeExecuting(function (string $query) use ($failures, $times, $error) {
        // The claim is the only statement that increments the attempts.
        if (preg_match('/^update\b.*attempts\W*\s*\+\s*1/i', $query) === 1 && $failures->count < $times) {
            $failures->count++;

            throw new RuntimeException($error);
        }
    });

    return $failures;
}
