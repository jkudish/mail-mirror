<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Contracts;

use Jkudish\MailMirror\Enums\MailDriver;
use Jkudish\MailMirror\Exceptions\MailImportFailure;
use Jkudish\MailMirror\Exceptions\MailWriteFailure;
use Jkudish\MailMirror\Models\MailAccount;
use Jkudish\MailMirror\Write\TrashState;

/**
 * Provider primitives for restoring one message from Trash. Callers use
 * MailWriteService, which owns account resolution, idempotency, and
 * confirmation.
 */
interface TrashRestoreDriver
{
    public function driver(): MailDriver;

    /**
     * Read the message's current Trash membership without mutating anything.
     *
     * @throws MailImportFailure when the provider request fails
     * @throws MailWriteFailure when provider semantics are ambiguous
     */
    public function trashState(MailAccount $account, string $providerMessageId): TrashState;

    /**
     * Send exactly one provider write, without transport retries, that moves
     * only the observed message out of Trash. It does not confirm the result.
     *
     * @throws MailImportFailure only when a failure occurs before the write request is sent
     * @throws MailWriteFailure when the observed state cannot be restored safely, or for any
     *                          failure after the write request is sent; its writeSent is false
     *                          only when the provider definitively did not apply the write
     */
    public function restoreFromTrash(MailAccount $account, TrashState $observed): void;
}
