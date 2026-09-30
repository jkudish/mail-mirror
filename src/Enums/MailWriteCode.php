<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Enums;

enum MailWriteCode: string
{
    case AlreadyInState = 'already_in_state';
    case AmbiguousMailboxRole = 'ambiguous_mailbox_role';
    case ContainerNotFound = 'container_not_found';
    case DraftNotFound = 'draft_not_found';
    case DraftTooLarge = 'draft_too_large';
    case InvalidDraft = 'invalid_draft';
    case LockExpired = 'lock_expired';
    case MessageIdConflict = 'message_id_conflict';
    case MessageNotFound = 'message_not_found';
    case NotInTrash = 'not_in_trash';
    case ProviderFailed = 'provider_failed';
    case RevisionConflict = 'revision_conflict';
    case StaleRevision = 'stale_revision';
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
            self::DraftNotFound => 'The provider draft does not exist in the supplied account.',
            self::DraftTooLarge => 'The draft exceeds mail-mirror.writes.max_draft_bytes.',
            self::InvalidDraft => 'The draft bytes lack exactly one valid Message-ID or have malformed headers.',
            self::LockExpired => 'The write lock had too little time left to send the write safely; retry.',
            self::MessageIdConflict => 'Another provider draft already uses this Message-ID.',
            self::MessageNotFound => 'The provider message does not exist in the supplied account.',
            self::NotInTrash => 'The provider message is not in Trash and is not confirmed as restored by an earlier request.',
            self::ProviderFailed => 'The provider request failed.',
            self::RevisionConflict => 'The provider draft changed during the write; re-read it before trying again.',
            self::StaleRevision => 'The provider draft no longer matches the expected revision; nothing was written.',
            self::TargetBusy => 'Another write for the same provider message is in progress.',
            self::Unconfirmed => 'The provider state did not confirm the write.',
            self::UnsupportedDriver => 'The mail driver does not support this write.',
            self::UnsupportedContainer => 'System labels and role mailboxes change only through their dedicated actions.',
            self::UnsupportedState => 'The provider message state cannot be changed without broader mutation.',
            self::WritesDisabled => 'Provider writes are disabled by mail-mirror.writes.enabled.',
        };
    }
}
