<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Enums;

enum SubmissionOutcome: string
{
    /** Provider reads show the draft was accepted for sending. */
    case Submitted = 'submitted';

    /** Provider reads show the draft still exists unsent at the expected revision. */
    case NotSubmitted = 'not_submitted';

    /** Provider reads cannot tell; never send again on this answer. */
    case Unknown = 'unknown';
}
