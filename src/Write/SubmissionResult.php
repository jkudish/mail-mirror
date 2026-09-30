<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Write;

use Jkudish\MailMirror\Enums\MailDriver;
use Jkudish\MailMirror\Enums\SubmissionOutcome;

/**
 * A submission outcome read from the provider. $matchedBy names the evidence:
 * "provider_id" when the provider linked the sent message or submission to
 * the draft's own ID, "message_id" when only the Message-ID header matched,
 * or null when nothing matched.
 */
final readonly class SubmissionResult
{
    /** @param array<string, mixed> $providerEvidence */
    public function __construct(
        public int $mailAccountId,
        public MailDriver $driver,
        public SubmissionOutcome $outcome,
        public ?string $providerMessageId,
        public ?string $threadId,
        public ?string $matchedBy,
        public array $providerEvidence,
    ) {}
}
