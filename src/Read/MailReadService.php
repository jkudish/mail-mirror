<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Read;

use InvalidArgumentException;
use Jkudish\MailMirror\Models\MailAccount;

final readonly class MailReadService
{
    public function __construct(private MailDriverRegistry $drivers) {}

    /** @return list<MessageReference> */
    public function inventory(MailAccount $account): array
    {
        $reader = $this->drivers->reader($account->driver);
        $references = [];

        foreach ($reader->inventory($account) as $reference) {
            $this->assertReferenceBelongsTo($account, $reference);
            $references[] = $reference;
        }

        return $references;
    }

    public function retrieve(MailAccount $account, MessageReference $message): RetrievedMessage
    {
        $this->assertReferenceBelongsTo($account, $message);

        $retrieved = $this->drivers->reader($account->driver)->retrieve($account, $message);
        $this->assertReferenceBelongsTo($account, $retrieved->reference);

        return $retrieved;
    }

    private function assertReferenceBelongsTo(MailAccount $account, MessageReference $message): void
    {
        if ($message->mailAccountId !== $account->getKey() || $message->driver !== $account->driver) {
            throw new InvalidArgumentException('The message reference does not belong to the supplied mail account.');
        }
    }
}
