<?php

use Illuminate\Contracts\Filesystem\Filesystem as FilesystemContract;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Medilies\RmQ\Events\FileDeletionFailed;
use Medilies\RmQ\Events\StagedFilesProcessed;
use Medilies\RmQ\Facades\RmQ;
use Medilies\RmQ\Models\RmqFile;
use Medilies\RmQ\Support\Paths;

// How each file is deleted, and what a failure records. The grace period is covered in DeleteAllTest.

it('deletes the staged files and removes their rows', function () {
    $files = $this->temporaryFiles(3);

    RmQ::stage($files);

    expect(RmQ::deleteAll(after: 0)->deleted)->toBe(3);

    Storage::assertMissing($files);

    expect(RmqFile::query()->count())->toBe(0);
});

it('treats an already missing file as deleted', function () {
    $file = $this->temporaryFile();
    RmQ::stage($file);
    Storage::delete($file);

    expect(RmQ::deleteAll(after: 0)->deleted)->toBe(1)
        ->and(RmqFile::query()->count())->toBe(0);
});

it('fails when the directory of a missing file is gone, as on an unmounted volume', function () {
    $file = $this->temporaryFile();
    RmQ::stage($file);
    Storage::deleteDirectory(dirname($file));

    $report = RmQ::deleteAll(after: 0);

    expect($report->failed)->toBe(1)
        ->and(RmqFile::query()->sole())
        ->attempts->toBe(1)
        ->error->toBe('The parent directory ['.dirname(Storage::path($file)).'] does not exist: the storage may be unavailable');
});

it('fails when the disk root is gone', function () {
    $root = $this->temporaryDirectory().DIRECTORY_SEPARATOR.'mount';
    config(['filesystems.disks.nfs' => ['driver' => 'local', 'root' => $root, 'lazy_root_creation' => true]]);
    RmQ::stage('file.txt', 'nfs');

    RmQ::deleteAll(after: 0);

    expect(RmqFile::query()->sole()->error)->toBe("The disk root [{$root}] does not exist: the storage may be unavailable")
        ->and($root)->not->toBeDirectory();
});

it('treats local disks and scoped disks over them as local', function (string $disk, bool $local) {
    config(['filesystems.disks' => [
        'temp' => ['driver' => 'local', 'root' => '/tmp'],
        's3' => ['driver' => 's3'],
        'avatars' => ['driver' => 'scoped', 'disk' => 'temp', 'prefix' => 'avatars'],
        'small' => ['driver' => 'scoped', 'disk' => 'avatars', 'prefix' => 'small'],
        'inline' => ['driver' => 'scoped', 'disk' => ['driver' => 'local', 'root' => '/tmp'], 'prefix' => 'x'],
        'bucket' => ['driver' => 'scoped', 'disk' => 's3', 'prefix' => 'x'],
        'loop' => ['driver' => 'scoped', 'disk' => 'loop', 'prefix' => 'x'],
    ]]);

    expect(Paths::isLocalDisk($disk))->toBe($local);
})->with([
    'local' => ['temp', true],
    's3' => ['s3', false],
    'scoped over local' => ['avatars', true],
    'scoped over scoped over local' => ['small', true],
    'scoped over an inline local config' => ['inline', true],
    'scoped over s3' => ['bucket', false],
    'scoped over itself' => ['loop', false],
    'unknown' => ['nope', false],
]);

it('checks the root a faked local disk really uses', function () {
    config(['filesystems.disks.public' => ['driver' => 'local', 'root' => '/nonexistent/rm-q/public']]);
    Storage::fake('public');

    RmQ::stage('missing.jpg', 'public');

    expect(RmQ::deleteAll(after: 0)->deleted)->toBe(1);
});

it('does not delete directories', function () {
    Storage::makeDirectory('directory');
    RmQ::stage('directory');

    RmQ::deleteAll(after: 0);

    expect(Storage::path('directory'))->toBeDirectory()
        ->and(RmqFile::query()->sole())
        ->attempts->toBe(1)
        ->error->toContain('Unable to delete file');
});

it('removes a symlink but not its target', function () {
    $target = $this->temporaryDirectory().DIRECTORY_SEPARATOR.'target.txt';
    file_put_contents($target, 'rm-q');
    symlink($target, Storage::path('link'));

    RmQ::stage('link');
    RmQ::deleteAll(after: 0);

    expect(is_link(Storage::path('link')))->toBeFalse()
        ->and($target)->toBeFile()
        ->and(RmqFile::query()->count())->toBe(0);
})->skipOnWindows();

it('records why a local file could not be deleted', function () {
    $file = $this->temporaryFile();
    $directory = dirname(Storage::path($file));
    chmod($directory, 0555);

    try {
        RmQ::stage($file);
        RmQ::deleteAll(after: 0);
    } finally {
        chmod($directory, 0755);
    }

    Storage::assertExists($file);

    expect(RmqFile::query()->sole()->error)->toContain('Unable to delete file');
})->skipOnWindows()->skip(fn () => function_exists('posix_geteuid') && posix_geteuid() === 0, 'root ignores permissions');

it('deletes files on a disk', function () {
    Storage::fake('s3');
    Storage::disk('s3')->put('avatars/1.jpg', 'image');

    RmQ::stage('avatars/1.jpg', 's3');
    RmQ::deleteAll(after: 0);

    Storage::disk('s3')->assertMissing('avatars/1.jpg');

    expect(RmqFile::query()->count())->toBe(0);
});

it('treats a file already missing from a disk as deleted', function () {
    // Faked, but still S3 to rm-q: directories don't exist on their own there.
    Storage::fake('s3');

    RmQ::stage('avatars/missing.jpg', 's3');

    expect(RmQ::deleteAll(after: 0)->deleted)->toBe(1)
        ->and(RmqFile::query()->count())->toBe(0);
});

it('records the reason a disk gave', function () {
    brokenDisk('Unable to delete file located at: avatars/1.jpg. Access Denied');

    RmQ::stage('avatars/1.jpg', 'broken');
    RmQ::deleteAll(after: 0);

    expect(RmqFile::query()->sole()->error)->toBe('Unable to delete file located at: avatars/1.jpg. Access Denied');
});

it('fails when a disk returns false', function () {
    config(['filesystems.disks.broken' => ['driver' => 'local', 'root' => $this->temporaryDirectory()]]);
    $disk = Mockery::mock(FilesystemContract::class);
    $disk->shouldReceive('delete')->andReturnFalse();
    Storage::set('broken', $disk);

    RmQ::stage('file.txt', 'broken');
    RmQ::deleteAll(after: 0);

    expect(RmqFile::query()->sole()->error)->toBe('The disk could not delete the file');
});

it('fails when the disk is no longer configured', function () {
    Storage::fake('s3');
    RmQ::stage('file.txt', 's3');
    config(['filesystems.disks.s3' => null]);
    Storage::forgetDisk('s3');

    RmQ::deleteAll(after: 0);

    expect(RmqFile::query()->sole()->error)->toContain('Disk [s3] does not have a configured driver');
});

it('checks the path again before deleting', function () {
    $file = $this->temporaryFile();
    stagedRow('../'.$file);

    RmQ::deleteAll(after: 0);

    Storage::assertExists($file);

    expect(RmqFile::query()->sole()->error)->toBe('The path must not contain ".." segments');
});

it('records the failure before a listener can throw', function () {
    brokenDisk('Access Denied');
    Event::listen(FileDeletionFailed::class, fn () => throw new RuntimeException('The listener is down'));
    RmQ::stage('file.txt', 'broken');

    expect(fn () => RmQ::deleteAll(after: 0))->toThrow(RuntimeException::class, 'The listener is down');

    expect(RmqFile::query()->sole())
        ->attempts->toBe(1)
        ->error->toBe('Access Denied');
});

it('reports failures through events', function () {
    Event::fake([FileDeletionFailed::class, StagedFilesProcessed::class]);
    brokenDisk('Access Denied');

    RmQ::stage('avatars/1.jpg', 'broken');
    RmQ::deleteAll(after: 0);

    Event::assertDispatched(FileDeletionFailed::class, fn (FileDeletionFailed $event) => $event->path === 'avatars/1.jpg'
        && $event->disk === 'broken'
        && $event->error === 'Access Denied'
        && $event->attempts === 1
        && $event->willRetry);

    Event::assertDispatched(StagedFilesProcessed::class, fn (StagedFilesProcessed $event) => $event->report->failed === 1
        && $event->report->deleted === 0);
});
