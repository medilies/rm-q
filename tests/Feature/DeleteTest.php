<?php

use Illuminate\Contracts\Filesystem\Filesystem as FilesystemContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Medilies\RmQ\Enums\Status;
use Medilies\RmQ\Events\FileDeletionFailed;
use Medilies\RmQ\Events\StagedFilesProcessed;
use Medilies\RmQ\Facades\RmQ;
use Medilies\RmQ\Models\RmqFile;

it('deletes the files staged by this instance', function () {
    $files = $this->temporaryFiles(3);

    RmQ::stage($files);
    RmQ::delete();

    foreach ($files as $file) {
        expect($file)->not->toBeFile();
    }

    expect(RmqFile::query()->get())
        ->toHaveCount(3)
        ->each(fn ($row) => $row
            ->status->toBe(Status::Deleted)
            ->attempts->toBe(1)
            ->processed_at->not->toBeNull()
            ->error->toBeNull());
});

it('leaves the files staged by other instances alone', function () {
    [$theirs, $mine] = $this->temporaryFiles(2);

    RmQ::stage($theirs);
    $this->newLifecycle();
    RmQ::stage($mine);
    RmQ::delete();

    expect($mine)->not->toBeFile()
        ->and($theirs)->toBeFile()
        ->and(RmqFile::query()->where('path', $theirs)->sole()->status)->toBe(Status::Staged);
});

it('does not query the database when nothing was staged', function () {
    DB::enableQueryLog();

    RmQ::delete();

    expect(DB::getQueryLog())->toBe([]);
});

it('does not process rows twice', function () {
    RmQ::stage($this->temporaryFile());

    RmQ::delete();
    RmQ::delete();

    expect(RmqFile::query()->sole())
        ->status->toBe(Status::Deleted)
        ->attempts->toBe(1);
});

it('treats an already missing file as deleted', function () {
    $file = $this->temporaryFile();
    RmQ::stage($file);
    unlink($file);

    RmQ::delete();

    expect(RmqFile::query()->sole())
        ->status->toBe(Status::Deleted)
        ->error->toBeNull();
});

it('does not delete directories', function () {
    $directory = $this->temporaryDirectory();
    RmQ::stage($directory);

    RmQ::delete();

    expect($directory)->toBeDirectory()
        ->and(RmqFile::query()->sole())
        ->status->toBe(Status::Failed)
        ->error->toBe('The path is a directory');
});

it('removes a symlink but not its target', function () {
    $target = $this->temporaryFile();
    $link = $this->temporaryDirectory().DIRECTORY_SEPARATOR.'link';
    symlink($target, $link);

    RmQ::stage($link);
    RmQ::delete();

    expect(is_link($link))->toBeFalse()
        ->and($target)->toBeFile()
        ->and(RmqFile::query()->sole()->status)->toBe(Status::Deleted);
})->skipOnWindows();

it('records why a local file could not be deleted', function () {
    $file = $this->temporaryFile();
    chmod(dirname($file), 0555);

    try {
        RmQ::stage($file);
        RmQ::delete();
    } finally {
        chmod(dirname($file), 0755);
    }

    expect($file)->toBeFile()
        ->and(RmqFile::query()->sole())
        ->status->toBe(Status::Failed)
        ->error->toContain('Permission denied');
})->skipOnWindows()->skip(fn () => function_exists('posix_geteuid') && posix_geteuid() === 0, 'root ignores permissions');

it('deletes files on a disk', function () {
    Storage::fake('s3');
    Storage::disk('s3')->put('avatars/1.jpg', 'image');

    RmQ::stage('avatars/1.jpg', 's3');
    RmQ::delete();

    Storage::disk('s3')->assertMissing('avatars/1.jpg');

    expect(RmqFile::query()->sole()->status)->toBe(Status::Deleted);
});

it('treats a file already missing from a disk as deleted', function () {
    Storage::fake('s3');

    RmQ::stage('avatars/missing.jpg', 's3');
    RmQ::delete();

    expect(RmqFile::query()->sole()->status)->toBe(Status::Deleted);
});

it('records the reason a disk gave', function () {
    brokenDisk('Unable to delete file located at: avatars/1.jpg. Access Denied');

    RmQ::stage('avatars/1.jpg', 'broken');
    RmQ::delete();

    expect(RmqFile::query()->sole())
        ->status->toBe(Status::Failed)
        ->error->toBe('Unable to delete file located at: avatars/1.jpg. Access Denied');
});

it('fails when a disk returns false', function () {
    config(['filesystems.disks.broken' => ['driver' => 'local', 'root' => $this->temporaryDirectory()]]);
    $disk = Mockery::mock(FilesystemContract::class);
    $disk->shouldReceive('delete')->andReturnFalse();
    Storage::set('broken', $disk);

    RmQ::stage('file.txt', 'broken');
    RmQ::delete();

    expect(RmqFile::query()->sole())
        ->status->toBe(Status::Failed)
        ->error->toBe('The disk could not delete the file');
});

it('fails when the disk is no longer configured', function () {
    Storage::fake('s3');
    RmQ::stage('file.txt', 's3');
    config(['filesystems.disks.s3' => null]);
    Storage::forgetDisk('s3');

    RmQ::delete();

    expect(RmqFile::query()->sole())
        ->status->toBe(Status::Failed)
        ->error->toContain('Disk [s3] does not have a configured driver');
});

it('checks the allowed directories again before deleting', function () {
    $file = $this->temporaryFile();
    RmQ::stage($file);
    config(['rm-q.allowed_directories' => [$this->temporaryDirectory()]]);

    RmQ::delete();

    expect($file)->toBeFile()
        ->and(RmqFile::query()->sole())
        ->status->toBe(Status::Failed)
        ->error->toContain('allowed_directories');
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

    RmQ::delete();

    expect($outside.DIRECTORY_SEPARATOR.'secret.txt')->toBeFile()
        ->and(RmqFile::query()->sole())
        ->status->toBe(Status::Failed)
        ->error->toContain('allowed_directories');
})->skipOnWindows();

it('records the outcomes before a listener can throw', function () {
    brokenDisk('Access Denied');
    Event::listen(FileDeletionFailed::class, fn () => throw new RuntimeException('The listener is down'));
    RmQ::stage('file.txt', 'broken');

    expect(fn () => RmQ::delete())->toThrow(RuntimeException::class, 'The listener is down');

    expect(RmqFile::query()->sole())
        ->status->toBe(Status::Failed)
        ->error->toBe('Access Denied');
});

it('reports failures through events and the log', function () {
    Event::fake([FileDeletionFailed::class, StagedFilesProcessed::class]);
    Log::spy();
    brokenDisk('Access Denied');

    RmQ::stage('avatars/1.jpg', 'broken');
    RmQ::delete();

    Event::assertDispatched(FileDeletionFailed::class, fn (FileDeletionFailed $event) => $event->path === 'avatars/1.jpg'
        && $event->disk === 'broken'
        && $event->error === 'Access Denied'
        && $event->attempts === 1
        && $event->willRetry);

    Event::assertDispatched(StagedFilesProcessed::class, fn (StagedFilesProcessed $event) => $event->report->failed === 1
        && $event->report->deleted === 0);

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message) => str_contains($message, '1 staged file(s) could not be deleted'));
});

it('does not log when everything was deleted', function () {
    Log::spy();

    RmQ::stage($this->temporaryFile());
    RmQ::delete();

    Log::shouldNotHaveReceived('warning');
});
