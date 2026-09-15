<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Exceptions;

use InvalidArgumentException;
use RuntimeException;

final class ProviderNotificationException extends RuntimeException
{
    private const CODES = [
        'authentication_failed',
        'configuration_invalid',
        'malformed_payload',
        'provider_unavailable',
        'resource_mismatch',
        'setup_conflict',
    ];

    public function __construct(
        public readonly string $safeCode,
        public readonly bool $retryable = false,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        if (! in_array($safeCode, self::CODES, true)
            || ($retryAfterSeconds !== null && ($retryAfterSeconds < 1 || $retryAfterSeconds > 300))) {
            throw new InvalidArgumentException('Provider notification failure metadata is invalid.');
        }

        parent::__construct('Provider notification operation failed with package-classified metadata.');
    }
}
