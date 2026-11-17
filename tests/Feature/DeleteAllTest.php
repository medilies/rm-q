<?php

use Illuminate\Contracts\Filesystem\Filesystem as FilesystemContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Medilies\RmQ\DeletionReport;
use Medilies\RmQ\Events\StagedFilesProcessed;
use Medilies\RmQ\Exceptions\RmqException;
use Medilies\RmQ\Facades\RmQ;
use Medilies\RmQ\Models\RmqFile;

it('waits for the grace period', function () {
    config(['rm-q.after' => 3600]);
    $this->freezeTime();
    $file = $this->temporaryFile();
    RmQ::stage($file);

    $this->travel(3599)->seconds();
    $early = RmQ::deleteAll();

    expect($early->processed())->toBe(0)
        ->and($file)->toBeFile();

    $this->travel(2)->seconds();
    $onTime = RmQ::deleteAll();

    expect($onTime->deleted)->toBe(1)
        ->and($file)->not->toBeFile()
        ->and(RmqFile::query()->count())->toBe(0);
});

it('takes the grace period as an argument', function () {
    config(['rm-q.after' => 3600]);
    $file = $this->temporaryFile();
    RmQ::stage($file);

    expect(RmQ::deleteAll(after: 0)->deleted)->toBe(1)
        ->and($file)->not->toBeFile();
});

it('rejects invalid config and arguments', function (array $config, array $arguments, string $exception, string $message) {
    config($config);

    expect(fn () => RmQ::deleteAll(...$arguments))->toThrow($exception, $message);
})->with([
    'after config not an integer' => [['rm-q.after' => 'one day'], [], InvalidArgumentException::class, 'rm-q.after'],
    'negative after config' => [['rm-q.after' => -1], [], RmqException::class, 'rm-q.after'],
    'chunk config' => [['rm-q.chunk' => 0], [], RmqException::class, 'rm-q.chunk'],
    'max_attempts config' => [['rm-q.max_attempts' => 0], [], RmqException::class, 'rm-q.max_attempts'],
    'negative after' => [[], ['after' => -1], RmqException::class, '[after]'],
    'limit' => [[], ['limit' => 0], RmqException::class, '[limit]'],
]);

it('rejects a negative grace period when counting', function () {
    expect(fn () => RmQ::countDeletable(-1))->toThrow(RmqException::class, '[after]');
});

it('refuses to run inside a transaction', function () {
    expect(fn () => DB::transaction(fn () => RmQ::deleteAll()))
        ->toThrow(RmqException::class, 'cannot run inside a transaction on the rm-q connection');
});

it('stops at the limit, oldest first', function () {
    $files = $this->temporaryFiles(5);
    RmQ::stage($files);

    $report = RmQ::deleteAll(after: 0, limit: 2);

    expect($report->processed())->toBe(2)
        ->and(RmqFile::query()->orderBy('id')->pluck('path')->all())->toBe(array_slice($files, 2));
});

it('retries a failure at each later run until it runs out of attempts', function () {
    config(['rm-q.max_attempts' => 3]);
    brokenDisk('Access Denied');
    RmQ::stage('file.txt', 'broken');

    $runs = [];

    for ($i = 0; $i < 4; $i++) {
        $runs[] = RmQ::deleteAll(after: 0);
    }

    expect(array_map(fn (DeletionReport $report) => [$report->failed, $report->exhausted], $runs))
        ->toBe([[1, 0], [1, 0], [1, 1], [0, 0]])
        ->and(RmqFile::query()->sole())
        ->attempts->toBe(3)
        ->error->toBe('Access Denied');
});

it('removes the row of a retried file once it succeeds', function () {
    config(['filesystems.disks.flaky' => ['driver' => 'local', 'root' => $this->temporaryDirectory()]]);
    $disk = Mockery::mock(FilesystemContract::class);
    $disk->shouldReceive('delete')->andReturn(false, true);
    Storage::set('flaky', $disk);
    RmQ::stage('file.txt', 'flaky');

    RmQ::deleteAll(after: 0);
    $retry = RmQ::deleteAll(after: 0);

    expect($retry->deleted)->toBe(1)
        ->and(RmqFile::query()->count())->toBe(0);
});

it('counts the attempt before deleting, so a run that dies still uses it up', function () {
    config(['filesystems.disks.slow' => ['driver' => 'local', 'root' => $this->temporaryDirectory()]]);
    $attemptsDuringDelete = null;
    $disk = Mockery::mock(FilesystemContract::class);
    $disk->shouldReceive('delete')->andReturnUsing(function () use (&$attemptsDuringDelete) {
        $attemptsDuringDelete = RmqFile::query()->sole()->attempts;

        return true;
    });
    Storage::set('slow', $disk);
    RmQ::stage('file.txt', 'slow');

    RmQ::deleteAll(after: 0);

    expect($attemptsDuringDelete)->toBe(1);
});

it('leaves alone the files that used up their attempts', function () {
    config(['rm-q.max_attempts' => 3]);
    $file = $this->temporaryFile();
    stagedRow($file, ['attempts' => 3]); // e.g. three runs died while deleting it

    expect(RmQ::deleteAll(after: 0)->processed())->toBe(0)
        ->and($file)->toBeFile();
});

it('skips a row removed while the run was going', function () {
    config(['filesystems.disks.local-mock' => ['driver' => 'local', 'root' => $this->temporaryDirectory()]]);
    $deleted = [];
    $disk = Mockery::mock(FilesystemContract::class);
    $disk->shouldReceive('delete')->andReturnUsing(function (string $path) use (&$deleted) {
        $deleted[] = $path;
        RmqFile::query()->where('path', 'b.txt')->delete();

        return true;
    });
    Storage::set('local-mock', $disk);
    RmQ::stage(['a.txt', 'b.txt'], 'local-mock');

    expect(RmQ::deleteAll(after: 0)->deleted)->toBe(1)
        ->and($deleted)->toBe(['a.txt']);
});

it('works through large backlogs in bounded statements', function () {
    config(['rm-q.chunk' => 100]);
    Storage::fake('s3');
    $paths = array_map(fn (int $i) => "files/{$i}.txt", range(1, 1200));

    $maxBindings = 0;
    DB::listen(function ($query) use (&$maxBindings) {
        $maxBindings = max($maxBindings, count($query->bindings));
    });

    expect(RmQ::stage($paths, 's3'))->toBe(1200);

    $report = RmQ::deleteAll(after: 0);

    expect($report->deleted)->toBe(1200)
        ->and(RmqFile::query()->count())->toBe(0)
        ->and($maxBindings)->toBeLessThanOrEqual(1000);
});

it('dispatches one event per run', function () {
    Event::fake([StagedFilesProcessed::class]);
    RmQ::stage($this->temporaryFiles(2));

    RmQ::deleteAll(after: 0);

    Event::assertDispatchedTimes(StagedFilesProcessed::class, 1);
    Event::assertDispatched(StagedFilesProcessed::class, fn (StagedFilesProcessed $event) => $event->report->deleted === 2);
});
