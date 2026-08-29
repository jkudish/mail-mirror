<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Read;

use InvalidArgumentException;

final readonly class ProviderDeletionResolution
{
    public function __construct(
        public int $mailAccountId,
        public string $providerMessageId,
    ) {
        if ($mailAccountId < 1 || trim($providerMessageId) === '' || mb_strlen($providerMessageId) > 255) {
            throw new InvalidArgumentException('A deletion resolution requires an account and bounded provider message ID.');
        }
    }
}
