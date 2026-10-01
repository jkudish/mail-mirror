<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Write;

use InvalidArgumentException;

/** One mail account qualified by the owner tuple the consumer has already authorized. */
final readonly class MailAccountTarget
{
    public function __construct(
        public int $mailAccountId,
        public ?string $ownerType,
        public string|int|null $ownerId,
    ) {
        if ($mailAccountId < 1) {
            throw new InvalidArgumentException('An account target requires an account.');
        }

        if (($ownerType === null) !== ($ownerId === null)) {
            throw new InvalidArgumentException('Owner type and owner ID must both be null or both be present.');
        }
    }
}
