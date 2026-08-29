<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Read;

use InvalidArgumentException;

final readonly class AccountProfile
{
    /** @param array<string, mixed> $providerMetadata */
    public function __construct(
        public string $providerAccountId,
        public ?string $emailAddress = null,
        public array $providerMetadata = [],
    ) {
        if (trim($providerAccountId) === '' || mb_strlen($providerAccountId) > 255
            || ($emailAddress !== null && (trim($emailAddress) === '' || mb_strlen($emailAddress) > 255))) {
            throw new InvalidArgumentException('An account profile requires bounded provider identity metadata.');
        }
    }
}
