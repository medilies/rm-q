<?php

use Illuminate\Contracts\Filesystem\Filesystem as FilesystemContract;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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
        ->and(Storage::path($file))->toBeFile();

    $this->travel(2)->seconds();
    $onTime = RmQ::deleteAll();

    expect($onTime->deleted)->toBe(1)
        ->and(Storage::path($file))->not->toBeFile()
        ->and(RmqFile::query()->count())->toBe(0);
});

it('keeps the grace period when app.timezone changes', function () {
    config(['rm-q.after' => 86400]);
    $this->freezeSecond();
    RmQ::stage($this->temporaryFile());

    date_default_timezone_set('Asia/Tokyo'); // e.g. app.timezone changed and the app redeployed
    $this->travel(15)->hours();

    expect(RmQ::countDeletable())->toBe(0)
        ->and(RmqFile::query()->sole()->staged_at->equalTo(now()->subHours(15)))->toBeTrue();

    $this->travel(9)->hours();

    expect(RmQ::countDeletable())->toBe(1);
});

it('keeps the grace period across the hour repeated when DST ends', function () {
    date_default_timezone_set('Europe/Paris');
    config(['rm-q.after' => 3600]);
    // 02:30 CET, the second 02:30 of 2026-10-25 in Paris.
    $this->travelTo(Date::parse('2026-10-25 01:30:00', 'UTC'));
    RmQ::stage($this->temporaryFile());

    expect(RmQ::countDeletable())->toBe(0);

    $this->travel(3599)->seconds();
    expect(RmQ::countDeletable())->toBe(0);

    $this->travel(1)->seconds();
    expect(RmQ::countDeletable())->toBe(1);
});

it('takes the grace period as an argument', function () {
    config(['rm-q.after' => 3600]);
    $file = $this->temporaryFile();
    RmQ::stage($file);

    expect(RmQ::deleteAll(after: 0)->deleted)->toBe(1)
        ->and(Storage::path($file))->not->toBeFile();
});

it('reads the grace period from a numeric string', function (string $after, int $staleness, bool $deleted) {
    config(['rm-q.after' => $after]);
    $this->freezeTime();
    $file = $this->temporaryFile();
    RmQ::stage($file);
    $this->travel($staleness)->seconds();

    expect(RmQ::deleteAll()->deleted)->toBe((int) $deleted);
})->with([
    'zero' => ['0', 0, true],
    'not over' => ['3600', 3599, false],
    'over' => ['3600', 3600, true],
]);

it('falls back to one day for an invalid grace period', function (mixed $after, string $rejected) {
    Log::spy();
    config(['rm-q.after' => $after]);
    $this->freezeTime();
    $file = $this->temporaryFile();
    RmQ::stage($file);

    $this->travel(86399)->seconds();
    expect(RmQ::countDeletable())->toBe(0);

    $this->travel(1)->seconds();
    expect(RmQ::deleteAll()->deleted)->toBe(1);

    Log::shouldHaveReceived('warning')->twice()->with(
        "rm-q.after must be a non-negative integer of seconds, got {$rejected}; using the default of 86400 (1 day).",
    );
})->with([
    'blank' => ['', '""'],
    'unit suffix' => ['24h', '"24h"'],
    'negative string' => ['-5', '"-5"'],
    'negative integer' => [-1, '-1'],
    'float' => [60.0, '60.0'],
]);

it('uses one day silently when the grace period is not set', function () {
    Log::spy();
    config(['rm-q.after' => null]);
    $this->freezeTime();
    RmQ::stage($this->temporaryFile());

    $this->travel(86399)->seconds();
    expect(RmQ::countDeletable())->toBe(0);

    $this->travel(1)->seconds();
    expect(RmQ::countDeletable())->toBe(1);

    Log::shouldNotHaveReceived('warning');
});

it('rejects invalid config and arguments', function (array $config, array $arguments, string $exception, string $message) {
    config($config);

    expect(fn () => RmQ::deleteAll(...$arguments))->toThrow($exception, $message);
})->with([
    'chunk config' => [['rm-q.chunk' => 0], [], RmqException::class, 'rm-q.chunk'],
    'chunk config not an integer' => [['rm-q.chunk' => '500 rows'], [], RmqException::class, 'rm-q.chunk'],
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

it('retries a failure once its backoff is over, until it runs out of attempts', function () {
    config(['rm-q.max_attempts' => 3, 'rm-q.backoff' => [900, 3600]]);
    $this->freezeSecond();
    brokenDisk('Access Denied');
    RmQ::stage('file.txt', 'broken');

    $run = fn () => [RmQ::deleteAll(after: 0)->failed, RmqFile::query()->sole()->attempts];

    expect($run())->toBe([1, 1])
        ->and($run())->toBe([0, 1]);

    $this->travel(899)->seconds();
    expect($run())->toBe([0, 1]);

    $this->travel(1)->seconds();
    expect($run())->toBe([1, 2]);

    $this->travel(3600)->seconds();
    $last = RmQ::deleteAll(after: 0);

    expect([$last->failed, $last->exhausted])->toBe([1, 1])
        ->and(RmqFile::query()->sole())
        ->attempts->toBe(3)
        ->next_attempt_at->toBeNull()
        ->error->toBe('Access Denied');

    $this->travel(1)->years();
    expect($run())->toBe([0, 3]);
});

it('repeats the last backoff delay', function () {
    config(['rm-q.max_attempts' => 4, 'rm-q.backoff' => [60]]);
    $this->freezeSecond();
    brokenDisk('Access Denied');
    RmQ::stage('file.txt', 'broken');

    RmQ::deleteAll(after: 0);

    foreach ([2, 3] as $attempts) {
        $this->travel(59)->seconds();
        expect(RmQ::deleteAll(after: 0)->processed())->toBe(0);

        $this->travel(1)->seconds();
        RmQ::deleteAll(after: 0);
        expect(RmqFile::query()->sole()->attempts)->toBe($attempts);
    }
});

it('leaves a file waiting for its backoff out of the count', function () {
    config(['rm-q.backoff' => [900]]);
    $this->freezeSecond();
    brokenDisk('Access Denied');
    RmQ::stage('file.txt', 'broken');
    RmQ::deleteAll(after: 0);

    expect(RmQ::countDeletable(0))->toBe(0);

    $this->travel(900)->seconds();
    expect(RmQ::countDeletable(0))->toBe(1);
});

it('rejects an invalid backoff', function (mixed $backoff) {
    config(['rm-q.backoff' => $backoff]);

    expect(fn () => RmQ::deleteAll())->toThrow(RmqException::class, 'rm-q.backoff');
})->with([
    'empty' => [[]],
    'not a list' => [900],
    'negative delay' => [[900, -1]],
    'not a number' => [['15m']],
    'keyed' => [['first' => 900]],
]);

it('accepts numeric strings in the backoff', function () {
    config(['rm-q.backoff' => ['900']]);
    $this->freezeSecond();
    brokenDisk('Access Denied');
    RmQ::stage('file.txt', 'broken');

    RmQ::deleteAll(after: 0);

    expect(RmqFile::query()->sole()->next_attempt_at->equalTo(now()->addSeconds(900)))->toBeTrue();
});

it('removes the row of a retried file once it succeeds', function () {
    config(['filesystems.disks.flaky' => ['driver' => 'local', 'root' => $this->temporaryDirectory()]]);
    $disk = Mockery::mock(FilesystemContract::class);
    $disk->shouldReceive('delete')->andReturn(false, true);
    Storage::set('flaky', $disk);
    RmQ::stage('file.txt', 'flaky');

    RmQ::deleteAll(after: 0);
    $this->travel(1)->days();
    $retry = RmQ::deleteAll(after: 0);

    expect($retry->deleted)->toBe(1)
        ->and(RmqFile::query()->count())->toBe(0);
});

it('counts the attempt and its backoff before deleting, so a run that dies still uses them up', function () {
    config(['filesystems.disks.slow' => ['driver' => 'local', 'root' => $this->temporaryDirectory()]]);
    $this->freezeSecond();
    $attemptsDuringDelete = null;
    $nextAttemptDuringDelete = null;
    $disk = Mockery::mock(FilesystemContract::class);
    $disk->shouldReceive('delete')->andReturnUsing(function () use (&$attemptsDuringDelete, &$nextAttemptDuringDelete) {
        $attemptsDuringDelete = RmqFile::query()->sole()->attempts;
        $nextAttemptDuringDelete = RmqFile::query()->sole()->next_attempt_at;

        return true;
    });
    Storage::set('slow', $disk);
    RmQ::stage('file.txt', 'slow');

    RmQ::deleteAll(after: 0);

    expect($attemptsDuringDelete)->toBe(1)
        ->and($nextAttemptDuringDelete?->equalTo(now()->addSeconds(900)))->toBeTrue();
});

it('leaves alone the files that used up their attempts', function () {
    config(['rm-q.max_attempts' => 3]);
    $file = $this->temporaryFile();
    stagedRow($file, ['attempts' => 3]); // e.g. three runs died while deleting it

    expect(RmQ::deleteAll(after: 0)->processed())->toBe(0)
        ->and(Storage::path($file))->toBeFile();
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
