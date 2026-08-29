<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Enums;

enum MailDriver: string
{
    case Gmail = 'gmail';
    case Jmap = 'jmap';
}
