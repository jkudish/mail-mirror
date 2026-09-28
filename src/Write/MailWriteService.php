<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Write;

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

/**
 * Provider writes for one account-qualified message.
 *
 * Every write follows the same contract:
 * 1. Resolve the account through the full owner tuple before any provider request.
 * 2. Read provider state. If the desired state already holds and this package
 *    recorded an intent for the same target, return AlreadyApplied without writing.
 * 3. Record the intent under an account-namespaced cache key, then send one
 *    provider write with transport retries off.
 * 4. Re-read provider state and return Applied only when it confirms the write.
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

        $observed = $this->trashState($driver, $account, $target->providerMessageId);
        $intent = $this->intentKey($account, 'restore-from-trash', $target->providerMessageId);

        if (! $observed->inTrash) {
            if ($this->cache->get($intent) !== true) {
                throw new MailWriteFailure(MailWriteCode::NotInTrash);
            }

            return $this->result($account, $observed, MailWriteOutcome::AlreadyApplied);
        }

        $this->cache->put($intent, true, $this->intentTtl());

        try {
            $driver->restoreFromTrash($account, $observed);
        } catch (MailImportFailure $failure) {
            throw MailWriteFailure::fromProvider($failure, true);
        }

        try {
            $confirmed = $this->trashState($driver, $account, $target->providerMessageId);
        } catch (MailWriteFailure $failure) {
            throw new MailWriteFailure(MailWriteCode::Unconfirmed, true, $failure->providerCode, $failure);
        }

        if ($confirmed->inTrash) {
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

    private function intentKey(MailAccount $account, string $operation, string $providerMessageId): string
    {
        return sprintf('mail-mirror:account:%d:write:%s:%s', $account->id, $operation, hash('sha256', $providerMessageId));
    }

    private function intentTtl(): int
    {
        $seconds = config('mail-mirror.writes.intent_ttl_seconds', 86400);

        return is_int($seconds) && $seconds >= 60 && $seconds <= 2592000 ? $seconds : 86400;
    }
}
