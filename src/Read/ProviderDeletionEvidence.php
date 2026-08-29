<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Read;

use InvalidArgumentException;

final readonly class ProviderDeletionEvidence
{
    /** @param array<string, mixed> $providerMetadata */
    public function __construct(
        public int $mailAccountId,
        public string $providerMessageId,
        public string $proofCode,
        public string $auditReference,
        public array $providerMetadata = [],
    ) {
        if ($mailAccountId < 1
            || trim($providerMessageId) === '' || mb_strlen($providerMessageId) > 255
            || ! preg_match('/^[a-z][a-z0-9_.-]{1,63}$/', $proofCode)
            || trim($auditReference) === ''
            || mb_strlen($auditReference) > 160) {
            throw new InvalidArgumentException('Deletion evidence requires bounded account-qualified metadata.');
        }
    }
}
