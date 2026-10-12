<?php

return [
    /*
     * The connection holding the rm-q table. It MUST be the connection your business transactions run on,
     * otherwise staging won't roll back with them. Null uses the default connection.
     */
    'connection' => env('RM_Q_CONNECTION'),

    'table' => 'rm_q_files',

    /*
     * Grace period, in seconds, before `rm-q:delete` / RmQ::deleteAll() touches a staged file.
     * Keep it longer than your longest request or job. Default: 1 day.
     */
    'after' => env('RM_Q_AFTER', 60 * 60 * 24),

    /* Rows claimed and processed per batch. */
    'chunk' => 500,

    /* A failed deletion is retried by `rm-q:delete` until it reaches this many attempts. */
    'max_attempts' => 3,

    /* Seconds after which a row claimed by a sweep that died is reclaimed. */
    'claim_timeout' => 60 * 60,

    /* How long `rm-q:cleanup` keeps processed rows, in days. */
    'cleanup_after_days' => 30,

    /*
     * Optional allowlist of absolute directories that files staged without a disk must live in.
     * Null means no restriction.
     */
    'allowed_directories' => null,
];
