<?php

namespace Medilies\RmQ\Events;

use Carbon\CarbonInterface;

final class FileDeletionFailed
{
    public function __construct(
        public readonly int $id,
        public readonly string $path,
        public readonly string $disk,
        public readonly string $error,
        public readonly int $attempts,
        /** False once the row used up `rm-q.max_attempts`. */
        public readonly bool $willRetry,
        /** When the next attempt may happen, in the app timezone; null once the row used up its attempts. */
        public readonly ?CarbonInterface $nextAttemptAt = null,
    ) {}
}
