<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Exceptions;

use RuntimeException;

final class SyncBudgetExhausted extends RuntimeException
{
    public const SAFE_CODE = 'budget_exhausted';

    /**
     * @param  array{
     *     http_requests: int,
     *     max_http_requests: int,
     *     listed_ids: int,
     *     max_listed_ids: int,
     *     fetched_messages: int,
     *     max_fetched_messages: int,
     *     downloaded_bytes: int,
     *     max_downloaded_bytes: int,
     *     elapsed_milliseconds: int,
     *     max_elapsed_milliseconds: int
     * }  $snapshot
     */
    public function __construct(
        public readonly string $dimension,
        public readonly array $snapshot,
    ) {
        parent::__construct('The configured mail sync work budget was exhausted; durable checkpoints remain available.');
    }
}
