<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Write;

use InvalidArgumentException;
use Jkudish\MailMirror\Enums\MailDriver;

/**
 * Provider Trash membership read directly from the provider, with the
 * provider-native evidence a driver needs to write and a caller needs to audit.
 *
 * $restored is the driver's restore-destination predicate. Gmail untrash
 * restores prior labels, so any state outside Trash is restored. JMAP restores
 * to the Inbox role, so the email must be in Inbox and not in Trash.
 */
final readonly class TrashState
{
    /** @param array<string, mixed> $providerEvidence */
    public function __construct(
        public int $mailAccountId,
        public MailDriver $driver,
        public string $providerMessageId,
        public bool $inTrash,
        public bool $restored,
        public array $providerEvidence,
    ) {
        if ($mailAccountId < 1 || trim($providerMessageId) === '' || mb_strlen($providerMessageId) > 255) {
            throw new InvalidArgumentException('Trash state requires an account and a bounded opaque provider message ID.');
        }

        if ($inTrash && $restored) {
            throw new InvalidArgumentException('A message in Trash cannot be restored.');
        }
    }
}
