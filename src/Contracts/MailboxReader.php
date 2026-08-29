<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Contracts;

use Jkudish\MailMirror\Enums\MailDriver;
use Jkudish\MailMirror\Models\MailAccount;
use Jkudish\MailMirror\Read\MessageReference;
use Jkudish\MailMirror\Read\RetrievedMessage;

interface MailboxReader
{
    public function driver(): MailDriver;

    /** @return iterable<MessageReference> */
    public function inventory(MailAccount $account): iterable;

    public function retrieve(MailAccount $account, MessageReference $message): RetrievedMessage;
}
