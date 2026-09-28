<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Enums;

enum MailWriteCode: string
{
    case AmbiguousMailboxRole = 'ambiguous_mailbox_role';
    case MessageNotFound = 'message_not_found';
    case NotInTrash = 'not_in_trash';
    case ProviderFailed = 'provider_failed';
    case TargetBusy = 'target_busy';
    case Unconfirmed = 'unconfirmed';
    case UnsupportedDriver = 'unsupported_driver';
    case UnsupportedState = 'unsupported_state';

    public function summary(): string
    {
        return match ($this) {
            self::AmbiguousMailboxRole => 'The provider mailbox roles do not identify exactly one source and destination.',
            self::MessageNotFound => 'The provider message does not exist in the supplied account.',
            self::NotInTrash => 'The provider message is not in Trash and is not confirmed as restored by an earlier request.',
            self::ProviderFailed => 'The provider request failed.',
            self::TargetBusy => 'Another write for the same provider message is in progress.',
            self::Unconfirmed => 'The provider state did not confirm the write.',
            self::UnsupportedDriver => 'The mail driver does not support this write.',
            self::UnsupportedState => 'The provider message state cannot be changed without broader mutation.',
        };
    }
}
