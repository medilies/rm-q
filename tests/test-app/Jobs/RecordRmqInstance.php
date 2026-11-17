<?php

namespace TestApp\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Medilies\RmQ\Facades\RmQ;

/** Records which RmQ instance a queued job sees, through the facade and through the container. */
class RecordRmqInstance implements ShouldQueue
{
    use Queueable;

    /** @var list<array{facade: string, container: string}> */
    public static array $seen = [];

    public function handle(): void
    {
        self::$seen[] = [
            'facade' => RmQ::instanceId(),
            'container' => app(\Medilies\RmQ\RmQ::class)->instanceId(),
        ];
    }
}
