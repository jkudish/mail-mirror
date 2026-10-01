<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Enums;

enum SubmissionOutcome: string
{
    /** Provider reads show the draft was accepted for sending. */
    case Submitted = 'submitted';

    /**
     * Provider reads do not prove a send. Absence is never proof of not sending:
     * providers may destroy submission records, and a timed-out request may still
     * be in flight. Never send again on this answer.
     */
    case Unknown = 'unknown';
}
