<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Jmap;

final readonly class FastmailEventHint
{
    /** @param array<string, string> $changed */
    public function __construct(
        public ?string $eventId,
        public array $changed,
    ) {}
}
