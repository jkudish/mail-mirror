<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Gmail;

use DateTimeImmutable;
use Jkudish\MailMirror\Exceptions\MailWriteFailure;
use Jkudish\MailMirror\Write\DraftContent;

/** Narrow readback comparator, never a revision hash or MIME renderer. */
final class GmailDraftContent
{
    public static function matches(string $observed, DraftContent $requested): bool
    {
        try {
            $actual = new DraftContent($observed);
        } catch (MailWriteFailure) {
            return false;
        }

        // Permit added trace headers, not removal/change of caller-provided ones.
        $received = $requested->headers['received'] ?? [];

        if ($received !== [] && array_slice($actual->headers['received'] ?? [], -count($received)) !== $received) {
            return false;
        }

        $left = self::headers($actual);
        $right = self::headers($requested);

        if ($left === null || $right === null) {
            return false;
        }

        // Omission delegates Date selection, including later Date-only edits.
        // Validate both maps first; never ignore a malformed provider Date.
        if (! array_key_exists('date', $right)) {
            unset($left['date']);
        }

        return $left === $right
            && substr($observed, (int) strpos($observed, "\r\n\r\n") + 4)
                === substr($requested->bytes, (int) strpos($requested->bytes, "\r\n\r\n") + 4);
    }

    /** @return array<string, list<string>>|null */
    private static function headers(DraftContent $content): ?array
    {
        $headers = $content->headers;
        unset($headers['message-id'], $headers['received']);

        foreach ($headers as $name => $values) {
            // All other fields, including arbitrary X-* and MIME metadata, remain
            // significant. Reject ambiguous duplicates rather than picking one.
            if (count($values) !== 1) {
                return null;
            }

            $value = trim($values[0]);

            if ($name === 'date') {
                $instant = self::dateInstant($value);

                if ($instant === null) {
                    return null;
                }

                $value = $instant;
            }

            $headers[$name] = [$value];
        }

        ksort($headers);

        return $headers;
    }

    /** Supported RFC 5322 Date subset: optional weekday, seconds, numeric offset or GMT/UT. */
    private static function dateInstant(string $value): ?string
    {
        if (preg_match('/\A(?:(Mon|Tue|Wed|Thu|Fri|Sat|Sun),\s+)?(\d{1,2})\s+(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\s+(\d{4})\s+(\d{2}:\d{2}:\d{2})\s+([+-]\d{4}|GMT|UT)\z/', $value, $parts) !== 1) {
            return null;
        }

        $zone = in_array($parts[6], ['GMT', 'UT'], true) ? '+0000' : $parts[6];

        if ((int) substr($zone, 1, 2) > 23 || (int) substr($zone, 3, 2) > 59) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!j M Y H:i:s O', $parts[2].' '.$parts[3].' '.$parts[4].' '.$parts[5].' '.$zone);
        $errors = DateTimeImmutable::getLastErrors();

        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || ($parts[1] !== '' && $parts[1] !== $date->format('D'))) {
            return null;
        }

        return $date->format('U');
    }
}
