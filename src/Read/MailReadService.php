<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Read;

use InvalidArgumentException;
use Jkudish\MailMirror\Models\MailAccount;

final readonly class MailReadService
{
    public function __construct(private MailDriverRegistry $drivers) {}

    public function inventoryPage(MailAccount $account, ?string $cursor = null): InventoryPage
    {
        $reader = $this->drivers->reader($account->driver);
        $page = $reader->inventoryPage($account, $cursor);
        $maximum = config('mail-mirror.inventory_page_max_messages', 500);

        if (! is_int($maximum) || $maximum < 1) {
            $maximum = 500;
        }

        if (count($page->messages) + count($page->deletions) > $maximum) {
            throw new InvalidArgumentException('The provider inventory page exceeds the configured resource limit.');
        }

        foreach ($page->messages as $reference) {
            $this->assertReferenceBelongsTo($account, $reference);
        }

        foreach ($page->deletions as $deletion) {
            if ($deletion->mailAccountId !== $account->getKey()) {
                throw new InvalidArgumentException('The provider deletion evidence does not belong to the supplied mail account.');
            }
        }

        return $page;
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
