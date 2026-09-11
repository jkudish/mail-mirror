<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

final readonly class ProviderDeletionStateChanged implements ShouldDispatchAfterCommit
{
    public function __construct(
        public int $mailAccountId,
        public string $providerMessageId,
        public bool $hasEvidence,
    ) {}
}
