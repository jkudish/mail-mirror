<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Exceptions;

use InvalidArgumentException;
use Jkudish\MailMirror\Enums\MailImportCode;
use Jkudish\MailMirror\Enums\MailImportStage;
use RuntimeException;

final class MailImportFailure extends RuntimeException
{
    public readonly MailImportStage $stage;

    public readonly MailImportCode $safeCode;

    public function __construct(
        MailImportStage|string $stage,
        MailImportCode|string $safeCode,
        public readonly bool $retryable = false,
        public readonly int $attempts = 1,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        if ($attempts < 1 || ($retryAfterSeconds !== null && ($retryAfterSeconds < 1 || $retryAfterSeconds > 300))) {
            throw new InvalidArgumentException('Import failure retry metadata must be positive and bounded.');
        }

        $this->stage = MailImportStage::normalize($stage);
        $this->safeCode = MailImportCode::normalize($safeCode);

        parent::__construct('Mail import failed with package-classified metadata.');
    }
}
