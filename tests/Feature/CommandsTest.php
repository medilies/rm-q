<?php

use Medilies\RmQ\Enums\Status;
use Medilies\RmQ\Facades\RmQ;
use Medilies\RmQ\Models\RmqFile;

it('deletes staged files past the grace period', function () {
    [$old, $recent] = $this->temporaryFiles(2);
    stagedRow($old, ['staged_at' => now()->subDays(2)]);
    stagedRow($recent);

    $this->artisan('rm-q:delete')
        ->expectsOutputToContain('Deleted 1 staged file(s), 0 failed.')
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

it('cleans up deleted rows only', function () {
    $file = $this->temporaryFile();
    stagedRow('/tmp/still-staged.txt');
    RmQ::stage($file);
    RmQ::delete();

    $this->artisan('rm-q:cleanup')->assertSuccessful();

    expect(RmqFile::query()->pluck('status')->all())->toBe([Status::Staged]);
});

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
        ->assertSuccessful();
});
