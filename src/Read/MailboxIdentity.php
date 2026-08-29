<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Read;

use InvalidArgumentException;

final readonly class MailboxIdentity
{
    /** @param array<string, mixed> $providerMetadata */
    public function __construct(
        public string $providerIdentityId,
        public ?string $emailAddress = null,
        public ?string $displayName = null,
        public array $providerMetadata = [],
    ) {
        foreach ([$providerIdentityId, $emailAddress, $displayName] as $position => $value) {
            if (($position === 0 && trim((string) $value) === '')
                || ($value !== null && mb_strlen($value) > 255)) {
                throw new InvalidArgumentException('A mailbox identity requires bounded provider metadata.');
            }
        }
    }
}
