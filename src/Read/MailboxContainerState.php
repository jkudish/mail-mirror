<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Read;

use InvalidArgumentException;

final readonly class MailboxContainerState
{
    /** @param array<string, mixed> $providerMetadata */
    public function __construct(
        public int $mailAccountId,
        public string $providerContainerId,
        public string $name,
        public ?string $kind = null,
        public array $providerMetadata = [],
    ) {
        if ($mailAccountId < 1
            || trim($providerContainerId) === '' || mb_strlen($providerContainerId) > 255
            || trim($name) === '' || mb_strlen($name) > 255
            || ($kind !== null && (trim($kind) === '' || mb_strlen($kind) > 255))) {
            throw new InvalidArgumentException('Container state requires bounded account-qualified values.');
        }
    }
}
