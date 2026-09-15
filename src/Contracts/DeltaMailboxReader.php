<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Contracts;

use Jkudish\MailMirror\Models\MailAccount;
use Jkudish\MailMirror\Read\MailboxChangesPage;

interface DeltaMailboxReader extends MailboxReader
{
    public function changesPage(MailAccount $account, ?string $cursor): MailboxChangesPage;
}
