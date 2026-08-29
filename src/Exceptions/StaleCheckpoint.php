<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Exceptions;

use RuntimeException;

final class StaleCheckpoint extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The account checkpoint advanced concurrently; this page was not persisted.');
    }
}
