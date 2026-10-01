<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Write;

use Jkudish\MailMirror\Enums\MailWriteCode;
use Jkudish\MailMirror\Exceptions\MailWriteFailure;

/**
 * Caller-built RFC 5322 message bytes for a provider draft. MailMirror stores
 * and sends these bytes unchanged; building MIME is the caller's job.
 *
 * The bytes must have a CRLF-delimited header section with well-formed fields,
 * at most one of each single-instance field, and exactly one Message-ID. That
 * Message-ID is the caller's idempotency key for creating and sending.
 */
final readonly class DraftContent
{
    /** RFC 5322 section 3.6 fields that may appear at most once. */
    private const SINGLE_FIELDS = [
        'date', 'from', 'sender', 'reply-to', 'to', 'cc', 'bcc',
        'message-id', 'in-reply-to', 'references', 'subject',
    ];

    private const MAX_HEADER_BYTES = 262144;

    /** The Message-ID without angle brackets. */
    public string $messageId;

    public string $sha256;

    /** @var array<string, list<string>> unfolded field values keyed by lower-case name */
    public array $headers;

    /** @throws MailWriteFailure invalid_draft */
    public function __construct(public string $bytes)
    {
        $headers = self::parseHeaders($bytes);
        $messageIds = $headers['message-id'] ?? [];

        if ($headers === null || count($messageIds) !== 1
            || ($messageId = self::messageIdValue($messageIds[0])) === null) {
            throw new MailWriteFailure(MailWriteCode::InvalidDraft);
        }

        $this->headers = $headers;
        $this->messageId = $messageId;
        $this->sha256 = hash('sha256', $bytes);
    }

    public function size(): int
    {
        return strlen($this->bytes);
    }

    /**
     * The Message-ID of provider bytes that did not necessarily come from this
     * package, or null when it has none or it is malformed.
     */
    public static function messageIdOf(string $bytes): ?string
    {
        $values = self::parseHeaders($bytes)['message-id'] ?? [];

        return count($values) === 1 ? self::messageIdValue($values[0]) : null;
    }

    /** @return array<string, list<string>>|null null when the header section is malformed */
    private static function parseHeaders(string $bytes): ?array
    {
        $end = strpos($bytes, "\r\n\r\n");

        if ($end === false || $end === 0 || $end > self::MAX_HEADER_BYTES) {
            return null;
        }

        $section = substr($bytes, 0, $end);

        // Every line ends in CRLF, and no control character other than HTAB appears.
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]|\r(?!\n)|(?<!\r)\n/', $section) === 1) {
            return null;
        }

        $headers = [];
        $name = null;

        foreach (explode("\r\n", $section) as $line) {
            if (strlen($line) > 998) {
                return null;
            }

            if ($line !== '' && ($line[0] === ' ' || $line[0] === "\t")) {
                if ($name === null) {
                    return null;
                }

                $last = array_key_last($headers[$name]);
                $headers[$name][$last] .= $line;

                continue;
            }

            if (preg_match('/\A([\x21-\x39\x3B-\x7E]+):(.*)\z/s', $line, $matches) !== 1) {
                return null;
            }

            $name = strtolower($matches[1]);
            $headers[$name][] = $matches[2];
        }

        foreach (self::SINGLE_FIELDS as $field) {
            if (count($headers[$field] ?? []) > 1) {
                return null;
            }
        }

        return $headers;
    }

    private static function messageIdValue(string $value): ?string
    {
        return preg_match('/\A\s*<([^<>\s@"]{1,250}@[^<>\s@"]{1,250})>\s*\z/', $value, $matches) === 1 ? $matches[1] : null;
    }
}
