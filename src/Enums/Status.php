<?php

namespace Medilies\RmQ\Enums;

enum Status: int
{
    /** Waiting for a sweep. */
    case Staged = 0;

    /** The file is gone: deleted by a sweep, or already missing. */
    case Deleted = 1;

    /** The last attempt failed; retried while `attempts < rm-q.max_attempts`. */
    case Failed = 2;

    /** Claimed by a sweep; reclaimed after `rm-q.claim_timeout` if that sweep died. */
    case Processing = 3;

    public function label(): string
    {
        return $this->name;
    }
}
