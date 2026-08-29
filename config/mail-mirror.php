<?php

declare(strict_types=1);

return [
    /*
     * Models use the application's default database connection unless a
     * consumer explicitly selects another configured connection.
     */
    'database_connection' => null,

    /*
     * Raw messages and materialized attachments are always written with
     * private visibility to this Laravel Filesystem disk.
     */
    'storage_disk' => env('MAIL_MIRROR_STORAGE_DISK', 'local'),

    /* Provider reads are sequential and retry only explicitly retryable failures. */
    'import_max_attempts' => 3,

    /* Reject unexpectedly large provider pages before retaining raw streams. */
    'inventory_page_max_messages' => 500,

    /* Reconciliation reports retain counts plus only this many opaque IDs per category. */
    'reconciliation_sample_limit' => 20,
];
