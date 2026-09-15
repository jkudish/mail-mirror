<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Gmail;

enum GmailWatchSetup: string
{
    case Create = 'create';
    case Renew = 'renew';
    case Replace = 'replace';
}
