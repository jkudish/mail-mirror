<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Gmail;

final readonly class GmailPullBatch
{
    /** @param list<GmailPulledNotification> $notifications */
    public function __construct(public array $notifications) {}
}
