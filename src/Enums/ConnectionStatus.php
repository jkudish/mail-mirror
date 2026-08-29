<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Enums;

enum ConnectionStatus: string
{
    case Ready = 'ready';
    case Disabled = 'disabled';
    case Revoked = 'revoked';
}
