<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Read;

use InvalidArgumentException;

final readonly class InventoryPage
{
    /**
     * @param  list<MessageReference>  $messages
     * @param  list<ProviderDeletionEvidence>  $deletions
     * @param  list<MailboxIdentity>  $identities
     */
    public function __construct(
        public array $messages,
        public ?string $nextCursor,
        public bool $complete,
        public array $deletions = [],
        public ?AccountProfile $accountProfile = null,
        public array $identities = [],
        public bool $identitiesComplete = false,
    ) {
        if (! $complete && ($nextCursor === null || $nextCursor === '')) {
            throw new InvalidArgumentException('An incomplete inventory page requires an opaque next cursor.');
        }

        if ($complete && $nextCursor !== null) {
            throw new InvalidArgumentException('A complete inventory page cannot include a next cursor.');
        }

        if ($identitiesComplete && $accountProfile === null) {
            throw new InvalidArgumentException('A complete identity inventory requires its account profile.');
        }
    }
}
