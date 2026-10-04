<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Enums;

enum MailWriteOutcome: string
{
    /** This call sent the provider write and a re-read confirmed it. */
    case Applied = 'applied';

    /** An earlier call already applied the write; a re-read confirmed it and no write was sent. */
    case AlreadyApplied = 'already_applied';

    /** A guarded call observed the desired state without proof of an earlier package write. */
    case AlreadySatisfied = 'already_satisfied';
}
