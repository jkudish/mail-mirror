<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Read;

use InvalidArgumentException;
use Jkudish\MailMirror\Contracts\MailboxReader;
use Jkudish\MailMirror\Enums\MailDriver;
use LogicException;

final class MailDriverRegistry
{
    /** @var array<string, MailboxReader> */
    private array $readers = [];

    public function register(MailDriver $driver, MailboxReader $reader): void
    {
        if ($reader->driver() !== $driver) {
            throw new InvalidArgumentException('The reader driver does not match its registry key.');
        }

        $this->readers[$driver->value] = $reader;
    }

    public function reader(MailDriver $driver): MailboxReader
    {
        return $this->readers[$driver->value]
            ?? throw new LogicException(sprintf('No reader is registered for %s.', $driver->value));
    }
}
