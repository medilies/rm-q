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
    'max_attempts' => 3,

    /*
     * Optional allowlist of absolute directories that files staged without a disk must live in.
     * Null means no restriction.
     */
    'allowed_directories' => null,
];
