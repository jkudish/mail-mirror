<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Read;

use DateTimeImmutable;

final readonly class MailMessageState
{
    /**
     * @param  list<string>  $keywords
     * @param  list<array{id: int, provider_id: string, name: string, kind: string|null}>  $containers
     */
    public function __construct(
        public bool $unread,
        public bool $flagged,
        public bool $draft,
        public bool $sent,
        public bool $spam,
        public bool $trash,
        public array $keywords,
        public array $containers,
        public DateTimeImmutable $sourceVersion,
    ) {}

    /** @return array{unread: bool, flagged: bool, draft: bool, sent: bool, spam: bool, trash: bool} */
    public function flags(): array
    {
        return [
            'unread' => $this->unread,
            'flagged' => $this->flagged,
            'draft' => $this->draft,
            'sent' => $this->sent,
            'spam' => $this->spam,
            'trash' => $this->trash,
        ];
    }
}
