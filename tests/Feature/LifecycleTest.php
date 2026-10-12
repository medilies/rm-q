<?php

use Medilies\RmQ\Enums\Status;
use Medilies\RmQ\Facades\RmQ;
use Medilies\RmQ\Models\RmqFile;
use Medilies\RmQ\RmQ as RmQService;
use TestApp\Jobs\RecordRmqInstance;

it('keeps one instance per lifecycle', function () {
    $first = RmQ::instanceId();

    expect(app(RmQService::class)->instanceId())->toBe($first);

    $this->newLifecycle();

    expect(RmQ::instanceId())->not->toBe($first)
        ->and(app(RmQService::class)->instanceId())->toBe(RmQ::instanceId());
});

it('gives each queued job its own instance', function () {
    config(['queue.default' => 'database']);
    RecordRmqInstance::$seen = [];

    dispatch(new RecordRmqInstance);
    dispatch(new RecordRmqInstance);

    $this->artisan('queue:work', [
        '--stop-when-empty' => true,
        '--sleep' => 0,
        '--timeout' => 0,
        '--memory' => 1024,
    ])->assertSuccessful();

    expect(RecordRmqInstance::$seen)->toHaveCount(2)
        ->and(RecordRmqInstance::$seen[0]['facade'])->toBe(RecordRmqInstance::$seen[0]['container'])
        ->and(RecordRmqInstance::$seen[1]['facade'])->toBe(RecordRmqInstance::$seen[1]['container'])
        ->and(RecordRmqInstance::$seen[0]['facade'])->not->toBe(RecordRmqInstance::$seen[1]['facade']);
});

it('processes each file once when requests reuse the container', function () {
    [$first, $second] = $this->temporaryFiles(2);

    // No reset between the requests: the worst case of a long-lived worker.
    $this->post('/stage', ['files' => [$first]])->assertOk();
    $this->post('/stage', ['files' => [$second]])->assertOk();

    expect($first)->not->toBeFile()
        ->and($second)->not->toBeFile()
        ->and(RmqFile::query()->get())
        ->toHaveCount(2)
        ->each(fn ($row) => $row->status->toBe(Status::Deleted)->attempts->toBe(1));
});
