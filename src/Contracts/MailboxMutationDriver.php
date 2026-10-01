<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Contracts;

use Jkudish\MailMirror\Enums\MailDriver;
use Jkudish\MailMirror\Exceptions\MailImportFailure;
use Jkudish\MailMirror\Exceptions\MailWriteFailure;
use Jkudish\MailMirror\Models\MailAccount;
use Jkudish\MailMirror\Write\MailboxChange;
use Jkudish\MailMirror\Write\MessageState;

/**
 * Provider primitives for reversible changes to one message's mailbox state.
 * Callers use MailWriteService, which owns the write switch, account
 * resolution, locking, idempotency, and confirmation.
 */
interface MailboxMutationDriver
{
    public function driver(): MailDriver;

    /**
     * Read the message's current state for $change without mutating anything.
     * The service calls this before the write and again to confirm it.
     *
     * @throws MailImportFailure when the provider request fails
     * @throws MailWriteFailure when the change cannot be resolved safely, such as an
     *                          ambiguous mailbox role or an unsupported container
     */
    public function messageState(MailAccount $account, string $providerMessageId, MailboxChange $change): MessageState;

    /**
     * Do any slow pre-send work now, before the service checks its lock deadline:
     * refresh credentials so they stay valid for at least $validForSeconds. After
     * this, applyChange() must do no I/O before its single write request.
     *
     * @throws MailImportFailure when the credential cannot be prepared
     */
    public function prepareWrite(MailAccount $account, int $validForSeconds): void;

    /**
     * Send exactly one provider write, without transport retries, that applies
     * $change to only the observed message. It does not confirm the result.
     *
     * @throws MailImportFailure only when a failure occurs before the write request is sent
     * @throws MailWriteFailure when the observed state cannot be changed safely, or for any
     *                          failure after the write request is sent; its writeSent is false
     *                          only when the provider definitively did not apply the write
     */
    public function applyChange(MailAccount $account, MessageState $observed, MailboxChange $change): void;
}
