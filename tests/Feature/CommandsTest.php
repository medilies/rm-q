<?php

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Medilies\RmQ\Facades\RmQ;
use Medilies\RmQ\Models\RmqFile;

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

    it('takes the grace period and a limit as options', function () {
        RmQ::stage($this->temporaryFiles(3));

        $this->artisan('rm-q:delete', ['--after' => '0', '--limit' => '2'])->assertSuccessful();

        expect(RmqFile::query()->count())->toBe(1);
    });

    it('only counts on a dry run', function () {
        $file = $this->temporaryFile();
        stagedRow($file, ['staged_at' => now()->subDays(2)]);
        stagedRow('/tmp/failed.txt', ['staged_at' => now()->subDays(2), 'attempts' => 1, 'error' => 'Timeout']);
        stagedRow('/tmp/recent.txt');

        $this->artisan('rm-q:delete', ['--dry-run' => true])
            ->expectsOutputToContain('Would process 2 staged file(s).')
            ->assertSuccessful();

        expect($file)->toBeFile()
            ->and(RmqFile::query()->sum('attempts'))->toEqual(1);
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

describe('rm-q:stats', function () {
    beforeEach(function () {
        $this->freezeTime();
        config(['rm-q.max_attempts' => 3]);
        stagedRow('/tmp/due.txt', ['staged_at' => now()->subDays(2)]);
        stagedRow('/tmp/retrying.txt', ['staged_at' => now()->subDays(3), 'attempts' => 1, 'error' => 'Timeout']);
        stagedRow('/tmp/gave-up.txt', ['staged_at' => now()->subDays(4), 'attempts' => 3, 'error' => 'Access Denied']);
        stagedRow('/tmp/recent.txt');
    });

    it('shows the state of the table', function () {
        $this->artisan('rm-q:stats')
            ->expectsOutputToContain('Pending')
            ->expectsOutputToContain('Failed, gave up')
            ->expectsOutputToContain('Oldest staged file')
            ->assertSuccessful();
    });

    it('outputs JSON', function () {
        expect(Artisan::call('rm-q:stats', ['--json' => true]))->toBe(Command::SUCCESS)
            ->and(json_decode(Artisan::output(), true))->toBe([
                'pending' => 3,
                'deletable_now' => 2,
                'retrying' => 1,
                'gave_up' => 1,
                'oldest_staged_at' => now()->subDays(3)->toIso8601String(),
            ]);
    });
});

describe('rm-q:retry', function () {
    it('gives the failures that gave up a new round of attempts', function () {
        config(['rm-q.max_attempts' => 3]);
        $gaveUp = stagedRow('/tmp/gave-up.txt', ['attempts' => 3, 'error' => 'Access Denied']);
        $retrying = stagedRow('/tmp/retrying.txt', ['attempts' => 1, 'error' => 'Timeout']);

        $this->artisan('rm-q:retry')
            ->expectsOutputToContain('1 failed deletion(s) will be retried')
            ->assertSuccessful();

        expect($gaveUp->fresh())
            ->attempts->toBe(0)
            ->error->toBeNull()
            ->and($retrying->fresh())
            ->attempts->toBe(1)
            ->error->toBe('Timeout');
    });
});
