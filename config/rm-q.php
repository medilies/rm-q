<?php

return [
    /*
     * The connection holding the rm-q table. It MUST be the connection your business transactions run on,
     * otherwise staging won't roll back with them. Null uses the default connection.
     */
    'connection' => env('RM_Q_CONNECTION'),

    'table' => 'rm_q_files',

    /*
     * Grace period, in seconds, before `rm-q:delete` / RmQ::deleteAll() deletes a staged file: time to notice a bug
     * or restore a database backup before the files are gone. Default: 1 day, also used, with a logged warning, when
     * the value is not a non-negative integer.
     */
    'after' => env('RM_Q_AFTER'),

    /* Rows read and processed per batch. */
    'chunk' => 500,

    /* A failed deletion is retried by `rm-q:delete` until it reaches this many attempts. */
    'max_attempts' => 5,

    /*
     * Seconds to wait before attempting a failed deletion again: the first delay follows the first failure, and the
     * last one repeats. The default retries after 15 minutes, 1 hour, 6 hours and 1 day, so with 5 attempts a file
     * gives up about 31 hours after its first failure: a short storage outage doesn't use up its attempts.
     */
    'backoff' => [900, 3600, 21600, 86400],
];
