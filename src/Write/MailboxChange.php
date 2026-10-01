<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Write;

use InvalidArgumentException;
use Jkudish\MailMirror\Enums\MailboxAction;

/**
 * Exactly one reversible mailbox change for one provider message. Container
 * actions name one provider label (Gmail) or mailbox (JMAP) ID; system labels
 * and role mailboxes change only through their dedicated actions.
 */
final readonly class MailboxChange
{
    public function __construct(
        public MailboxAction $action,
        public ?string $containerId = null,
    ) {
        if ($action->targetsContainer() !== ($containerId !== null)) {
            throw new InvalidArgumentException('Only container actions, and all of them, name a container.');
        }

        if ($containerId !== null && ($containerId === '' || mb_strlen($containerId) > 255
            || preg_match('/[\x00-\x20\x7F]/', $containerId) === 1)) {
            throw new InvalidArgumentException('A container ID must be a bounded opaque provider ID.');
        }
    }

    public static function markRead(): self
    {
        return new self(MailboxAction::MarkRead);
    }

    public static function markUnread(): self
    {
        return new self(MailboxAction::MarkUnread);
    }

    public static function star(): self
    {
        return new self(MailboxAction::Star);
    }

    public static function unstar(): self
    {
        return new self(MailboxAction::Unstar);
    }

    public static function archive(): self
    {
        return new self(MailboxAction::Archive);
    }

    public static function unarchive(): self
    {
        return new self(MailboxAction::Unarchive);
    }

    public static function addContainer(string $containerId): self
    {
        return new self(MailboxAction::AddContainer, $containerId);
    }

    public static function removeContainer(string $containerId): self
    {
        return new self(MailboxAction::RemoveContainer, $containerId);
    }

    public static function trash(): self
    {
        return new self(MailboxAction::Trash);
    }

    public static function untrash(): self
    {
        return new self(MailboxAction::Untrash);
    }

    public static function spam(): self
    {
        return new self(MailboxAction::Spam);
    }

    public static function notSpam(): self
    {
        return new self(MailboxAction::NotSpam);
    }

    /**
     * Every change to one message shares one intent slot, which names the last
     * change this package may have applied. Untrash keeps the key and value the
     * Trash restore used, so an intent recorded before an upgrade still confirms
     * a retry after it; every other change uses the shared "mailbox" key.
     *
     * @return list<string> every intent-key suffix in the slot
     */
    public static function intentNames(): array
    {
        return ['restore-from-trash', 'mailbox'];
    }

    /** The intent-key suffix this change records under. */
    public function intentName(): string
    {
        return $this->action === MailboxAction::Untrash ? 'restore-from-trash' : 'mailbox';
    }

    /** The recorded value that proves this exact change, not merely one on the same state. */
    public function intentValue(): string|true
    {
        return match (true) {
            $this->action === MailboxAction::Untrash => true,
            $this->containerId !== null => $this->action->value.':'.hash('sha256', $this->containerId),
            default => $this->action->value,
        };
    }
}
