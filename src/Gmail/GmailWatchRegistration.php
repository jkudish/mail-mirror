<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Gmail;

use DateTimeImmutable;

final readonly class GmailWatchRegistration
{
    public function __construct(
        public int $mailAccountId,
        public GmailWatchIdentity $identity,
        public string $historyIdHint,
        public DateTimeImmutable $expiresAt,
    ) {}
}
