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
     * @param  bool  $writeSent  True when the provider may have applied the write: the request
     *                           was sent and the provider did not definitively reject it. False
     *                           when no write was sent or the provider rejected it without applying.
     */
    public function __construct(
        public readonly MailWriteCode $safeCode,
        public readonly bool $writeSent = false,
        public readonly ?MailImportCode $providerCode = null,
        ?Throwable $previous = null,
        public readonly ?string $draftId = null,
    ) {
        parent::__construct($safeCode->summary(), 0, $previous);
    }

    /**
     * Classify a provider failure. A failure raised after the write request was
     * sent may have applied unless the provider answered with a 4xx status.
     */
    public static function fromProvider(MailImportFailure $failure, bool $requestSent): self
    {
        $rejected = $failure->httpStatus !== null && $failure->httpStatus >= 400 && $failure->httpStatus < 500;

        return new self(
            $failure->safeCode === MailImportCode::MessageUnavailable ? MailWriteCode::MessageNotFound : MailWriteCode::ProviderFailed,
            $requestSent && ! $rejected,
            $failure->safeCode,
            $failure,
        );
    }
}
