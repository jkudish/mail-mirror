<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Read;

use InvalidArgumentException;
use Jkudish\MailMirror\Contracts\BudgetedDeltaMailboxReader;
use Jkudish\MailMirror\Contracts\BudgetedMailboxReader;
use Jkudish\MailMirror\Contracts\DeltaMailboxReader;
use Jkudish\MailMirror\Models\MailAccount;

final readonly class MailReadService
{
    public function __construct(private MailDriverRegistry $drivers) {}

    public function inventoryPage(
        MailAccount $account,
        ?string $cursor = null,
        ?SyncWorkBudget $budget = null,
    ): InventoryPage {
        $reader = $this->drivers->reader($account->driver);

        if ($budget !== null && ! $reader instanceof BudgetedMailboxReader) {
            throw new InvalidArgumentException('The mail driver does not expose provider work metering.');
        }

        $page = $reader instanceof BudgetedMailboxReader
            ? $reader->inventoryPage($account, $cursor, $budget)
            : $reader->inventoryPage($account, $cursor);
        $maximum = config('mail-mirror.inventory_page_max_messages', 500);

        if (! is_int($maximum) || $maximum < 1) {
            $maximum = 500;
        }

        if (count($page->messages) + count($page->deletions) + count($page->identities) + count($page->deletionResolutions) > $maximum) {
            throw new InvalidArgumentException('The provider inventory page exceeds the configured resource limit.');
        }

        $budget?->recordListedIds(count($page->messages) + count($page->deletions) + count($page->deletionResolutions));

        if ($page->accountProfile !== null
            && $page->accountProfile->providerAccountId !== $account->provider_account_id) {
            throw new InvalidArgumentException('The provider account profile does not belong to the supplied mail account.');
        }

        foreach ($page->messages as $reference) {
            $this->assertReferenceBelongsTo($account, $reference);
        }

        foreach ($page->deletions as $deletion) {
            if ($deletion->mailAccountId !== $account->getKey()) {
                throw new InvalidArgumentException('The provider deletion evidence does not belong to the supplied mail account.');
            }
        }

        $resolutionIds = [];

        foreach ($page->deletionResolutions as $resolution) {
            if ($resolution->mailAccountId !== $account->getKey()) {
                throw new InvalidArgumentException('The provider deletion resolution does not belong to the supplied mail account.');
            }

            $resolutionIds[] = $resolution->providerMessageId;
        }

        if (count(array_unique($resolutionIds)) !== count($resolutionIds)) {
            throw new InvalidArgumentException('The provider deletion resolutions must be unique.');
        }

        return $page;
    }

    public function retrieve(
        MailAccount $account,
        MessageReference $message,
        ?SyncWorkBudget $budget = null,
    ): RetrievedMessage {
        $this->assertReferenceBelongsTo($account, $message);
        $reader = $this->drivers->reader($account->driver);

        if ($budget !== null && ! $reader instanceof BudgetedMailboxReader) {
            throw new InvalidArgumentException('The mail driver does not expose provider work metering.');
        }

        $budget?->claimFetchedMessage();

        try {
            $retrieved = $reader instanceof BudgetedMailboxReader
                ? $reader->retrieve($account, $message, $budget)
                : $reader->retrieve($account, $message);
        } finally {
            $budget?->finishFetchedMessage();
        }

        $this->assertReferenceBelongsTo($account, $retrieved->reference);

        return $retrieved;
    }

    public function changesPage(
        MailAccount $account,
        ?string $cursor = null,
        ?SyncWorkBudget $budget = null,
    ): MailboxChangesPage {
        $reader = $this->drivers->reader($account->driver);

        if (! $reader instanceof DeltaMailboxReader) {
            throw new InvalidArgumentException('The mail driver does not support changed-message synchronization.');
        }

        if ($budget !== null && ! $reader instanceof BudgetedDeltaMailboxReader) {
            throw new InvalidArgumentException('The mail driver does not expose provider delta work metering.');
        }

        $page = $reader instanceof BudgetedDeltaMailboxReader
            ? $reader->changesPage($account, $cursor, $budget)
            : $reader->changesPage($account, $cursor);
        $maximum = config('mail-mirror.inventory_page_max_messages', 500);
        $maximum = is_int($maximum) && $maximum > 0 ? $maximum : 500;

        if (count($page->messages) + count($page->unavailableMessages) + count($page->deletions) + count($page->containers) > $maximum) {
            throw new InvalidArgumentException('The provider changes page exceeds the configured resource limit.');
        }

        $budget?->recordListedIds(count($page->messages) + count($page->unavailableMessages) + count($page->deletions));

        if ($page->accountProfile !== null
            && $page->accountProfile->providerAccountId !== $account->provider_account_id) {
            throw new InvalidArgumentException('The provider account profile does not belong to the supplied mail account.');
        }

        foreach ($page->messages as $change) {
            $this->assertReferenceBelongsTo($account, $change->reference);
        }

        foreach ($page->unavailableMessages as $message) {
            $this->assertReferenceBelongsTo($account, $message);
        }

        foreach ($page->deletions as $deletion) {
            if ($deletion->mailAccountId !== $account->getKey()) {
                throw new InvalidArgumentException('The provider deletion evidence does not belong to the supplied mail account.');
            }
        }

        foreach ($page->containers as $container) {
            if ($container->mailAccountId !== $account->getKey()) {
                throw new InvalidArgumentException('The provider container state does not belong to the supplied mail account.');
            }
        }

        return $page;
    }

    private function assertReferenceBelongsTo(MailAccount $account, MessageReference $message): void
    {
        if ($message->mailAccountId !== $account->getKey() || $message->driver !== $account->driver) {
            throw new InvalidArgumentException('The message reference does not belong to the supplied mail account.');
        }
    }
}
