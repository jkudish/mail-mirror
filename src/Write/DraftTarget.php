<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Write;

use InvalidArgumentException;

/**
 * One provider draft qualified by its mail account and the owner tuple the
 * consumer has already authorized.
 */
final readonly class DraftTarget
{
    public function __construct(
        public int $mailAccountId,
        public ?string $ownerType,
        public string|int|null $ownerId,
        public string $draftId,
    ) {
        if ($mailAccountId < 1 || trim($draftId) === '' || mb_strlen($draftId) > 255) {
            throw new InvalidArgumentException('A draft target requires an account and a bounded opaque draft ID.');
        }

        if (($ownerType === null) !== ($ownerId === null)) {
            throw new InvalidArgumentException('Owner type and owner ID must both be null or both be present.');
        }
    }
}
