<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Write;

use InvalidArgumentException;
use Jkudish\MailMirror\Enums\MailDriver;

/**
 * One message's mailbox state read directly from the provider for one
 * MailboxChange, with the provider-native evidence a driver needs to write
 * and a caller needs to audit.
 *
 * $desiredStateHolds is the driver's destination predicate for that change,
 * for example "no UNREAD label" for a Gmail mark-read, or "in the Inbox-role
 * mailbox and not in the Trash-role mailbox" for a JMAP untrash.
 */
final readonly class MessageState
{
    /** @param array<string, mixed> $providerEvidence */
    public function __construct(
        public int $mailAccountId,
        public MailDriver $driver,
        public string $providerMessageId,
        public bool $desiredStateHolds,
        public array $providerEvidence,
    ) {
        if ($mailAccountId < 1 || trim($providerMessageId) === '' || mb_strlen($providerMessageId) > 255) {
            throw new InvalidArgumentException('Message state requires an account and a bounded opaque provider message ID.');
        }
    }

    /**
     * Versioned SHA-256 of account, driver, exact opaque message ID and all
     * provider evidence. Evidence maps and set lists are unordered; scalar
     * types and bytes are preserved. The derived destination predicate is not
     * provider state, so an action and its inverse can compare the same read.
     */
    public function fingerprint(): string
    {
        return hash('sha256', serialize([
            'mail-mirror-message-state-v1',
            $this->mailAccountId,
            $this->driver->value,
            $this->providerMessageId,
            self::canonicalEvidence($this->providerEvidence),
        ]));
    }

    private static function canonicalEvidence(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $list = array_is_list($value);
        $value = array_map(self::canonicalEvidence(...), $value);

        if ($list) {
            usort($value, fn (mixed $a, mixed $b): int => strcmp(serialize($a), serialize($b)));
        } else {
            ksort($value, SORT_STRING);
        }

        return $value;
    }
}
