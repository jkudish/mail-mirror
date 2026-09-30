<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Contracts;

use Jkudish\MailMirror\Enums\MailDriver;
use Jkudish\MailMirror\Exceptions\MailImportFailure;
use Jkudish\MailMirror\Exceptions\MailWriteFailure;
use Jkudish\MailMirror\Models\MailAccount;
use Jkudish\MailMirror\Write\DraftContent;
use Jkudish\MailMirror\Write\DraftRevision;

/**
 * Provider primitives for drafts built from caller-supplied RFC 5322 bytes.
 * Callers use MailWriteService, which owns the write switch, account
 * resolution, locking, revision checks, idempotency, and confirmation.
 *
 * Reads throw MailImportFailure when a provider request fails and
 * MailWriteFailure when provider semantics are ambiguous.
 */
interface DraftDriver
{
    public function driver(): MailDriver;

    /** Read one draft, or null when the provider has no such draft. */
    public function draft(MailAccount $account, string $draftId): ?DraftRevision;

    /** Resolve the draft whose provider message is $providerMessageId, including drafts started outside the consumer. */
    public function draftForMessage(MailAccount $account, string $providerMessageId): ?DraftRevision;

    /**
     * Every draft whose Message-ID header is exactly $messageId (without angle brackets).
     *
     * @return list<DraftRevision>
     */
    public function draftsWithMessageId(MailAccount $account, string $messageId): array;

    /** @throws MailImportFailure when the credential cannot be prepared */
    public function prepareWrite(MailAccount $account, int $validForSeconds): void;

    /**
     * Do slow, invisible pre-send work for $content before the lock deadline
     * check, such as uploading a JMAP blob. Returns a staged blob ID, or null.
     *
     * @throws MailImportFailure when staging fails; nothing visible was written
     */
    public function stageDraft(MailAccount $account, DraftContent $content): ?string;

    /**
     * Create one draft from exactly $content, without transport retries, and
     * return its draft ID. It does not confirm the result.
     *
     * @throws MailImportFailure only when a failure occurs before the write request is sent
     * @throws MailWriteFailure for any failure after the write request is sent; its writeSent is
     *                          false only when the provider definitively did not apply the write
     */
    public function createDraft(MailAccount $account, DraftContent $content, ?string $stagedBlobId, ?string $providerThreadId): string;

    /**
     * Replace the observed draft with exactly $content and return the draft ID
     * that now holds it. When $imported is a draft that already holds $content,
     * left by an interrupted earlier replace, only finish removing the observed
     * draft; a driver that replaces in place throws message_id_conflict.
     *
     * @throws MailImportFailure only when a failure occurs before the first write request is sent
     * @throws MailWriteFailure as for createDraft()
     */
    public function replaceDraft(MailAccount $account, DraftRevision $observed, DraftContent $content, ?string $stagedBlobId, ?DraftRevision $imported): string;

    /**
     * Delete exactly the observed draft, without transport retries.
     *
     * @throws MailImportFailure only when a failure occurs before the write request is sent
     * @throws MailWriteFailure as for createDraft()
     */
    public function deleteDraft(MailAccount $account, DraftRevision $observed): void;
}
