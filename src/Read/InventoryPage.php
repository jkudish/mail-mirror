<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Read;

use InvalidArgumentException;

final readonly class InventoryPage
{
    /**
     * @param  list<MessageReference>  $messages
     * @param  list<ProviderDeletionEvidence>  $deletions
     */
    public function __construct(
        public array $messages,
        public ?string $nextCursor,
        public bool $complete,
        public array $deletions = [],
    ) {
        if (! $complete && ($nextCursor === null || $nextCursor === '')) {
            throw new InvalidArgumentException('An incomplete inventory page requires an opaque next cursor.');
        }
    }
}
