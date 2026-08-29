<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Contracts;

use Jkudish\MailMirror\Enums\MailDriver;
use Jkudish\MailMirror\Models\MailAccount;
use Jkudish\MailMirror\Read\InventoryPage;
use Jkudish\MailMirror\Read\MessageReference;
use Jkudish\MailMirror\Read\RetrievedMessage;

interface MailboxReader
{
    public function driver(): MailDriver;

    public function inventoryPage(MailAccount $account, ?string $cursor): InventoryPage;

    public function retrieve(MailAccount $account, MessageReference $message): RetrievedMessage;
}
