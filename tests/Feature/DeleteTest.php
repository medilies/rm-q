<?php

use Illuminate\Contracts\Filesystem\Filesystem as FilesystemContract;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Medilies\RmQ\Events\FileDeletionFailed;
use Medilies\RmQ\Events\StagedFilesProcessed;
use Medilies\RmQ\Facades\RmQ;
use Medilies\RmQ\Models\RmqFile;

// How each file is deleted, and what a failure records. The grace period is covered in DeleteAllTest.

it('deletes the staged files and removes their rows', function () {
    $files = $this->temporaryFiles(3);

    RmQ::stage($files);

    expect(RmQ::deleteAll(after: 0)->deleted)->toBe(3);

    foreach ($files as $file) {
        expect($file)->not->toBeFile();
    }

    expect(RmqFile::query()->count())->toBe(0);
});

it('treats an already missing file as deleted', function () {
    $file = $this->temporaryFile();
    RmQ::stage($file);
    unlink($file);

    expect(RmQ::deleteAll(after: 0)->deleted)->toBe(1)
        ->and(RmqFile::query()->count())->toBe(0);
});

it('does not delete directories', function () {
    $directory = $this->temporaryDirectory();
    RmQ::stage($directory);

    RmQ::deleteAll(after: 0);

    expect($directory)->toBeDirectory()
        ->and(RmqFile::query()->sole())
        ->attempts->toBe(1)
        ->error->toBe('The path is a directory');
});

it('removes a symlink but not its target', function () {
    $target = $this->temporaryFile();
    $link = $this->temporaryDirectory().DIRECTORY_SEPARATOR.'link';
    symlink($target, $link);

    RmQ::stage($link);
    RmQ::deleteAll(after: 0);

    expect(is_link($link))->toBeFalse()
        ->and($target)->toBeFile()
        ->and(RmqFile::query()->count())->toBe(0);
})->skipOnWindows();

it('records why a local file could not be deleted', function () {
    $file = $this->temporaryFile();
    chmod(dirname($file), 0555);

    try {
        RmQ::stage($file);
        RmQ::deleteAll(after: 0);
    } finally {
        chmod(dirname($file), 0755);
    }

    expect($file)->toBeFile()
        ->and(RmqFile::query()->sole()->error)->toContain('Permission denied');
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

it('checks the allowed directories again before deleting', function () {
    $file = $this->temporaryFile();
    RmQ::stage($file);
    config(['rm-q.allowed_directories' => [$this->temporaryDirectory()]]);

    RmQ::deleteAll(after: 0);

    expect($file)->toBeFile()
        ->and(RmqFile::query()->sole()->error)->toContain('allowed_directories');
});

it('checks where directories really are when deleting', function () {
    $allowed = $this->temporaryDirectory();
    $outside = $this->temporaryDirectory();
    file_put_contents($outside.DIRECTORY_SEPARATOR.'secret.txt', 'secret');
    mkdir($allowed.DIRECTORY_SEPARATOR.'uploads');
    config(['rm-q.allowed_directories' => [$allowed]]);

    RmQ::stage($allowed.DIRECTORY_SEPARATOR.'uploads'.DIRECTORY_SEPARATOR.'secret.txt');

    // The directory is swapped for a symlink between staging and deleting.
    rmdir($allowed.DIRECTORY_SEPARATOR.'uploads');
    symlink($outside, $allowed.DIRECTORY_SEPARATOR.'uploads');

    RmQ::deleteAll(after: 0);

    expect($outside.DIRECTORY_SEPARATOR.'secret.txt')->toBeFile()
        ->and(RmqFile::query()->sole()->error)->toContain('allowed_directories');
})->skipOnWindows();

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
