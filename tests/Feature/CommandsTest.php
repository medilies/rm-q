<?php

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Medilies\RmQ\Enums\Status;
use Medilies\RmQ\Facades\RmQ;
use Medilies\RmQ\Models\RmqFile;
use Symfony\Component\Uid\Ulid;

describe('rm-q:delete', function () {
    it('deletes staged files past the grace period', function () {
        [$old, $recent] = $this->temporaryFiles(2);
        stagedRow($old, ['staged_at' => now()->subDays(2)]);
        stagedRow($recent);

        $this->artisan('rm-q:delete')
            ->expectsTable(['Result', 'Count'], [['Deleted', 1], ['Failed, will retry', 0], ['Failed, gave up', 0]])
            ->expectsOutputToContain('Processed 1 staged file(s)')
            ->assertSuccessful();

        expect($old)->not->toBeFile()
            ->and($recent)->toBeFile();
    });

    it('deletes files staged by a previous lifecycle', function () {
        config(['rm-q.after' => 0]);
        $file = $this->temporaryFile();
        RmQ::stage($file);
        $this->newLifecycle();

        $this->artisan('rm-q:delete')->assertSuccessful();

        expect($file)->not->toBeFile();
    });

    it('takes the grace period and a limit as options', function () {
        RmQ::stage($this->temporaryFiles(3));

        $this->artisan('rm-q:delete', ['--after' => '0', '--limit' => '2'])->assertSuccessful();

        expect(RmqFile::query()->where('status', Status::Deleted)->count())->toBe(2);
    });

    it('only counts on a dry run', function () {
        $file = $this->temporaryFile();
        stagedRow($file, ['staged_at' => now()->subDays(2)]);
        stagedRow('/tmp/abandoned.txt', [
            'status' => Status::Processing->value,
            'claimed_by' => (string) new Ulid,
            'claimed_at' => now()->subDay(),
            'attempts' => 1,
        ]);
        stagedRow('/tmp/failed.txt', ['status' => Status::Failed->value, 'attempts' => 1, 'processed_at' => now()->subMinute()]);

        $this->artisan('rm-q:delete', ['--dry-run' => true])
            ->expectsTable(['Would process', 'Count'], [
                ['Staged, past the grace period', 1],
                ['Abandoned by a crashed run', 1],
                ['Failed, to retry', 1],
            ])
            ->assertSuccessful();

        expect($file)->toBeFile()
            ->and(RmqFile::query()->where('status', Status::Staged)->count())->toBe(1);
    });

    it('rejects invalid options', function (array $options, string $message) {
        $this->artisan('rm-q:delete', $options)
            ->expectsOutputToContain($message)
            ->assertExitCode(Command::INVALID);
    })->with([
        'negative grace period' => [['--after' => '-1'], 'The --after option must be an integer greater than or equal to 0.'],
        'not a number' => [['--after' => 'soon'], 'The --after option must be an integer'],
        'zero limit' => [['--limit' => '0'], 'The --limit option must be an integer greater than or equal to 1.'],
    ]);

    it('fails when a file used up its attempts', function () {
        config(['rm-q.max_attempts' => 1]);
        brokenDisk('Access Denied');
        RmQ::stage('file.txt', 'broken');

        $this->artisan('rm-q:delete', ['--after' => '0'])
            ->expectsTable(['Result', 'Count'], [['Deleted', 0], ['Failed, will retry', 0], ['Failed, gave up', 1]])
            ->expectsOutputToContain('Run rm-q:retry')
            ->assertFailed();
    });

    it('can run in isolation', function () {
        $this->artisan('rm-q:delete', ['--isolated' => true])->assertSuccessful();
    });
});

describe('rm-q:cleanup', function () {
    it('removes deleted rows past the retention period', function () {
        config(['rm-q.cleanup_after_days' => 30]);
        stagedRow('/tmp/old.txt', ['status' => Status::Deleted->value, 'processed_at' => now()->subDays(31)]);
        stagedRow('/tmp/recent.txt', ['status' => Status::Deleted->value, 'processed_at' => now()->subDays(29)]);
        stagedRow('/tmp/staged.txt', ['staged_at' => now()->subDays(60)]);
        stagedRow('/tmp/failed.txt', ['status' => Status::Failed->value, 'attempts' => 3, 'processed_at' => now()->subDays(60)]);

        $this->artisan('rm-q:cleanup')
            ->expectsOutputToContain('Removed 1 row(s).')
            ->assertSuccessful();

        expect(RmqFile::query()->pluck('path')->sort()->values()->all())
            ->toBe(['/tmp/failed.txt', '/tmp/recent.txt', '/tmp/staged.txt']);
    });

    it('also removes the failures that gave up, on request', function () {
        config(['rm-q.max_attempts' => 3]);
        stagedRow('/tmp/gave-up.txt', ['status' => Status::Failed->value, 'attempts' => 3, 'processed_at' => now()->subDay()]);
        stagedRow('/tmp/retrying.txt', ['status' => Status::Failed->value, 'attempts' => 1, 'processed_at' => now()->subDay()]);

        $this->artisan('rm-q:cleanup', ['--with-failed' => true, '--days' => '0'])
            ->expectsOutputToContain('Removed 1 row(s).')
            ->assertSuccessful();

        expect(RmqFile::query()->pluck('path')->all())->toBe(['/tmp/retrying.txt']);
    });

    it('removes large backlogs in chunks', function () {
        $processedAt = now()->subDays(60);

        foreach (array_chunk(range(1, 2500), 100) as $chunk) {
            RmqFile::query()->insert(array_map(fn (int $i) => [
                'path' => "/tmp/{$i}.txt",
                'instance' => (string) new Ulid,
                'status' => Status::Deleted->value,
                'attempts' => 1,
                'staged_at' => $processedAt,
                'processed_at' => $processedAt,
            ], $chunk));
        }

        $this->artisan('rm-q:cleanup')
            ->expectsOutputToContain('Removed 2500 row(s).')
            ->assertSuccessful();

        expect(RmqFile::query()->count())->toBe(0);
    });

    it('rejects an invalid retention', function () {
        $this->artisan('rm-q:cleanup', ['--days' => '-3'])
            ->expectsOutputToContain('The --days option must be an integer greater than or equal to 0.')
            ->assertExitCode(Command::INVALID);
    });
});

describe('rm-q:stats', function () {
    it('counts rows per status, including unknown ones', function () {
        stagedRow('/tmp/a.txt');
        stagedRow('/tmp/b.txt', ['status' => Status::Deleted->value]);
        stagedRow('/tmp/c.txt', ['status' => 99]);

        $this->artisan('rm-q:stats')
            ->expectsTable(['Status', 'Count'], [
                ['Staged', 1],
                ['Deleted', 1],
                ['Unknown (99)', 1],
                ['Total', 3],
            ])
            ->expectsOutputToContain('Oldest staged file')
            ->assertSuccessful();
    });

    it('outputs JSON', function () {
        $this->freezeTime();
        config(['rm-q.max_attempts' => 3]);
        stagedRow('/tmp/a.txt', ['staged_at' => now()->subDays(2)]);
        stagedRow('/tmp/b.txt', ['status' => Status::Failed->value, 'attempts' => 3, 'processed_at' => now()]);

        expect(Artisan::call('rm-q:stats', ['--json' => true]))->toBe(Command::SUCCESS)
            ->and(json_decode(Artisan::output(), true))->toBe([
                'statuses' => ['Staged' => 1, 'Failed' => 1],
                'total' => 2,
                'oldest_staged_at' => now()->subDays(2)->toIso8601String(),
                'deletable_now' => ['abandoned' => 0, 'staged' => 1, 'retries' => 0],
                'failed_gave_up' => 1,
            ]);
    });
});

describe('rm-q:retry', function () {
    it('gives the failures that gave up a new round of attempts', function () {
        config(['rm-q.max_attempts' => 3]);
        $gaveUp = stagedRow('/tmp/gave-up.txt', ['status' => Status::Failed->value, 'attempts' => 3, 'error' => 'Access Denied']);
        $retrying = stagedRow('/tmp/retrying.txt', ['status' => Status::Failed->value, 'attempts' => 1, 'error' => 'Timeout']);

        $this->artisan('rm-q:retry')
            ->expectsOutputToContain('1 failed deletion(s) will be retried')
            ->assertSuccessful();

        expect($gaveUp->fresh())
            ->status->toBe(Status::Staged)
            ->attempts->toBe(0)
            ->error->toBeNull()
            ->and($retrying->fresh())
            ->status->toBe(Status::Failed)
            ->attempts->toBe(1);
    });
});
