<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Write;

use Jkudish\MailMirror\Enums\MailDriver;
use Jkudish\MailMirror\Enums\MailWriteOutcome;

/**
 * A provider write confirmed by re-reading provider state. The evidence is
 * the provider-native state observed by that confirming read.
 */
final readonly class MailWriteResult
{
    /** @param array<string, mixed> $providerEvidence */
    public function __construct(
        public int $mailAccountId,
        public MailDriver $driver,
        public string $providerMessageId,
        public MailWriteOutcome $outcome,
        public array $providerEvidence,
    ) {}
}
