<?php

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Medilies\RmQ\Events\StagedFilesProcessed;
use Medilies\RmQ\Exceptions\RmqException;
use Medilies\RmQ\Facades\RmQ;
use Medilies\RmQ\Models\RmqFile;

it('deletes nothing while paused', function () {
    Log::spy();
    Event::fake([StagedFilesProcessed::class]);
    config(['rm-q.paused' => true]);
    $file = $this->temporaryFile();
    RmQ::stage($file);

    $report = RmQ::deleteAll(after: 0);

    expect($report->processed())->toBe(0)
        ->and(RmqFile::query()->sole()->attempts)->toBe(0);

    Storage::assertExists($file);
    Log::shouldHaveReceived('warning')->once()->with('rm-q is paused (rm-q.paused): no staged file was deleted.');
    Event::assertNotDispatched(StagedFilesProcessed::class);
});

it('deletes again once resumed', function () {
    config(['rm-q.paused' => true]);
    $file = $this->temporaryFile();
    RmQ::stage($file);
    RmQ::deleteAll(after: 0);

    config(['rm-q.paused' => false]);

    expect(RmQ::deleteAll(after: 0)->deleted)->toBe(1);
});

it('reads the setting from .env values', function (mixed $value, bool $paused) {
    config(['rm-q.paused' => $value]);

    expect(RmQ::paused())->toBe($paused);
})->with([
    'unset' => [null, false],
    'false' => [false, false],
    'true' => [true, true],
    'string true' => ['true', true],
    'string false' => ['false', false],
    'one' => ['1', true],
    'zero' => ['0', false],
    'blank' => ['', false],
]);

it('refuses to run with an invalid setting', function (mixed $value) {
    config(['rm-q.paused' => $value]);
    RmQ::stage($this->temporaryFile());

    expect(fn () => RmQ::deleteAll(after: 0))->toThrow(RmqException::class, 'rm-q.paused')
        ->and(RmqFile::query()->count())->toBe(1);
})->with(['yes', 'on', 2, [[true]]]);

it('warns and succeeds when rm-q:delete runs while paused', function () {
    config(['rm-q.paused' => true]);
    RmQ::stage($this->temporaryFile());

    $this->artisan('rm-q:delete', ['--after' => '0'])
        ->expectsOutputToContain('rm-q is paused')
        ->doesntExpectOutputToContain('Processed')
        ->assertSuccessful();

    expect(RmqFile::query()->count())->toBe(1);
});

it('reports nothing to process on a dry run while paused, with the due count', function (array $options) {
    config(['rm-q.paused' => true]);
    RmQ::stage($this->temporaryFile());

    $this->artisan('rm-q:delete', ['--after' => '0', '--dry-run' => true, ...$options])
        ->expectsOutputToContain('Would process 0 staged file(s) (1 due, rm-q is paused).')
        ->assertSuccessful();
})->with([
    'no limit' => [[]],
    'with a limit' => [['--limit' => '1']],
]);

it('shows the pause in the stats', function () {
    config(['rm-q.paused' => true]);

    expect(Artisan::call('rm-q:stats', ['--json' => true]))->toBe(Command::SUCCESS)
        ->and(json_decode(Artisan::output(), true)['paused'])->toBeTrue();
});
