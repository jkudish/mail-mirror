<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Enums;

enum MailSourceChangeKind: string
{
    case MessageChanged = 'message_changed';
    case MessageDeleted = 'message_deleted';
    case RawChanged = 'raw_changed';
    case ContainerChanged = 'container_changed';
    case ContainerDeleted = 'container_deleted';
    case ProviderDeletionChanged = 'provider_deletion_changed';
}
