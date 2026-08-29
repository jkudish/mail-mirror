<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Read;

final readonly class ProviderDeletionEvidence
{
    /** @param array<string, mixed> $providerMetadata */
    public function __construct(
        public int $mailAccountId,
        public string $providerMessageId,
        public string $proofCode,
        public string $auditReference,
        public array $providerMetadata = [],
    ) {}
}
