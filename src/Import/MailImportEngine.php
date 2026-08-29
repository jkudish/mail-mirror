<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Import;

use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Jkudish\MailMirror\Exceptions\AccountResourceMismatch;
use Jkudish\MailMirror\Exceptions\MailImportFailure;
use Jkudish\MailMirror\Exceptions\StaleCheckpoint;
use Jkudish\MailMirror\Models\MailAccount;
use Jkudish\MailMirror\Models\MailImportError;
use Jkudish\MailMirror\Models\MailInventoryItem;
use Jkudish\MailMirror\Models\MailMessage;
use Jkudish\MailMirror\Models\MailProviderDeletionEvidence;
use Jkudish\MailMirror\Models\MailReconciliationReport;
use Jkudish\MailMirror\Models\MailSyncCheckpoint;
use Jkudish\MailMirror\Read\InventoryPage;
use Jkudish\MailMirror\Read\MailReadService;
use Jkudish\MailMirror\Read\MessageReference;
use Jkudish\MailMirror\Read\RetrievedMessage;
use Jkudish\MailMirror\Storage\MailObjectStorage;
use Throwable;

final readonly class MailImportEngine
{
    public function __construct(
        private MailReadService $reads,
        private ReconciliationService $reconciliation,
        private MailObjectStorage $objects,
    ) {}

    public function syncAccount(
        int $mailAccountId,
        ?string $ownerType,
        string|int|null $ownerId,
        ?int $pageLimit = null,
        ?Closure $afterDurablePage = null,
    ): ?MailReconciliationReport {
        try {
            $account = MailAccount::query()->whereKey($mailAccountId)
                ->where('owner_type', $ownerType)
                ->where('owner_id', $ownerId === null ? null : (string) $ownerId)
                ->firstOrFail();
        } catch (ModelNotFoundException) {
            throw new AccountResourceMismatch('The requested account does not match the supplied owner tuple.');
        }

        return $this->sync($account, $pageLimit, $afterDurablePage);
    }

    public function sync(
        MailAccount $suppliedAccount,
        ?int $pageLimit = null,
        ?Closure $afterDurablePage = null,
    ): ?MailReconciliationReport {
        if ($pageLimit !== null && $pageLimit < 1) {
            throw new InvalidArgumentException('The page limit must be at least one.');
        }

        $account = $this->reacquire($suppliedAccount);
        $checkpoint = MailSyncCheckpoint::query()->forAccount($account)->first();

        if ($checkpoint !== null && $checkpoint->scan_completed_at !== null) {
            $report = MailReconciliationReport::query()->forAccount($account)->where('scan_id', $checkpoint->scan_id)->first();

            if ($report === null) {
                return $this->reconciliation->reconcile($account, $checkpoint->scan_id);
            }
        }

        $checkpoint = $this->checkpoint($account);
        $pages = 0;

        while ($pageLimit === null || $pages < $pageLimit) {
            $page = $this->reads->inventoryPage($account, $checkpoint->provider_cursor);
            $outcomes = $this->retrieve($account, $page);

            try {
                $checkpoint = $this->persistPage($account, $checkpoint, $page, $outcomes);
            } finally {
                $this->closeRawSources($outcomes);
            }
            $pages++;
            $afterDurablePage?->__invoke($checkpoint);

            if ($checkpoint->scan_completed_at !== null) {
                return $this->reconciliation->reconcile($account, $checkpoint->scan_id);
            }
        }

        return null;
    }

    private function reacquire(MailAccount $supplied): MailAccount
    {
        $account = MailAccount::query()->find($supplied->id);

        if ($account === null
            || $account->owner_type !== $supplied->owner_type
            || $account->owner_id !== $supplied->owner_id) {
            throw new AccountResourceMismatch('The supplied account identity or owner tuple does not match durable state.');
        }

        return $account;
    }

    private function checkpoint(MailAccount $account): MailSyncCheckpoint
    {
        return $account->getConnection()->transaction(function () use ($account): MailSyncCheckpoint {
            MailAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            $checkpoint = MailSyncCheckpoint::query()->forAccount($account)->lockForUpdate()->first();

            if ($checkpoint === null) {
                return MailSyncCheckpoint::query()->create([
                    'mail_account_id' => $account->id,
                    'scan_id' => (string) Str::uuid(),
                    'version' => 0,
                    'processed_count' => 0,
                    'scan_started_at' => now(),
                ]);
            }

            if ($checkpoint->scan_completed_at !== null) {
                $checkpoint->forceFill([
                    'scan_id' => (string) Str::uuid(),
                    'provider_cursor' => null,
                    'version' => $checkpoint->version + 1,
                    'processed_count' => 0,
                    'scan_started_at' => now(),
                    'scan_completed_at' => null,
                ])->save();
            }

            return $checkpoint;
        }, 3);
    }

    /** @return array<string, RetrievedMessage|MailImportFailure> */
    private function retrieve(MailAccount $account, InventoryPage $page): array
    {
        $outcomes = [];
        $maxAttempts = config('mail-mirror.import_max_attempts', 3);
        $maxAttempts = is_int($maxAttempts) && $maxAttempts > 0 ? $maxAttempts : 3;

        foreach ($page->messages as $reference) {
            $attempt = 0;

            do {
                $attempt++;

                try {
                    $message = $this->reads->retrieve($account, $reference);
                    $this->assertRetrieved($reference, $message);
                    $outcomes[$reference->providerMessageId] = $message;
                    break;
                } catch (MailImportFailure $failure) {
                    if (! $failure->retryable || $attempt >= $maxAttempts) {
                        $outcomes[$reference->providerMessageId] = new MailImportFailure(
                            $failure->stage,
                            $failure->safeCode,
                            $failure->safeSummary,
                            false,
                            $attempt,
                        );
                        break;
                    }
                } catch (Throwable) {
                    $outcomes[$reference->providerMessageId] = new MailImportFailure(
                        'retrieve',
                        'unexpected_failure',
                        'The provider message could not be retrieved safely.',
                    );
                    break;
                }
            } while (true);
        }

        return $outcomes;
    }

    private function assertRetrieved(MessageReference $expected, RetrievedMessage $message): void
    {
        $actual = $message->reference;

        if ($actual->mailAccountId !== $expected->mailAccountId
            || $actual->driver !== $expected->driver
            || $actual->providerMessageId !== $expected->providerMessageId) {
            throw new MailImportFailure('retrieve', 'malformed_payload', 'The provider returned mismatched message identity.');
        }
    }

    /** @param array<string, RetrievedMessage|MailImportFailure> $outcomes */
    private function persistPage(
        MailAccount $account,
        MailSyncCheckpoint $expected,
        InventoryPage $page,
        array $outcomes,
    ): MailSyncCheckpoint {
        return $account->getConnection()->transaction(function () use ($account, $expected, $page, $outcomes): MailSyncCheckpoint {
            $checkpoint = MailSyncCheckpoint::query()->forAccount($account)
                ->where('scan_id', $expected->scan_id)
                ->where('version', $expected->version)
                ->lockForUpdate()
                ->first();

            if ($checkpoint === null) {
                throw new StaleCheckpoint;
            }

            foreach ($page->messages as $reference) {
                MailInventoryItem::query()->updateOrCreate(
                    ['mail_account_id' => $account->id, 'provider_message_id' => $reference->providerMessageId],
                    [
                        'scan_id' => $checkpoint->scan_id,
                        'provider_thread_id' => $reference->providerThreadId,
                        'provider_metadata' => $reference->providerMetadata,
                    ],
                );

                $outcome = $outcomes[$reference->providerMessageId];

                if ($outcome instanceof MailImportFailure) {
                    $this->recordFailure($account, $reference->providerMessageId, $outcome);
                } else {
                    $message = MailMessage::query()->updateOrCreate(
                        ['mail_account_id' => $account->id, 'provider_message_id' => $reference->providerMessageId],
                        [
                            'internet_message_id' => $outcome->internetMessageId,
                            'subject' => $outcome->subject,
                            'provider_metadata' => $outcome->providerMetadata,
                        ],
                    );

                    if ($outcome->rawSource !== null) {
                        $this->objects->storeRaw(
                            $account,
                            $message,
                            $outcome->rawSource->stream,
                            $outcome->rawSource->providerObjectId,
                            $outcome->rawSource->mediaType,
                            $outcome->rawSource->providerMetadata,
                        );
                    }

                    MailImportError::query()->forAccount($account)
                        ->where('provider_message_id', $reference->providerMessageId)
                        ->whereNull('resolved_at')->whereNull('waived_at')
                        ->update(['resolved_at' => now()]);
                }
            }

            foreach ($page->deletions as $deletion) {
                MailProviderDeletionEvidence::query()->updateOrCreate(
                    ['mail_account_id' => $account->id, 'provider_message_id' => $deletion->providerMessageId],
                    [
                        'proof_code' => $deletion->proofCode,
                        'audit_reference' => $deletion->auditReference,
                        'provider_metadata' => $deletion->providerMetadata,
                    ],
                );
            }

            $checkpoint->forceFill([
                'provider_cursor' => $page->nextCursor,
                'version' => $checkpoint->version + 1,
                'processed_count' => $checkpoint->processed_count + count($page->messages),
                'scan_completed_at' => $page->complete ? now() : null,
            ])->save();

            return $checkpoint;
        }, 3);
    }

    private function recordFailure(MailAccount $account, string $providerMessageId, MailImportFailure $failure): void
    {
        $error = MailImportError::query()->forAccount($account)
            ->where('provider_message_id', $providerMessageId)
            ->where('stage', $failure->stage)
            ->first();

        if ($error === null) {
            MailImportError::query()->create([
                'mail_account_id' => $account->id,
                'provider_message_id' => $providerMessageId,
                'stage' => $failure->stage,
                'code' => $failure->safeCode,
                'summary' => $failure->safeSummary,
                'attempt_count' => $failure->attempts,
                'last_failed_at' => now(),
            ]);

            return;
        }

        $error->forceFill([
            'code' => $failure->safeCode,
            'summary' => $failure->safeSummary,
            'attempt_count' => $error->attempt_count + $failure->attempts,
            'last_failed_at' => now(),
            'resolved_at' => null,
        ])->save();
    }

    /** @param array<string, RetrievedMessage|MailImportFailure> $outcomes */
    private function closeRawSources(array $outcomes): void
    {
        foreach ($outcomes as $outcome) {
            if ($outcome instanceof RetrievedMessage
                && $outcome->rawSource !== null
                && is_resource($outcome->rawSource->stream)) {
                fclose($outcome->rawSource->stream);
            }
        }
    }
}
