<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Gmail;

use DateTimeImmutable;
use Jkudish\MailMirror\Credentials\GuardsCredentialSerialization;

final readonly class GmailPulledNotification
{
    use GuardsCredentialSerialization;

    public function __construct(
        public int $mailAccountId,
        private string $subscription,
        private string $ackId,
        public string $messageId,
        public DateTimeImmutable $publishedAt,
        public ?string $historyIdHint,
        public ?string $rejectionReason,
    ) {}

    public function accepted(): bool
    {
        return $this->rejectionReason === null;
    }

    public function subscription(): string
    {
        return $this->subscription;
    }

    public function ackId(): string
    {
        return $this->ackId;
    }
}
