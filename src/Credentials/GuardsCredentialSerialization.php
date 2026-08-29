<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Credentials;

use Jkudish\MailMirror\Exceptions\ConnectionCredentialException;

trait GuardsCredentialSerialization
{
    /** @return never */
    public function __serialize(): array
    {
        throw new ConnectionCredentialException('Credentials cannot be serialized.');
    }

    /** @return array{} */
    public function __debugInfo(): array
    {
        return [];
    }
}
