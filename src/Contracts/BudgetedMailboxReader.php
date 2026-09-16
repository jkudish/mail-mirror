<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Contracts;

use Jkudish\MailMirror\Models\MailAccount;
use Jkudish\MailMirror\Read\InventoryPage;
use Jkudish\MailMirror\Read\MessageReference;
use Jkudish\MailMirror\Read\RetrievedMessage;
use Jkudish\MailMirror\Read\SyncWorkBudget;

interface BudgetedMailboxReader extends MailboxReader
{
    public function inventoryPage(MailAccount $account, ?string $cursor, ?SyncWorkBudget $budget = null): InventoryPage;

    public function retrieve(MailAccount $account, MessageReference $message, ?SyncWorkBudget $budget = null): RetrievedMessage;
}
