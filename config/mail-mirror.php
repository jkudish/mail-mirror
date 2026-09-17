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

    /* A rejected opaque provider cursor may restart one fresh scan, never loop indefinitely. */
    'import_max_scan_restarts' => 1,

    /* Reject unexpectedly large provider pages before retaining raw streams. */
    'inventory_page_max_messages' => 500,

    /* Reconciliation reports retain counts plus only this many opaque IDs per category. */
    'reconciliation_sample_limit' => 20,

    'gmail' => [
        /* Fail closed unless provider network access is explicitly enabled by a consumer. */
        'enabled' => env('MAIL_MIRROR_GMAIL_ENABLED', false),
        'client_id' => env('MAIL_MIRROR_GMAIL_CLIENT_ID'),
        'redirect_uri' => env('MAIL_MIRROR_GMAIL_REDIRECT_URI'),
        'page_size' => 100,
        'timeout_seconds' => 30,
        'max_raw_bytes' => 52428800,

        'pubsub' => [
            /* Outbound pull is a separate, fail-closed integration from mailbox OAuth. */
            'enabled' => false,
            'project_id' => null,
            'topic_id' => null,
            'subscription_id' => null,
            /* Name only: the short-lived token itself is never copied into cached config. */
            'access_token_environment' => 'MAIL_MIRROR_GMAIL_PUBSUB_ACCESS_TOKEN',
            'max_messages' => 20,
            /* Pull may wait on an empty subscription; matches Google's PHP client deadline. */
            'pull_timeout_seconds' => 60,
            /* Acknowledgements do not long-poll. */
            'timeout_seconds' => 10,
        ],
    ],

    'jmap' => [
        /* Fail closed unless Fastmail JMAP network access is explicitly enabled by a consumer. */
        'enabled' => filter_var(env('MAIL_MIRROR_JMAP_ENABLED', false), FILTER_VALIDATE_BOOL),
        'page_size' => 100,
        'timeout_seconds' => 30,
        'request_max_attempts' => 3,
        'max_raw_bytes' => 52428800,

        'event_source' => [
            /* The host owns repeated invocation and process supervision. */
            'enabled' => false,
            'timeout_seconds' => 35,
            'ping_seconds' => 30,
            'max_stream_bytes' => 262144,
            'max_event_bytes' => 32768,
            'max_events' => 20,
        ],
    ],
];
