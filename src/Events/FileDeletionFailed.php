<?php

namespace Medilies\RmQ\Events;

final class FileDeletionFailed
{
    public function __construct(
        public readonly int $id,
        public readonly string $path,
        public readonly ?string $disk,
        public readonly string $error,
        public readonly int $attempts,
        /** False once the row used up `rm-q.max_attempts`. */
        public readonly bool $willRetry,
    ) {}
}
