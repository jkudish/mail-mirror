<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Write;

use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Date;
use Jkudish\MailMirror\Contracts\DraftDriver;
use Jkudish\MailMirror\Contracts\MailboxMutationDriver;
use Jkudish\MailMirror\Contracts\ResumableDraftDriver;
use Jkudish\MailMirror\Contracts\SubmissionDriver;
use Jkudish\MailMirror\Enums\MailDriver;
use Jkudish\MailMirror\Enums\MailWriteCode;
use Jkudish\MailMirror\Enums\MailWriteOutcome;
use Jkudish\MailMirror\Enums\SubmissionOutcome;
use Jkudish\MailMirror\Exceptions\AccountResourceMismatch;
use Jkudish\MailMirror\Exceptions\MailImportFailure;
use Jkudish\MailMirror\Exceptions\MailWriteFailure;
use Jkudish\MailMirror\Jmap\FastmailJmapMailboxReader;
use Jkudish\MailMirror\Models\MailAccount;
use Jkudish\MailMirror\Models\MailIdentity;
use Jkudish\MailMirror\Read\MailDriverRegistry;
use LogicException;
use Throwable;

/**
 * Provider writes for one account-qualified message.
 *
 * Every write follows the same contract:
 * 0. Refuse with WritesDisabled, before anything else, unless
 *    mail-mirror.writes.enabled is true.
 * 1. Resolve the account through the full owner tuple before any provider request.
 * 2. Hold an account-namespaced cache lock for the target message; a busy lock
 *    fails with TargetBusy before any provider request.
 * 3. Read provider state. If the write's destination state already holds and a
 *    recorded intent shows this package may have applied it, return
 *    AlreadyApplied without writing.
 * 4. Send one provider write with transport retries off, and only while the lock
 *    has enough time left to cover the whole send step. Otherwise fail with
 *    LockExpired before sending, so a writer that later acquires the lock always
 *    reads state after this write has finished. Record the intent only when the
 *    write may have applied; clear it when the write definitely did not.
 * 5. Re-read provider state and return Applied only when it confirms the
 *    destination state. Any failure of that re-read is Unconfirmed.
 */
final readonly class MailWriteService
{
    public function __construct(
        private MailDriverRegistry $drivers,
        private Repository $cache,
    ) {}

    /**
     * Apply one reversible mailbox change to exactly one provider message.
     * An expected fingerprint must match a live read under the target lock,
     * before changing intents or preparing a write. A claim guard throws
     * MailWriteFailure(ClaimSuperseded) when the consumer's durable claim is
     * no longer current; it runs before any already-state result and again
     * after credential preparation, immediately before the send deadline check.
     * Either guard opts into AlreadySatisfied when no matching intent exists.
     *
     * @param  Closure(): void|null  $claimGuard
     *
     * @throws AccountResourceMismatch before any provider request
     * @throws MailWriteFailure
     */
    public function apply(MailWriteTarget $target, MailboxChange $change, ?string $expectedFingerprint = null, ?Closure $claimGuard = null): MailWriteResult
    {
        $this->assertWritesEnabled();
        $account = $this->account($target);
        $driver = $this->drivers->reader($account->driver);

        if (! $driver instanceof MailboxMutationDriver) {
            throw new MailWriteFailure(MailWriteCode::UnsupportedDriver);
        }

        $targetKey = $this->targetKey($account, $target->providerMessageId);

        return $this->withLock(
            $account,
            $targetKey.':write-lock',
            fn (\DateTimeInterface $sendBy, int $sendSeconds): MailWriteResult => $this->applyLocked(
                $driver,
                $account,
                $target->providerMessageId,
                $change,
                $targetKey,
                $sendBy,
                $sendSeconds,
                $expectedFingerprint,
                $claimGuard,
            ),
        );
    }

    /**
     * Read live provider metadata without writes or intent changes, even when
     * writes are disabled. The optional callback receives that same state under
     * the mutation target lock: commit consumer receipt reconciliation before
     * returning from it. Its second argument, assertFresh(), throws LockExpired
     * after the conservative lock deadline. Call it inside that transaction
     * after acquiring rows and immediately before returning, so a wait cannot
     * commit an expired observation. One-argument callbacks remain supported.
     * The lease is finite and does not stop other provider clients; bound the
     * final transaction commit within the reserved send-step margin.
     * JMAP unavailable mutation prerequisites are evidence with a false desired
     * state, not a read failure. apply() still requires strict prerequisites.
     *
     * @param  (Closure(MessageState, Closure(): void): void)|null  $callback
     *
     * @throws AccountResourceMismatch before any provider request
     * @throws MailWriteFailure
     */
    public function observe(MailWriteTarget $target, MailboxChange $change, ?Closure $callback = null): MessageState
    {
        $account = $this->account($target);
        $driver = $this->drivers->reader($account->driver);

        if (! $driver instanceof MailboxMutationDriver) {
            throw new MailWriteFailure(MailWriteCode::UnsupportedDriver);
        }

        return $this->withLock(
            $account,
            $this->targetKey($account, $target->providerMessageId).':write-lock',
            function (\DateTimeInterface $sendBy) use ($driver, $account, $target, $change, $callback): MessageState {
                $state = $this->messageState($driver, $account, $target->providerMessageId, $change, forObservation: true);
                $assertFresh = static function () use ($sendBy): void {
                    if (Date::now()->greaterThan($sendBy)) {
                        throw new MailWriteFailure(MailWriteCode::LockExpired);
                    }
                };
                $assertFresh();
                $callback?->__invoke($state, $assertFresh);

                return $state;
            },
        );
    }

    /**
     * Move one message out of provider Trash. Gmail restores the message's
     * prior labels; JMAP moves it from the Trash-role to the Inbox-role mailbox.
     *
     * @throws AccountResourceMismatch before any provider request
     * @throws MailWriteFailure
     */
    public function restoreFromTrash(MailWriteTarget $target): MailWriteResult
    {
        return $this->apply($target, MailboxChange::untrash());
    }

    /**
     * Run $write under the account-namespaced lock $lockKey. $write receives
     * the latest time it may send its single write and the send step length.
     *
     * @template TResult
     *
     * @param  Closure(\DateTimeInterface, int): TResult  $write
     * @param  int  $sendRequests  sequential write requests the send step may need
     * @return TResult
     */
    private function withLock(MailAccount $account, string $lockKey, #[\SensitiveParameter] Closure $write, int $sendRequests = 1): mixed
    {
        $store = $this->cache->getStore();

        if (! $store instanceof LockProvider) {
            throw new LogicException('Provider writes require a cache store that supports atomic locks.');
        }

        $sendSeconds = $this->sendStepSeconds($account->driver) * $sendRequests;
        $lockSeconds = $this->lockSeconds($sendSeconds);
        $lock = $store->lock($lockKey, $lockSeconds);

        if (! $lock->get()) {
            throw new MailWriteFailure(MailWriteCode::TargetBusy);
        }

        $sendBy = Date::now()->addSeconds($lockSeconds - $sendSeconds);

        try {
            return $write($sendBy, $sendSeconds);
        } finally {
            $lock->release();
        }
    }

    /** @param Closure(): void|null $claimGuard */
    private function applyLocked(
        MailboxMutationDriver $driver,
        MailAccount $account,
        string $providerMessageId,
        MailboxChange $change,
        string $targetKey,
        \DateTimeInterface $sendBy,
        int $sendSeconds,
        ?string $expectedFingerprint,
        ?Closure $claimGuard,
    ): MailWriteResult {
        $intent = $targetKey.':intent:'.$change->intentName();
        $observed = $this->messageState($driver, $account, $providerMessageId, $change);

        if ($expectedFingerprint !== null && ! hash_equals($expectedFingerprint, $observed->fingerprint())) {
            throw new MailWriteFailure(MailWriteCode::StaleState);
        }

        $claimGuard?->__invoke();

        if ($observed->desiredStateHolds) {
            // Only the last change this package may have applied to the message counts:
            // any later write, including the inverse change, replaced its intent.
            if ($this->cache->get($intent) !== $change->intentValue()) {
                if ($expectedFingerprint !== null || $claimGuard !== null) {
                    return $this->result($account, $observed, MailWriteOutcome::AlreadySatisfied);
                }

                throw new MailWriteFailure($change->action->unchangedCode());
            }

            return $this->result($account, $observed, MailWriteOutcome::AlreadyApplied);
        }

        // The destination state does not hold, so any earlier intent belongs to a finished cycle.
        $this->forgetIntents($targetKey);

        try {
            $driver->prepareWrite($account, $sendSeconds);
        } catch (MailImportFailure $failure) {
            throw MailWriteFailure::fromProvider($failure, false);
        }

        // Consumer work can wait on its database or supersede this claim. Both
        // it and credential preparation must finish before checking the deadline.
        $claimGuard?->__invoke();

        if (Date::now()->greaterThan($sendBy)) {
            throw new MailWriteFailure(MailWriteCode::LockExpired);
        }

        try {
            $driver->applyChange($account, $observed, $change);
        } catch (MailImportFailure $failure) {
            throw MailWriteFailure::fromProvider($failure, false);
        } catch (MailWriteFailure $failure) {
            if ($failure->writeSent) {
                $this->cache->put($intent, $change->intentValue(), $this->intentTtl());
            }

            throw $failure;
        }

        $this->cache->put($intent, $change->intentValue(), $this->intentTtl());

        try {
            $confirmed = $this->messageState($driver, $account, $providerMessageId, $change);
        } catch (Throwable $failure) {
            throw new MailWriteFailure(
                MailWriteCode::Unconfirmed,
                true,
                $failure instanceof MailWriteFailure ? $failure->providerCode : null,
                $failure,
            );
        }

        if (! $confirmed->desiredStateHolds) {
            throw new MailWriteFailure(MailWriteCode::Unconfirmed, true);
        }

        return $this->result($account, $confirmed, MailWriteOutcome::Applied);
    }

    /**
     * Read one provider draft. Reads need no write switch.
     *
     * @throws AccountResourceMismatch before any provider request
     * @throws MailWriteFailure draft_not_found or a provider failure
     */
    public function draft(DraftTarget $target): DraftRevision
    {
        $account = $this->account($target);

        return $this->readDraft($this->draftDriver($account), $account, $target->draftId)
            ?? throw new MailWriteFailure(MailWriteCode::DraftNotFound);
    }

    /**
     * Resolve a provider draft, including one started outside the consumer,
     * from its provider message ID.
     *
     * @throws AccountResourceMismatch before any provider request
     * @throws MailWriteFailure draft_not_found or a provider failure
     */
    public function resolveDraft(MailWriteTarget $target): DraftRevision
    {
        $account = $this->account($target);
        $driver = $this->draftDriver($account);

        try {
            $draft = $driver->draftForMessage($account, $target->providerMessageId);
        } catch (MailImportFailure $failure) {
            throw MailWriteFailure::fromProvider($failure, false);
        }

        if ($draft === null) {
            throw new MailWriteFailure(MailWriteCode::DraftNotFound);
        }

        return $this->ownedDraft($account, $draft);
    }

    /** Prepare Gmail metadata only. The consumer must persist checkpoint() before uploading MIME. */
    public function prepareDraftUpload(MailAccountTarget $target, string $operationKey, #[\SensitiveParameter] DraftContent $content, ?string $providerThreadId = null): DraftUploadSession
    {
        $this->assertWritesEnabled();
        $this->assertDraftSize($content);
        $account = $this->account($target);
        $driver = $this->draftDriver($account);

        if (! $driver instanceof ResumableDraftDriver) {
            throw new MailWriteFailure(MailWriteCode::UnsupportedDriver);
        }

        if (trim($operationKey) === '' || strlen($operationKey) > 255
            || ($providerThreadId !== null && (trim($providerThreadId) === '' || strlen($providerThreadId) > 255))) {
            throw new MailWriteFailure(MailWriteCode::InvalidDraftUpload);
        }

        return $this->withLock($account, $this->uploadKey($account, $operationKey),
            function (\DateTimeInterface $sendBy, int $sendSeconds) use ($driver, $account, $operationKey, $content, $providerThreadId): DraftUploadSession {
                $this->prepareDraftWrite($driver, $account, $sendSeconds, $sendBy, null);

                try {
                    return $driver->prepareDraftUpload($account, $operationKey, $content, $providerThreadId);
                } catch (MailImportFailure $failure) {
                    throw MailWriteFailure::fromProvider($failure, false);
                }
            });
    }

    /**
     * Create/recover Gmail only through the same caller-checkpointed $upload.
     * JMAP keeps its Message-ID/byte-identity recovery. Never prepare another
     * Gmail session because an earlier upload is unknown or expired.
     *
     * @throws AccountResourceMismatch before any provider request
     * @throws MailWriteFailure
     */
    public function createDraft(MailAccountTarget $target, #[\SensitiveParameter] DraftContent $content, ?string $providerThreadId = null, ?DraftUploadSession $upload = null): DraftWriteResult
    {
        $this->assertWritesEnabled();
        $this->assertDraftSize($content);
        $account = $this->account($target);
        $driver = $this->draftDriver($account);

        if ($driver instanceof ResumableDraftDriver) {
            if ($upload === null) {
                throw new MailWriteFailure(MailWriteCode::DraftUploadRequired);
            }

            $upload->assertMatches($target, $content, $providerThreadId);
        } elseif ($upload !== null) {
            throw new MailWriteFailure(MailWriteCode::InvalidDraftUpload);
        }

        return $this->withLock(
            $account,
            $this->messageIdKey($account, $content->messageId).':write-lock',
            function (\DateTimeInterface $sendBy, int $sendSeconds) use ($driver, $account, $content, $providerThreadId, $upload): DraftWriteResult {
                if ($driver instanceof ResumableDraftDriver) {
                    return $this->withLock($account, $this->uploadKey($account, $upload->operationKey),
                        function (\DateTimeInterface $innerSendBy, int $uploadSeconds) use ($driver, $account, $content, $upload, $sendBy): DraftWriteResult {
                            $this->prepareDraftWrite($driver, $account, $uploadSeconds, min($sendBy, $innerSendBy), null);

                            try {
                                $draftId = $driver->uploadDraft($account, $content, $upload);
                            } catch (MailImportFailure $failure) {
                                throw MailWriteFailure::fromProvider($failure, false);
                            }

                            $confirmed = $this->confirmDraft($driver, $account, $draftId, $content);

                            if ($upload->providerThreadId !== null && $confirmed->threadId !== $upload->providerThreadId) {
                                throw new MailWriteFailure(MailWriteCode::RevisionConflict, true, draftId: $draftId);
                            }

                            return $this->draftResult($account, MailWriteOutcome::Applied, $confirmed);
                        }, 2);
                }

                $existing = $this->draftsWithMessageId($driver, $account, $content->messageId);

                if ($existing !== []) {
                    return count($existing) === 1 && $driver->holdsContent($existing[0], $content)
                        ? $this->draftResult($account, MailWriteOutcome::AlreadyApplied, $existing[0])
                        : throw new MailWriteFailure(MailWriteCode::MessageIdConflict);
                }

                $staged = $this->prepareDraftWrite($driver, $account, $sendSeconds, $sendBy, $content);

                try {
                    $draftId = $driver->createDraft($account, $content, $staged, $providerThreadId);
                } catch (MailImportFailure $failure) {
                    throw MailWriteFailure::fromProvider($failure, false);
                }

                $confirmed = $this->confirmDraft($driver, $account, $draftId, $content);

                return $this->draftResult($account, MailWriteOutcome::Applied, $confirmed);
            },
            $driver instanceof ResumableDraftDriver ? 2 : 1,
        );
    }

    /**
     * Replace one draft with $content, only while the draft still has
     * $expectedRevision. A stale revision fails before any provider write.
     *
     * A retry after a possibly applied replace returns AlreadyApplied when the
     * new bytes are present. A JMAP replace interrupted between importing the
     * new email and destroying the old one is finished by the retry.
     *
     * @throws AccountResourceMismatch before any provider request
     * @throws MailWriteFailure
     */
    public function replaceDraft(DraftTarget $target, string $expectedRevision, DraftContent $content): DraftWriteResult
    {
        $this->assertWritesEnabled();
        $this->assertDraftSize($content);
        $account = $this->account($target);
        $driver = $this->draftDriver($account);
        $draftKey = $this->draftKey($account, $target->draftId);
        $intent = $draftKey.':intent';
        $intentValue = 'replace:'.$expectedRevision.':'.$content->sha256;

        // Lock order: the content's Message-ID, then the draft. Creates hold the same
        // Message-ID lock, so the duplicate check below and the write serialize with them.
        return $this->withLock(
            $account,
            $this->messageIdKey($account, $content->messageId).':write-lock',
            fn (\DateTimeInterface $outerSendBy): DraftWriteResult => $this->withLock(
                $account,
                $draftKey.':write-lock',
                fn (\DateTimeInterface $innerSendBy, int $sendSeconds): DraftWriteResult => $this->replaceLocked(
                    $driver, $account, $target, $expectedRevision, $content, $intent, $intentValue,
                    min($outerSendBy, $innerSendBy), $sendSeconds,
                ),
                2,
            ),
            2,
        );
    }

    private function replaceLocked(
        DraftDriver $driver,
        MailAccount $account,
        DraftTarget $target,
        string $expectedRevision,
        DraftContent $content,
        string $intent,
        string $intentValue,
        \DateTimeInterface $sendBy,
        int $sendSeconds,
    ): DraftWriteResult {
        $current = $this->readDraft($driver, $account, $target->draftId);

        if ($current === null || $current->revision !== $expectedRevision) {
            // Gmail rewrites Message-ID. Retry the known in-place draft instead
            // of searching the caller's old Message-ID; still require the exact
            // recorded attempt and meaningful content, never identity alone.
            if ($current !== null && $this->cache->get($intent) === $intentValue && $driver->holdsContent($current, $content)) {
                return $this->draftResult($account, MailWriteOutcome::AlreadyApplied, $current);
            }

            // Gmail updates only this ID in place. A different matching draft
            // cannot be evidence of this operation, even if this draft is gone.
            if ($account->driver === MailDriver::Gmail) {
                throw new MailWriteFailure($current === null ? MailWriteCode::DraftNotFound : MailWriteCode::StaleRevision);
            }

            $replacement = $this->cache->get($intent) === $intentValue
                ? $this->draftsWithMessageId($driver, $account, $content->messageId)
                : [];

            if (count($replacement) === 1 && $driver->holdsContent($replacement[0], $content)
                && ($current === null || $current->draftId === $replacement[0]->draftId)) {
                return $this->draftResult($account, MailWriteOutcome::AlreadyApplied, $replacement[0]);
            }

            throw new MailWriteFailure($current === null ? MailWriteCode::DraftNotFound : MailWriteCode::StaleRevision);
        }

        $others = array_values(array_filter(
            $this->draftsWithMessageId($driver, $account, $content->messageId),
            fn (DraftRevision $draft): bool => $draft->draftId !== $current->draftId,
        ));
        // An identical draft is what an interrupted replace leaves; anything else is a conflict.
        $imported = count($others) === 1 && $driver->holdsContent($others[0], $content) ? $others[0] : null;

        if ($others !== [] && $imported === null) {
            throw new MailWriteFailure(MailWriteCode::MessageIdConflict);
        }

        $this->cache->forget($intent);
        $staged = $this->prepareDraftWrite($driver, $account, $sendSeconds, $sendBy, $imported === null ? $content : null);

        try {
            $draftId = $driver->replaceDraft($account, $current, $content, $staged, $imported);
        } catch (MailImportFailure $failure) {
            throw MailWriteFailure::fromProvider($failure, false);
        } catch (MailWriteFailure $failure) {
            if ($failure->writeSent) {
                $this->cache->put($intent, $intentValue, $this->intentTtl());
            }

            throw $failure;
        }

        $this->cache->put($intent, $intentValue, $this->intentTtl());
        $confirmed = $this->confirmDraft($driver, $account, $draftId, $content);

        if ($draftId !== $current->draftId && $this->confirmingRead(fn () => $driver->draft($account, $current->draftId)) !== null) {
            throw new MailWriteFailure(MailWriteCode::Unconfirmed, true);
        }

        return $this->draftResult($account, MailWriteOutcome::Applied, $confirmed);
    }

    /**
     * Delete one draft, only while it still has $expectedRevision.
     *
     * @throws AccountResourceMismatch before any provider request
     * @throws MailWriteFailure
     */
    public function deleteDraft(DraftTarget $target, string $expectedRevision): DraftWriteResult
    {
        $this->assertWritesEnabled();
        $account = $this->account($target);
        $driver = $this->draftDriver($account);
        $draftKey = $this->draftKey($account, $target->draftId);
        $intent = $draftKey.':intent';
        $intentValue = 'delete:'.$expectedRevision;

        return $this->withLock(
            $account,
            $draftKey.':write-lock',
            function (\DateTimeInterface $sendBy, int $sendSeconds) use ($driver, $account, $target, $expectedRevision, $intent, $intentValue): DraftWriteResult {
                $current = $this->readDraft($driver, $account, $target->draftId);

                if ($current === null) {
                    return $this->cache->get($intent) === $intentValue
                        ? $this->draftResult($account, MailWriteOutcome::AlreadyApplied, null)
                        : throw new MailWriteFailure(MailWriteCode::DraftNotFound);
                }

                if ($current->revision !== $expectedRevision) {
                    throw new MailWriteFailure(MailWriteCode::StaleRevision);
                }

                $this->cache->forget($intent);
                $this->prepareDraftWrite($driver, $account, $sendSeconds, $sendBy, null);

                try {
                    $driver->deleteDraft($account, $current);
                } catch (MailImportFailure $failure) {
                    throw MailWriteFailure::fromProvider($failure, false);
                } catch (MailWriteFailure $failure) {
                    if ($failure->writeSent) {
                        $this->cache->put($intent, $intentValue, $this->intentTtl());
                    }

                    throw $failure;
                }

                $this->cache->put($intent, $intentValue, $this->intentTtl());

                if ($this->confirmingRead(fn () => $driver->draft($account, $current->draftId)) !== null) {
                    throw new MailWriteFailure(MailWriteCode::Unconfirmed, true);
                }

                return $this->draftResult($account, MailWriteOutcome::Applied, null);
            },
        );
    }

    /**
     * Send one draft exactly once, only while it still has $expectedRevision
     * and its From address matches exactly one mirrored provider identity.
     *
     * Gmail sends the bytes approved at $expectedRevision, not the draft's
     * current content. A submit that may have been sent records an intent;
     * until reconcileSubmission() answers submitted, every later submit of
     * that draft fails submission_unknown without any provider request. If that
     * cache intent is lost, a provider submission linked to the draft's ID still
     * refuses the send. MailMirror never re-sends on its own. Durable
     * at-most-once and any human-authorized resend belong to the consumer.
     *
     * A consumer claim guard throws MailWriteFailure(ClaimSuperseded) when its
     * durable authorization is no longer current. It runs under the draft lock
     * before any provider request or intent handling, then after credential
     * preparation immediately before the send deadline check. It cannot fence
     * an arbitrary process pause after that final check.
     *
     * @param  Closure(): void|null  $claimGuard
     *
     * @throws AccountResourceMismatch before any provider request
     * @throws MailWriteFailure
     */
    public function submit(DraftTarget $target, string $expectedRevision, ?Closure $claimGuard = null): SubmissionResult
    {
        $this->assertWritesEnabled();
        $account = $this->account($target);
        $driver = $this->submissionDriver($account);
        $draftKey = $this->draftKey($account, $target->draftId);
        $intent = $draftKey.':intent:submit';

        return $this->withLock(
            $account,
            $draftKey.':write-lock',
            function (\DateTimeInterface $sendBy, int $sendSeconds) use ($driver, $account, $target, $expectedRevision, $intent, $claimGuard): SubmissionResult {
                $claimGuard?->__invoke();

                if ($this->cache->get($intent) !== null) {
                    throw new MailWriteFailure(MailWriteCode::SubmissionUnknown);
                }

                $current = $this->readDraft($driver, $account, $target->draftId)
                    ?? throw new MailWriteFailure(MailWriteCode::DraftNotFound);

                if ($current->revision !== $expectedRevision) {
                    throw new MailWriteFailure(MailWriteCode::StaleRevision);
                }

                $identity = $this->identityFor($account, $current);

                // The cache intent can be evicted. A submission the provider links to this
                // draft means an earlier send was accepted even if the draft never left
                // Drafts (JMAP onSuccessUpdateEmail not applied), so never send again.
                try {
                    $earlier = $driver->findSubmission($account, $current->draftId, $current->messageId);
                } catch (MailImportFailure $failure) {
                    throw MailWriteFailure::fromProvider($failure, false);
                }

                if ($earlier !== null && $earlier->matchedBy === 'provider_id') {
                    throw new MailWriteFailure(MailWriteCode::SubmissionUnknown);
                }

                $this->prepareDraftWrite($driver, $account, $sendSeconds, $sendBy, null, $claimGuard);

                try {
                    $sentMessageId = $driver->submitDraft($account, $current, $identity);
                } catch (MailImportFailure $failure) {
                    throw MailWriteFailure::fromProvider($failure, false);
                } catch (MailWriteFailure $failure) {
                    if ($failure->writeSent) {
                        $this->cache->put($intent, $current->revision, $this->intentTtl());
                    }

                    throw $failure;
                }

                $this->cache->put($intent, $current->revision, $this->intentTtl());
                $sent = $this->confirmingRead(fn () => $driver->sentMessage($account, $sentMessageId));

                if (! $sent instanceof SubmissionResult || $sent->outcome !== SubmissionOutcome::Submitted
                    || $sent->mailAccountId !== $account->id || $sent->driver !== $account->driver) {
                    throw new MailWriteFailure(MailWriteCode::Unconfirmed, true);
                }

                // Confirmed sent: the draft no longer exists, so nothing can submit it again.
                $this->cache->forget($intent);

                return $sent;
            },
        );
    }

    /**
     * Answer from provider reads only whether draft $target, read earlier at
     * $expectedRevision with Message-ID $messageId, was submitted.
     *
     * - Evidence linked to the draft's own provider ID means submitted.
     * - A Message-ID match alone means submitted only when the draft is gone.
     * - Everything else is unknown. Absence is never proof of not sending:
     *   providers may destroy submission records, and a timed-out request may
     *   still be in flight. A human-authorized resend belongs to the consumer.
     *
     * Submitted clears a pending submit intent; unknown never does.
     *
     * @throws AccountResourceMismatch before any provider request
     * @throws MailWriteFailure
     */
    public function reconcileSubmission(DraftTarget $target, string $expectedRevision, string $messageId): SubmissionResult
    {
        $account = $this->account($target);
        $driver = $this->submissionDriver($account);
        $draftKey = $this->draftKey($account, $target->draftId);

        return $this->withLock(
            $account,
            $draftKey.':write-lock',
            function () use ($driver, $account, $target, $expectedRevision, $messageId, $draftKey): SubmissionResult {
                $draft = $this->readDraft($driver, $account, $target->draftId);
                $draftUnsent = $draft !== null && $draft->revision === $expectedRevision;

                try {
                    $found = $driver->findSubmission($account, $target->draftId, $messageId);
                } catch (MailImportFailure $failure) {
                    throw MailWriteFailure::fromProvider($failure, false);
                } catch (MailWriteFailure $failure) {
                    // A search that stopped at its bound proves nothing either way.
                    if ($failure->safeCode !== MailWriteCode::SubmissionUnknown) {
                        throw $failure;
                    }

                    $found = null;
                }

                if ($found !== null && ($found->mailAccountId !== $account->id || $found->driver !== $account->driver
                    || $found->outcome !== SubmissionOutcome::Submitted)) {
                    throw new AccountResourceMismatch('The provider submission does not belong to the supplied account.');
                }

                // A Message-ID match alone proves a send only once the draft is gone:
                // a changed draft that still exists may never have been sent.
                if ($found !== null && ($found->matchedBy === 'provider_id' || $draft === null)) {
                    $this->cache->forget($draftKey.':intent:submit');

                    return $found;
                }

                $outcome = SubmissionOutcome::Unknown;

                return new SubmissionResult($account->id, $account->driver, $outcome, null, null, $found?->matchedBy, [
                    'draft_exists' => $draft !== null,
                    'draft_at_expected_revision' => $draftUnsent,
                ]);
            },
        );
    }

    private function submissionDriver(MailAccount $account): SubmissionDriver
    {
        $driver = $this->drivers->reader($account->driver);

        return $driver instanceof SubmissionDriver ? $driver : throw new MailWriteFailure(MailWriteCode::UnsupportedDriver);
    }

    /** The one mirrored identity whose address is the draft's single From address. */
    private function identityFor(MailAccount $account, DraftRevision $draft): MailIdentity
    {
        $from = $draft->fromAddress;
        $matches = $from === null ? [] : MailIdentity::query()
            ->forAccount($account)
            ->get()
            ->filter(fn (MailIdentity $identity): bool => $identity->email_address !== null
                && strtolower($identity->email_address) === $from)
            ->values()
            ->all();

        return count($matches) === 1 ? $matches[0] : throw new MailWriteFailure(MailWriteCode::IdentityMismatch);
    }

    private function draftDriver(MailAccount $account): DraftDriver
    {
        $driver = $this->drivers->reader($account->driver);

        return $driver instanceof DraftDriver ? $driver : throw new MailWriteFailure(MailWriteCode::UnsupportedDriver);
    }

    private function readDraft(DraftDriver $driver, MailAccount $account, string $draftId): ?DraftRevision
    {
        try {
            $draft = $driver->draft($account, $draftId);
        } catch (MailImportFailure $failure) {
            throw MailWriteFailure::fromProvider($failure, false);
        }

        if ($draft !== null && $draft->draftId !== $draftId) {
            throw new AccountResourceMismatch('The provider draft does not belong to the supplied target.');
        }

        return $draft === null ? null : $this->ownedDraft($account, $draft);
    }

    /** @return list<DraftRevision> */
    private function draftsWithMessageId(DraftDriver $driver, MailAccount $account, string $messageId): array
    {
        try {
            $drafts = $driver->draftsWithMessageId($account, $messageId);
        } catch (MailImportFailure $failure) {
            throw MailWriteFailure::fromProvider($failure, false);
        }

        foreach ($drafts as $draft) {
            $this->ownedDraft($account, $draft);
        }

        return $drafts;
    }

    private function ownedDraft(MailAccount $account, DraftRevision $draft): DraftRevision
    {
        if ($draft->mailAccountId !== $account->id || $draft->driver !== $account->driver) {
            throw new AccountResourceMismatch('The provider draft does not belong to the supplied account.');
        }

        return $draft;
    }

    /**
     * Prepare credentials, stage content, then check the claim and lock deadline;
     * nothing visible is written.
     *
     * @param  Closure(): void|null  $claimGuard
     */
    private function prepareDraftWrite(#[\SensitiveParameter] DraftDriver $driver, MailAccount $account, int $sendSeconds, \DateTimeInterface $sendBy, #[\SensitiveParameter] ?DraftContent $content, ?Closure $claimGuard = null): ?string
    {
        try {
            $driver->prepareWrite($account, $sendSeconds);
            $staged = $content === null ? null : $driver->stageDraft($account, $content);
        } catch (MailImportFailure $failure) {
            throw MailWriteFailure::fromProvider($failure, false);
        }

        $claimGuard?->__invoke();

        if (Date::now()->greaterThan($sendBy)) {
            throw new MailWriteFailure(MailWriteCode::LockExpired);
        }

        return $staged;
    }

    /**
     * Re-read the written draft. Other bytes than the ones written mean the
     * draft changed during the write, for example a Gmail edit racing the
     * unconditional update: a conflict, never Applied.
     */
    private function confirmDraft(#[\SensitiveParameter] DraftDriver $driver, MailAccount $account, string $draftId, #[\SensitiveParameter] DraftContent $content): DraftRevision
    {
        try {
            $confirmed = $this->confirmingRead(fn () => $driver->draft($account, $draftId));

            if (! $confirmed instanceof DraftRevision || $confirmed->draftId !== $draftId
                || $confirmed->mailAccountId !== $account->id || $confirmed->driver !== $account->driver) {
                throw new MailWriteFailure(MailWriteCode::Unconfirmed, true);
            }

            if (! $driver->holdsContent($confirmed, $content)) {
                throw new MailWriteFailure(MailWriteCode::RevisionConflict, true);
            }

            return $confirmed;
        } catch (MailWriteFailure $failure) {
            throw new MailWriteFailure($failure->safeCode, $failure->writeSent, $failure->providerCode, $failure, $draftId);
        }
    }

    /**
     * Run a read after a sent write; any failure of it is Unconfirmed.
     *
     * @template TRead
     *
     * @param  Closure(): TRead  $read
     * @return TRead
     */
    private function confirmingRead(#[\SensitiveParameter] Closure $read): mixed
    {
        try {
            return $read();
        } catch (Throwable $failure) {
            throw new MailWriteFailure(
                MailWriteCode::Unconfirmed,
                true,
                $failure instanceof MailWriteFailure ? $failure->providerCode : ($failure instanceof MailImportFailure ? $failure->safeCode : null),
                $failure,
            );
        }
    }

    private function draftResult(MailAccount $account, MailWriteOutcome $outcome, ?DraftRevision $draft): DraftWriteResult
    {
        return new DraftWriteResult($account->id, $account->driver, $outcome, $draft);
    }

    private function assertDraftSize(#[\SensitiveParameter] DraftContent $content): void
    {
        $maximum = config('mail-mirror.writes.max_draft_bytes', 26214400);
        $maximum = is_int($maximum) && $maximum >= 1024 && $maximum <= 52428800 ? $maximum : 26214400;

        if ($content->size() > $maximum) {
            throw new MailWriteFailure(MailWriteCode::DraftTooLarge);
        }
    }

    /** Writes to one draft share this key; JMAP replaces change the ID, which revisions then catch. */
    private function draftKey(MailAccount $account, string $draftId): string
    {
        return sprintf('mail-mirror:account:%d:draft:%s', $account->id, hash('sha256', $draftId));
    }

    private function uploadKey(MailAccount $account, string $operationKey): string
    {
        return sprintf('mail-mirror:account:%d:draft-upload:%s:write-lock', $account->id, hash('sha256', $operationKey));
    }

    /** Creates, and later submissions, of one caller Message-ID share this key. */
    private function messageIdKey(MailAccount $account, string $messageId): string
    {
        return sprintf('mail-mirror:account:%d:message-id:%s', $account->id, hash('sha256', $messageId));
    }

    private function messageState(MailboxMutationDriver $driver, MailAccount $account, string $providerMessageId, MailboxChange $change, bool $forObservation = false): MessageState
    {
        try {
            $state = $forObservation && $driver instanceof FastmailJmapMailboxReader
                ? $driver->messageState($account, $providerMessageId, $change, forObservation: true)
                : $driver->messageState($account, $providerMessageId, $change);
        } catch (MailImportFailure $failure) {
            throw MailWriteFailure::fromProvider($failure, false);
        }

        if ($state->mailAccountId !== $account->id || $state->driver !== $account->driver
            || $state->providerMessageId !== $providerMessageId) {
            throw new AccountResourceMismatch('The provider state does not belong to the supplied write target.');
        }

        return $state;
    }

    private function result(MailAccount $account, MessageState $state, MailWriteOutcome $outcome): MailWriteResult
    {
        return new MailWriteResult($account->id, $account->driver, $state->providerMessageId, $outcome, $state->providerEvidence);
    }

    /** The one package write switch, checked before any database or provider access. */
    private function assertWritesEnabled(): void
    {
        if (config('mail-mirror.writes.enabled') !== true) {
            throw new MailWriteFailure(MailWriteCode::WritesDisabled);
        }
    }

    private function account(MailWriteTarget|DraftTarget|MailAccountTarget $target): MailAccount
    {
        try {
            return MailAccount::query()->whereKey($target->mailAccountId)
                ->where('owner_type', $target->ownerType)
                ->where('owner_id', $target->ownerId === null ? null : (string) $target->ownerId)
                ->firstOrFail();
        } catch (ModelNotFoundException) {
            throw new AccountResourceMismatch('The requested account does not match the supplied owner tuple.');
        }
    }

    /** Every write to one provider message shares this account-namespaced key. */
    private function targetKey(MailAccount $account, string $providerMessageId): string
    {
        return sprintf('mail-mirror:account:%d:message:%s', $account->id, hash('sha256', $providerMessageId));
    }

    /** Clear the message's single intent slot, including the legacy restore key. */
    private function forgetIntents(string $targetKey): void
    {
        foreach (MailboxChange::intentNames() as $name) {
            $this->cache->forget($targetKey.':intent:'.$name);
        }
    }

    private function intentTtl(): int
    {
        $seconds = config('mail-mirror.writes.intent_ttl_seconds', 86400);

        return is_int($seconds) && $seconds >= 60 && $seconds <= 2592000 ? $seconds : 86400;
    }

    /**
     * Worst-case time from the pre-send deadline check until the write request
     * has finished. prepareWrite() does all slow work first, so the step is the
     * single write request, bounded by the driver's timeout, plus a five-second
     * margin. Work after a rejected write (such as a 401 token refresh) does not
     * count: that write was not applied, so a later writer cannot duplicate it.
     */
    private function sendStepSeconds(MailDriver $driver): int
    {
        return $this->timeout(match ($driver) {
            MailDriver::Gmail => 'mail-mirror.gmail.timeout_seconds',
            MailDriver::Jmap => 'mail-mirror.jmap.timeout_seconds',
        }) + 5;
    }

    private function timeout(string $key): int
    {
        $seconds = config($key, 30);

        return is_int($seconds) && $seconds >= 1 && $seconds <= 120 ? $seconds : 30;
    }

    /** The configured lock, raised when needed so it always outlasts the send step. */
    private function lockSeconds(int $sendSeconds): int
    {
        $seconds = config('mail-mirror.writes.lock_seconds', 300);
        $seconds = is_int($seconds) && $seconds >= 30 && $seconds <= 3600 ? $seconds : 300;

        return max($seconds, 2 * $sendSeconds);
    }
}
