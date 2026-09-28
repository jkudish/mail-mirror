<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Write;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Jkudish\MailMirror\Contracts\TrashRestoreDriver;
use Jkudish\MailMirror\Enums\MailWriteCode;
use Jkudish\MailMirror\Enums\MailWriteOutcome;
use Jkudish\MailMirror\Exceptions\AccountResourceMismatch;
use Jkudish\MailMirror\Exceptions\MailImportFailure;
use Jkudish\MailMirror\Exceptions\MailWriteFailure;
use Jkudish\MailMirror\Models\MailAccount;
use Jkudish\MailMirror\Read\MailDriverRegistry;
use LogicException;
use Throwable;

/**
 * Provider writes for one account-qualified message.
 *
 * Every write follows the same contract:
 * 1. Resolve the account through the full owner tuple before any provider request.
 * 2. Hold an account-namespaced cache lock for the target message; a busy lock
 *    fails with TargetBusy before any provider request.
 * 3. Read provider state. If the write's destination state already holds and a
 *    recorded intent shows this package may have applied it, return
 *    AlreadyApplied without writing.
 * 4. Send one provider write with transport retries off. Record the intent only
 *    when the write may have applied; clear it when the write definitely did not.
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
     * Move one message out of provider Trash. Gmail restores the message's
     * prior labels; JMAP moves it from the Trash-role to the Inbox-role mailbox.
     *
     * @throws AccountResourceMismatch before any provider request
     * @throws MailWriteFailure
     */
    public function restoreFromTrash(MailWriteTarget $target): MailWriteResult
    {
        $account = $this->account($target);
        $driver = $this->drivers->reader($account->driver);

        if (! $driver instanceof TrashRestoreDriver) {
            throw new MailWriteFailure(MailWriteCode::UnsupportedDriver);
        }

        $store = $this->cache->getStore();

        if (! $store instanceof LockProvider) {
            throw new LogicException('Provider writes require a cache store that supports atomic locks.');
        }

        $targetKey = $this->targetKey($account, $target->providerMessageId);
        $lock = $store->lock($targetKey.':write-lock', $this->lockSeconds());

        if (! $lock->get()) {
            throw new MailWriteFailure(MailWriteCode::TargetBusy);
        }

        try {
            return $this->restoreLocked($driver, $account, $target->providerMessageId, $targetKey.':intent:restore-from-trash');
        } finally {
            $lock->release();
        }
    }

    private function restoreLocked(
        TrashRestoreDriver $driver,
        MailAccount $account,
        string $providerMessageId,
        string $intent,
    ): MailWriteResult {
        $observed = $this->trashState($driver, $account, $providerMessageId);

        if (! $observed->inTrash) {
            if (! $observed->restored || $this->cache->get($intent) !== true) {
                throw new MailWriteFailure(MailWriteCode::NotInTrash);
            }

            return $this->result($account, $observed, MailWriteOutcome::AlreadyApplied);
        }

        // The message is in Trash, so any earlier intent belongs to a finished cycle.
        $this->cache->forget($intent);

        try {
            $driver->restoreFromTrash($account, $observed);
        } catch (MailImportFailure $failure) {
            throw MailWriteFailure::fromProvider($failure, false);
        } catch (MailWriteFailure $failure) {
            if ($failure->writeSent) {
                $this->cache->put($intent, true, $this->intentTtl());
            }

            throw $failure;
        }

        $this->cache->put($intent, true, $this->intentTtl());

        try {
            $confirmed = $this->trashState($driver, $account, $providerMessageId);
        } catch (Throwable $failure) {
            throw new MailWriteFailure(
                MailWriteCode::Unconfirmed,
                true,
                $failure instanceof MailWriteFailure ? $failure->providerCode : null,
                $failure,
            );
        }

        if (! $confirmed->restored) {
            throw new MailWriteFailure(MailWriteCode::Unconfirmed, true);
        }

        return $this->result($account, $confirmed, MailWriteOutcome::Applied);
    }

    private function trashState(TrashRestoreDriver $driver, MailAccount $account, string $providerMessageId): TrashState
    {
        try {
            $state = $driver->trashState($account, $providerMessageId);
        } catch (MailImportFailure $failure) {
            throw MailWriteFailure::fromProvider($failure, false);
        }

        if ($state->mailAccountId !== $account->id || $state->driver !== $account->driver
            || $state->providerMessageId !== $providerMessageId) {
            throw new AccountResourceMismatch('The provider state does not belong to the supplied write target.');
        }

        return $state;
    }

    private function result(MailAccount $account, TrashState $state, MailWriteOutcome $outcome): MailWriteResult
    {
        return new MailWriteResult($account->id, $account->driver, $state->providerMessageId, $outcome, $state->providerEvidence);
    }

    private function account(MailWriteTarget $target): MailAccount
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

    private function intentTtl(): int
    {
        $seconds = config('mail-mirror.writes.intent_ttl_seconds', 86400);

        return is_int($seconds) && $seconds >= 60 && $seconds <= 2592000 ? $seconds : 86400;
    }

    private function lockSeconds(): int
    {
        $seconds = config('mail-mirror.writes.lock_seconds', 300);

        return is_int($seconds) && $seconds >= 30 && $seconds <= 3600 ? $seconds : 300;
    }
}
