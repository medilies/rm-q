<?php

use Illuminate\Contracts\Filesystem\Filesystem as FilesystemContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Medilies\RmQ\DeletionReport;
use Medilies\RmQ\Enums\Status;
use Medilies\RmQ\Events\StagedFilesProcessed;
use Medilies\RmQ\Exceptions\RmqException;
use Medilies\RmQ\Facades\RmQ;
use Medilies\RmQ\Models\RmqFile;
use Symfony\Component\Uid\Ulid;

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
        ->and(RmqFile::query()->sole()->status)->toBe(Status::Deleted);
});

it('deletes rows staged by other processes', function () {
    // The command runs in a fresh process: nothing was staged by its own instance.
    $file = $this->temporaryFile();
    stagedRow($file, ['staged_at' => now()->subDays(2)]);

    $report = RmQ::deleteAll();

    expect($report->deleted)->toBe(1)
        ->and($file)->not->toBeFile();
});

it('takes the grace period as an argument', function () {
    config(['rm-q.after' => 3600]);
    $file = $this->temporaryFile();
    RmQ::stage($file);

    expect(RmQ::deleteAll(after: 0)->deleted)->toBe(1)
        ->and($file)->not->toBeFile();
});

it('accepts the grace period config as a numeric string, as env() returns it', function () {
    config(['rm-q.after' => '0']);
    $file = $this->temporaryFile();
    RmQ::stage($file);

    expect(RmQ::deleteAll()->deleted)->toBe(1)
        ->and($file)->not->toBeFile();
});

it('rejects invalid config and arguments', function (array $config, array $arguments, string $message) {
    config($config);

    expect(fn () => RmQ::deleteAll(...$arguments))->toThrow(RmqException::class, $message);
})->with([
    'after config' => [['rm-q.after' => 'one day'], [], 'rm-q.after'],
    'negative after config' => [['rm-q.after' => -1], [], 'rm-q.after'],
    'chunk config' => [['rm-q.chunk' => 0], [], 'rm-q.chunk'],
    'negative after' => [[], ['after' => -1], '[after]'],
    'limit' => [[], ['limit' => 0], '[limit]'],
]);

it('refuses to run inside a transaction', function () {
    expect(fn () => RmQ::transaction(fn () => RmQ::deleteAll()))
        ->toThrow(RmqException::class, 'cannot run inside a database transaction');
});

it('stops at the limit', function () {
    RmQ::stage($this->temporaryFiles(5));

    $report = RmQ::deleteAll(after: 0, limit: 2);

    expect($report->processed())->toBe(2)
        ->and(RmqFile::query()->where('status', Status::Staged)->count())->toBe(3);
});

it('retries failures until they run out of attempts', function () {
    config(['rm-q.max_attempts' => 3]);
    brokenDisk('Access Denied');
    RmQ::stage('file.txt', 'broken');

    $runs = [];

    for ($i = 0; $i < 4; $i++) {
        $this->travel(1)->seconds(); // Failures are retried at most once per run.
        $runs[] = RmQ::deleteAll(after: 0);
    }

    expect(array_map(fn (DeletionReport $report) => [$report->failed, $report->exhausted], $runs))
        ->toBe([[1, 0], [1, 0], [1, 1], [0, 0]])
        ->and(RmqFile::query()->sole())
        ->status->toBe(Status::Failed)
        ->attempts->toBe(3);
});

it('marks a retried file deleted once it succeeds', function () {
    config(['filesystems.disks.flaky' => ['driver' => 'local', 'root' => $this->temporaryDirectory()]]);
    $disk = Mockery::mock(FilesystemContract::class);
    $disk->shouldReceive('delete')->andReturn(false, true);
    Storage::set('flaky', $disk);
    RmQ::stage('file.txt', 'flaky');

    RmQ::deleteAll(after: 0);
    $this->travel(1)->seconds();
    $retry = RmQ::deleteAll(after: 0);

    expect($retry->deleted)->toBe(1)
        ->and(RmqFile::query()->sole())
        ->status->toBe(Status::Deleted)
        ->attempts->toBe(2)
        ->error->toBeNull();
});

it('skips rows claimed by another sweep until the claim times out', function () {
    config(['rm-q.claim_timeout' => 600]);
    $file = $this->temporaryFile();
    $foreignToken = (string) new Ulid;
    stagedRow($file, [
        'status' => Status::Processing->value,
        'claimed_by' => $foreignToken,
        'claimed_at' => now(),
        'attempts' => 1,
    ]);

    RmQ::deleteAll(after: 0);

    expect($file)->toBeFile()
        ->and(RmqFile::query()->sole())
        ->status->toBe(Status::Processing)
        ->claimed_by->toBe($foreignToken);

    $this->travel(601)->seconds();
    RmQ::deleteAll(after: 0);

    expect($file)->not->toBeFile()
        ->and(RmqFile::query()->sole())
        ->status->toBe(Status::Deleted)
        ->attempts->toBe(2);
});

it('never overwrites a row another sweep took over', function () {
    config(['filesystems.disks.slow' => ['driver' => 'local', 'root' => $this->temporaryDirectory()]]);
    $takeover = (string) new Ulid;
    $disk = Mockery::mock(FilesystemContract::class);
    $disk->shouldReceive('delete')->andReturnUsing(function () use ($takeover) {
        // Another sweep reclaims the row while this one is deleting the file.
        RmqFile::query()->update(['claimed_by' => $takeover]);

        return true;
    });
    Storage::set('slow', $disk);
    RmQ::stage('file.txt', 'slow');

    RmQ::deleteAll(after: 0);

    expect(RmqFile::query()->sole())
        ->status->toBe(Status::Processing)
        ->claimed_by->toBe($takeover);
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
        ->and(RmqFile::query()->where('status', Status::Deleted)->count())->toBe(1200)
        ->and($maxBindings)->toBeLessThanOrEqual(1000);
});

it('dispatches one event per run', function () {
    Event::fake([StagedFilesProcessed::class]);
    RmQ::stage($this->temporaryFiles(2));

    RmQ::deleteAll(after: 0);

    Event::assertDispatchedTimes(StagedFilesProcessed::class, 1);
    Event::assertDispatched(StagedFilesProcessed::class, fn (StagedFilesProcessed $event) => $event->report->deleted === 2);
});
