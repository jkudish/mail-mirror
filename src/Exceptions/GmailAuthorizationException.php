<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Exceptions;

use RuntimeException;

final class GmailAuthorizationException extends RuntimeException
{
    public function __construct(public readonly bool $grantInvalid = false)
    {
        parent::__construct('Gmail authorization could not be completed safely.');
    }
}
