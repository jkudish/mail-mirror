<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Contracts;

use Jkudish\MailMirror\Models\MailAccount;
use Jkudish\MailMirror\Read\MailboxChangesPage;
use Jkudish\MailMirror\Read\SyncWorkBudget;

interface BudgetedDeltaMailboxReader extends BudgetedMailboxReader, DeltaMailboxReader
{
    public function changesPage(MailAccount $account, ?string $cursor, ?SyncWorkBudget $budget = null): MailboxChangesPage;
}
