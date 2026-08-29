<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Read;

use InvalidArgumentException;
use Jkudish\MailMirror\Enums\MailDriver;

final readonly class MessageReference
{
    /** @param array<string, mixed> $providerMetadata */
    public function __construct(
        public int $mailAccountId,
        public MailDriver $driver,
        public string $providerMessageId,
        public ?string $providerThreadId = null,
        public array $providerMetadata = [],
    ) {
        if ($mailAccountId < 1 || trim($providerMessageId) === '' || mb_strlen($providerMessageId) > 255) {
            throw new InvalidArgumentException('A message reference requires an account and opaque provider message ID.');
        }

        if ($providerThreadId !== null && (trim($providerThreadId) === '' || mb_strlen($providerThreadId) > 255)) {
            throw new InvalidArgumentException('A provider thread ID must be null or a bounded non-empty value.');
        }
    }
}
