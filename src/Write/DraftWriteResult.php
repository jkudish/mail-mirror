<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Write;

use Jkudish\MailMirror\Enums\MailDriver;
use Jkudish\MailMirror\Enums\MailWriteOutcome;

/**
 * A draft write confirmed by re-reading the provider. $draft is the draft the
 * confirming read found, or null after a delete.
 */
final readonly class DraftWriteResult
{
    public function __construct(
        public int $mailAccountId,
        public MailDriver $driver,
        public MailWriteOutcome $outcome,
        public ?DraftRevision $draft,
    ) {}
}
