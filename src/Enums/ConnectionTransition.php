<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Enums;

enum ConnectionTransition: string
{
    case Stored = 'stored';
    case Rotated = 'rotated';
    case Disabled = 'disabled';
    case Revoked = 'revoked';
    case Reconnected = 'reconnected';
}
