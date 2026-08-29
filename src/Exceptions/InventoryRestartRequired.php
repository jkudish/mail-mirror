<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Exceptions;

use InvalidArgumentException;
use RuntimeException;

final class InventoryRestartRequired extends RuntimeException
{
    public function __construct(public readonly string $restartCursor)
    {
        if ($restartCursor === '' || strlen($restartCursor) > 8192) {
            throw new InvalidArgumentException('An inventory restart requires a bounded opaque cursor.');
        }

        parent::__construct('The provider cursor is no longer usable; a fresh inventory scan is required.');
    }
}
