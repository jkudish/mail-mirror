<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Jmap;

final readonly class FastmailEventBatch
{
    /** @param list<FastmailEventHint> $stateChanges */
    public function __construct(
        public array $stateChanges,
        public ?string $lastEventId,
        public ?int $pingIntervalSeconds,
    ) {}
}
