<?php

namespace Medilies\RmQ;

/** What one RmQ::deleteAll() run did. */
final class DeletionReport
{
    public function __construct(
        /** Files deleted, or already missing. */
        public readonly int $deleted,
        /** Files that could not be deleted. */
        public readonly int $failed,
        /** Failures that used up their last attempt: they won't be retried automatically. */
        public readonly int $exhausted,
    ) {}

    public function processed(): int
    {
        return $this->deleted + $this->failed;
    }
}
