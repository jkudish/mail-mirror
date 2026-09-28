<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Exceptions;

use Jkudish\MailMirror\Enums\MailImportCode;
use Jkudish\MailMirror\Enums\MailWriteCode;
use RuntimeException;
use Throwable;

/**
 * A provider write that did not complete with confirmation. The message is
 * safe to log: it carries no provider IDs, payloads, or credentials.
 */
final class MailWriteFailure extends RuntimeException
{
    /**
     * @param  bool  $writeSent  Whether the provider write request was sent. When true the
     *                           provider outcome is unknown until a later request re-reads it.
     */
    public function __construct(
        public readonly MailWriteCode $safeCode,
        public readonly bool $writeSent = false,
        public readonly ?MailImportCode $providerCode = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($safeCode->summary(), 0, $previous);
    }

    public static function fromProvider(MailImportFailure $failure, bool $writeSent): self
    {
        return new self(
            $failure->safeCode === MailImportCode::MessageUnavailable ? MailWriteCode::MessageNotFound : MailWriteCode::ProviderFailed,
            $writeSent,
            $failure->safeCode,
            $failure,
        );
    }
}
