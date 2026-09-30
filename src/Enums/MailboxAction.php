<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Enums;

/** One reversible change to one provider message's mailbox state. */
enum MailboxAction: string
{
    case MarkRead = 'mark_read';
    case MarkUnread = 'mark_unread';
    case Star = 'star';
    case Unstar = 'unstar';
    case Archive = 'archive';
    case Unarchive = 'unarchive';
    case AddContainer = 'add_container';
    case RemoveContainer = 'remove_container';
    case Trash = 'trash';
    case Untrash = 'untrash';
    case Spam = 'spam';
    case NotSpam = 'not_spam';

    public function targetsContainer(): bool
    {
        return $this === self::AddContainer || $this === self::RemoveContainer;
    }

    /**
     * The failure for a message already in the destination state when this
     * package recorded no intent for it. Untrash keeps the code the Trash
     * restore reported before the other actions existed.
     */
    public function unchangedCode(): MailWriteCode
    {
        return $this === self::Untrash ? MailWriteCode::NotInTrash : MailWriteCode::AlreadyInState;
    }
}
