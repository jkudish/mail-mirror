<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Exceptions;

use InvalidArgumentException;
use RuntimeException;

final class MailImportFailure extends RuntimeException
{
    public function __construct(
        public readonly string $stage,
        public readonly string $safeCode,
        public readonly string $safeSummary,
        public readonly bool $retryable = false,
        public readonly int $attempts = 1,
    ) {
        if (! preg_match('/^[a-z][a-z0-9_]{1,63}$/', $stage)
            || ! preg_match('/^[a-z][a-z0-9_.-]{1,63}$/', $safeCode)
            || trim($safeSummary) === ''
            || mb_strlen($safeSummary) > 160
            || $attempts < 1) {
            throw new InvalidArgumentException('Import failures require bounded, redacted stable metadata.');
        }

        parent::__construct('Mail import failed with safe code '.$safeCode.'.');
    }
}
