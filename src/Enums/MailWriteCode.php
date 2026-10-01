<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Enums;

enum MailWriteCode: string
{
    case AlreadyInState = 'already_in_state';
    case AmbiguousMailboxRole = 'ambiguous_mailbox_role';
    case ContainerNotFound = 'container_not_found';
    case LockExpired = 'lock_expired';
    case MessageNotFound = 'message_not_found';
    case NotInTrash = 'not_in_trash';
    case ProviderFailed = 'provider_failed';
    case TargetBusy = 'target_busy';
    case Unconfirmed = 'unconfirmed';
    case UnsupportedDriver = 'unsupported_driver';
    case UnsupportedContainer = 'unsupported_container';
    case UnsupportedState = 'unsupported_state';
    case WritesDisabled = 'writes_disabled';

    public function summary(): string
    {
        return match ($this) {
            self::AlreadyInState => 'The provider message is already in the requested state and no earlier request by this package is known to have changed it.',
            self::AmbiguousMailboxRole => 'The provider mailbox roles do not identify exactly one source and destination.',
            self::ContainerNotFound => 'The requested provider label or mailbox does not exist in the supplied account.',
            self::LockExpired => 'The write lock had too little time left to send the write safely; retry.',
            self::MessageNotFound => 'The provider message does not exist in the supplied account.',
            self::NotInTrash => 'The provider message is not in Trash and is not confirmed as restored by an earlier request.',
            self::ProviderFailed => 'The provider request failed.',
            self::TargetBusy => 'Another write for the same provider message is in progress.',
            self::Unconfirmed => 'The provider state did not confirm the write.',
            self::UnsupportedDriver => 'The mail driver does not support this write.',
            self::UnsupportedContainer => 'System labels and role mailboxes change only through their dedicated actions.',
            self::UnsupportedState => 'The provider message state cannot be changed without broader mutation.',
            self::WritesDisabled => 'Provider writes are disabled by mail-mirror.writes.enabled.',
        };
    }
}
