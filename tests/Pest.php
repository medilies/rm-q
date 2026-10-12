<?php

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
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
