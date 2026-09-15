<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Exceptions;

use InvalidArgumentException;
use RuntimeException;

final class DeltaRepairRequired extends RuntimeException
{
    public function __construct(public readonly string $recoveryCursor)
    {
        if ($recoveryCursor === '' || strlen($recoveryCursor) > 8192) {
            throw new InvalidArgumentException('Delta repair requires a bounded opaque recovery cursor.');
        }

        parent::__construct('The applied provider cursor requires a bounded authoritative repair.');
    }
}
