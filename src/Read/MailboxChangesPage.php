<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Read;

use InvalidArgumentException;

final readonly class MailboxChangesPage
{
    /**
     * @param  list<ChangedMessageState>  $messages
     * @param  list<ProviderDeletionEvidence>  $deletions
     * @param  list<MailboxContainerState>  $containers
     */
    public function __construct(
        public array $messages,
        public array $deletions,
        public array $containers,
        public bool $containersComplete,
        public string $nextCursor,
        public bool $complete,
        public ?AccountProfile $accountProfile = null,
    ) {
        if ($nextCursor === '' || strlen($nextCursor) > 8192) {
            throw new InvalidArgumentException('A changes page requires a bounded opaque applied cursor.');
        }
    }
}
