<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Read;

final readonly class DeltaSyncResult
{
    public function __construct(
        public int $processedCount,
        public bool $caughtUp,
        public bool $repairPending,
    ) {}
}
