<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Enums;

enum SyncRunStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
}
