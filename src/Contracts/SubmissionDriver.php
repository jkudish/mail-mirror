<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Contracts;

use Jkudish\MailMirror\Exceptions\MailImportFailure;
use Jkudish\MailMirror\Exceptions\MailWriteFailure;
use Jkudish\MailMirror\Models\MailAccount;
use Jkudish\MailMirror\Models\MailIdentity;
use Jkudish\MailMirror\Write\DraftRevision;
use Jkudish\MailMirror\Write\SubmissionResult;

/**
 * Provider primitives for sending one draft exactly once and for answering,
 * from provider reads only, whether an uncertain send happened. Callers use
 * MailWriteService, which owns the write switch, account resolution, locking,
 * revision and identity checks, and the rule that a possibly sent draft is
 * never submitted again before reconciliation.
 */
interface SubmissionDriver extends DraftDriver
{
    /**
     * Send exactly one submission of the observed draft as $identity, without
     * transport retries, and return the provider message ID of the sent copy.
     * It does no I/O before that request and does not confirm the result.
     *
     * @throws MailImportFailure only when a failure occurs before the request is sent
     * @throws MailWriteFailure for any failure after the request is sent; its writeSent is false
     *                          only when the provider definitively did not accept the submission
     */
    public function submitDraft(MailAccount $account, DraftRevision $observed, MailIdentity $identity): string;

    /**
     * Re-read a sent message and return Submitted with its provider message and
     * thread IDs, or null when provider state does not show it as sent.
     */
    public function sentMessage(MailAccount $account, string $providerMessageId): ?SubmissionResult;

    /**
     * Look for evidence that draft $draftId, whose Message-ID is $messageId, was
     * submitted: a Submitted result naming how it matched, or null. Reads only.
     * A null $messageId limits the search to evidence linked to the draft ID.
     *
     * @throws MailImportFailure when a provider read fails
     * @throws MailWriteFailure submission_unknown when the search stops at its bound
     *                          before it is exhausted; that is never absence
     */
    public function findSubmission(MailAccount $account, string $draftId, ?string $messageId): ?SubmissionResult;
}
