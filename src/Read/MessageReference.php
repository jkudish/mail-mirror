<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Read;

use Jkudish\MailMirror\Enums\MailDriver;

final readonly class MessageReference
{
    /** @param array<string, mixed> $providerMetadata */
    public function __construct(
        public int $mailAccountId,
        public MailDriver $driver,
        public string $providerMessageId,
        public string $providerOccurrenceId,
        public ?string $providerThreadId = null,
        public array $providerMetadata = [],
    ) {}
}
