<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Import;

use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Jkudish\MailMirror\Enums\MailImportCode;
use Jkudish\MailMirror\Enums\MailImportStage;
use Jkudish\MailMirror\Enums\MailSourceChangeKind;
use Jkudish\MailMirror\Events\MailContainerStateChanged;
use Jkudish\MailMirror\Events\ProviderDeletionStateChanged;
use Jkudish\MailMirror\Exceptions\AccountResourceMismatch;
use Jkudish\MailMirror\Exceptions\DeltaRepairRequired;
use Jkudish\MailMirror\Exceptions\InventoryRestartRequired;
use Jkudish\MailMirror\Exceptions\MailImportFailure;
use Jkudish\MailMirror\Exceptions\StaleCheckpoint;
use Jkudish\MailMirror\Exceptions\SyncBudgetExhausted;
use Jkudish\MailMirror\Models\MailAccount;
use Jkudish\MailMirror\Models\MailAddress;
use Jkudish\MailMirror\Models\MailAttachment;
use Jkudish\MailMirror\Models\MailContainer;
use Jkudish\MailMirror\Models\MailDeltaCheckpoint;
use Jkudish\MailMirror\Models\MailDeltaPendingMessage;
use Jkudish\MailMirror\Models\MailIdentity;
use Jkudish\MailMirror\Models\MailImportError;
use Jkudish\MailMirror\Models\MailInventoryItem;
use Jkudish\MailMirror\Models\MailLocalMessagePurge;
use Jkudish\MailMirror\Models\MailMessage;
use Jkudish\MailMirror\Models\MailMessageContainerMembership;
use Jkudish\MailMirror\Models\MailMessageHeader;
use Jkudish\MailMirror\Models\MailMessageParticipant;
use Jkudish\MailMirror\Models\MailProviderDeletionEvidence;
use Jkudish\MailMirror\Models\MailRawObject;
use Jkudish\MailMirror\Models\MailReconciliationReport;
use Jkudish\MailMirror\Models\MailSourceChange;
use Jkudish\MailMirror\Models\MailSyncCheckpoint;
use Jkudish\MailMirror\Models\MailThread;
use Jkudish\MailMirror\Read\ChangedMessageState;
use Jkudish\MailMirror\Read\DeltaSyncResult;
use Jkudish\MailMirror\Read\InventoryPage;
use Jkudish\MailMirror\Read\MailboxChangesPage;
use Jkudish\MailMirror\Read\MailReadService;
use Jkudish\MailMirror\Read\MessageReference;
use Jkudish\MailMirror\Read\RetrievedMessage;
use Jkudish\MailMirror\Read\SyncWorkBudget;
use Jkudish\MailMirror\Storage\MailObjectStorage;
use Throwable;

final readonly class MailImportEngine
{
    private const DELTA_PENDING_RETRY_LIMIT = 25;

    public function __construct(
        private MailReadService $reads,
        private ReconciliationService $reconciliation,
        private MailObjectStorage $objects,
        private Dispatcher $events,
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

    public function retryOpenFailures(
        int $mailAccountId,
        ?string $ownerType,
        string|int|null $ownerId,
        int $limit = 100,
    ): int {
        if ($limit < 1 || $limit > 500) {
            throw new InvalidArgumentException('The retry limit must be between one and 500.');
        }

        try {
            $account = MailAccount::query()->whereKey($mailAccountId)
                ->where('owner_type', $ownerType)
                ->where('owner_id', $ownerId === null ? null : (string) $ownerId)
                ->firstOrFail();
        } catch (ModelNotFoundException) {
            throw new AccountResourceMismatch('The requested account does not match the supplied owner tuple.');
        }

        /** @var array<string, RetrievedMessage|MailImportFailure> $outcomes */
        $outcomes = [];
        /** @var list<string> $rollbackObjectKeys */
        $rollbackObjectKeys = [];

        try {
            return $account->getConnection()->transaction(function () use ($account, $limit, &$outcomes, &$rollbackObjectKeys): int {
                MailAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
                $checkpoint = MailSyncCheckpoint::query()->forAccount($account)
                    ->lockForUpdate()
                    ->first();

                if ($checkpoint === null) {
                    return 0;
                }

                if ($checkpoint->scan_completed_at !== null) {
                    throw new InvalidArgumentException('Open failures may only be retried during an incomplete inventory scan.');
                }

                $checkpoint->forceFill(['version' => $checkpoint->version + 1])->save();

                /** @var list<MessageReference> $references */
                $references = MailInventoryItem::query()->forAccount($account)
                    ->where('scan_id', $checkpoint->scan_id)
                    ->whereExists(function (Builder $query): void {
                        $query->selectRaw('1')
                            ->from('mail_import_errors as errors')
                            ->whereColumn('errors.mail_account_id', 'mail_inventory_items.mail_account_id')
                            ->whereColumn('errors.provider_message_id', 'mail_inventory_items.provider_message_id')
                            ->whereNull('errors.resolved_at');
                    })
                    ->orderBy('id')
                    ->limit($limit)
                    ->get()
                    ->map(fn (MailInventoryItem $item): MessageReference => new MessageReference(
                        $account->id,
                        $account->driver,
                        $item->provider_message_id,
                        $item->provider_thread_id,
                        $item->provider_metadata ?? [],
                    ))
                    ->values()
                    ->all();

                $outcomes = $this->retrieveReferences($account, $references, null);
                $successful = 0;

                foreach ($references as $reference) {
                    $outcome = $outcomes[$reference->providerMessageId];
                    $this->persistOutcome($account, $reference, $outcome, $rollbackObjectKeys);
                    $successful += $outcome instanceof RetrievedMessage ? 1 : 0;
                }

                return $successful;
            }, 1);
        } catch (Throwable $failure) {
            foreach ($rollbackObjectKeys as $objectKey) {
                $this->objects->cleanupRolledBackRaw($account, $objectKey);
            }

            throw $failure;
        } finally {
            $this->closeRawSources($outcomes);
        }
    }

    public function syncChangesAccount(
        int $mailAccountId,
        ?string $ownerType,
        string|int|null $ownerId,
        ?int $pageLimit = null,
        int $repairPageLimit = 1,
        ?Closure $afterDurablePage = null,
        ?SyncWorkBudget $budget = null,
    ): DeltaSyncResult {
        try {
            $account = MailAccount::query()->whereKey($mailAccountId)
                ->where('owner_type', $ownerType)
                ->where('owner_id', $ownerId === null ? null : (string) $ownerId)
                ->firstOrFail();
        } catch (ModelNotFoundException) {
            throw new AccountResourceMismatch('The requested account does not match the supplied owner tuple.');
        }

        return $this->syncChanges($account, $pageLimit, $repairPageLimit, $afterDurablePage, $budget);
    }

    public function syncChanges(
        MailAccount $suppliedAccount,
        ?int $pageLimit = null,
        int $repairPageLimit = 1,
        ?Closure $afterDurablePage = null,
        ?SyncWorkBudget $budget = null,
    ): DeltaSyncResult {
        if (($pageLimit !== null && $pageLimit < 1) || $repairPageLimit < 1) {
            throw new InvalidArgumentException('Delta and repair page limits must be at least one.');
        }

        $account = $this->reacquire($suppliedAccount);
        $budget?->assertElapsed();
        $checkpoint = MailDeltaCheckpoint::query()->forAccount($account)->first();

        if ($checkpoint?->repair_cursor !== null) {
            return $this->continueDeltaRepair($account, $checkpoint, $pageLimit, $repairPageLimit, $afterDurablePage, $budget);
        }

        /** @var list<string> $pendingRetryIds */
        $pendingRetryIds = MailDeltaPendingMessage::query()->forAccount($account)
            ->leftJoin('mail_import_errors as retry_errors', function (JoinClause $join): void {
                $join->on('retry_errors.mail_account_id', '=', 'mail_delta_pending_messages.mail_account_id')
                    ->on('retry_errors.provider_message_id', '=', 'mail_delta_pending_messages.provider_message_id')
                    ->where('retry_errors.stage', MailImportStage::Retrieve->value);
            })
            ->orderByRaw('COALESCE(retry_errors.attempt_count, 0)')
            ->orderBy('mail_delta_pending_messages.id')
            ->limit(self::DELTA_PENDING_RETRY_LIMIT)
            ->pluck('mail_delta_pending_messages.provider_message_id')
            ->all();
        $processed = 0;
        $pages = 0;

        while ($pageLimit === null || $pages < $pageLimit) {
            try {
                $page = $this->reads->changesPage($account, $checkpoint?->provider_cursor, $budget);
            } catch (DeltaRepairRequired $repair) {
                $checkpoint = $this->beginDeltaRepair($account, $checkpoint, $repair->recoveryCursor);

                return $this->continueDeltaRepair($account, $checkpoint, $pageLimit, $repairPageLimit, $afterDurablePage, $budget);
            }

            $outcomes = $this->retrieveNewChanges($account, $page, $budget);

            try {
                $checkpoint = $this->persistChangesPage($account, $checkpoint, $page, $outcomes);
            } finally {
                $this->closeRawSources($outcomes);
            }

            $processed += count($page->messages) + count($page->unavailableMessages) + count($page->deletions);
            $pages++;
            $afterDurablePage?->__invoke($checkpoint);
            $budget?->assertElapsed();

            if (collect($outcomes)->contains(fn (RetrievedMessage|MailImportFailure $outcome): bool => $this->isBlockingDeltaFailure($outcome))) {
                return new DeltaSyncResult($processed, false, false);
            }

            if ($page->complete) {
                $this->retryDeltaPendingMessages($account, $pendingRetryIds, $budget);
                $budget?->assertElapsed();
                $pending = MailDeltaPendingMessage::query()->forAccount($account)->exists();

                return new DeltaSyncResult($processed, ! $pending, false);
            }
        }

        $this->retryDeltaPendingMessages($account, $pendingRetryIds, $budget);
        $budget?->assertElapsed();

        return new DeltaSyncResult($processed, false, false);
    }

    public function sync(
        MailAccount $suppliedAccount,
        ?int $pageLimit = null,
        ?Closure $afterDurablePage = null,
        ?SyncWorkBudget $budget = null,
    ): ?MailReconciliationReport {
        if ($pageLimit !== null && $pageLimit < 1) {
            throw new InvalidArgumentException('The page limit must be at least one.');
        }

        $account = $this->reacquire($suppliedAccount);
        $budget?->assertElapsed();
        $checkpoint = MailSyncCheckpoint::query()->forAccount($account)->first();

        if ($checkpoint !== null && $checkpoint->scan_completed_at !== null) {
            $report = MailReconciliationReport::query()->forAccount($account)->where('scan_id', $checkpoint->scan_id)->first();

            if ($report === null) {
                return $this->reconciliation->reconcile($account, $checkpoint->scan_id);
            }
        }

        $checkpoint = $this->checkpoint($account);
        $pages = 0;
        $restarts = 0;
        $maximumRestarts = config('mail-mirror.import_max_scan_restarts', 1);
        $maximumRestarts = is_int($maximumRestarts) && $maximumRestarts >= 0 && $maximumRestarts <= 3
            ? $maximumRestarts : 1;

        while ($pageLimit === null || $pages < $pageLimit) {
            try {
                $page = $this->reads->inventoryPage($account, $checkpoint->provider_cursor, $budget);
            } catch (InventoryRestartRequired $restart) {
                if ($restarts >= $maximumRestarts) {
                    throw new MailImportFailure(MailImportStage::Inventory, MailImportCode::StateMismatch);
                }

                $checkpoint = $this->restartCheckpoint($account, $checkpoint, $restart->restartCursor);
                $restarts++;

                continue;
            }
            $outcomes = $this->retrieve($account, $page, $budget);

            try {
                $checkpoint = $this->persistPage($account, $checkpoint, $page, $outcomes);
            } finally {
                $this->closeRawSources($outcomes);
            }
            $pages++;
            $afterDurablePage?->__invoke($checkpoint);
            $budget?->assertElapsed();

            if ($checkpoint->scan_completed_at !== null) {
                return $this->reconciliation->reconcile($account, $checkpoint->scan_id);
            }
        }

        return null;
    }

    /** @return array<string, RetrievedMessage|MailImportFailure> */
    private function retrieveNewChanges(
        MailAccount $account,
        MailboxChangesPage $page,
        ?SyncWorkBudget $budget,
    ): array {
        $references = [];

        foreach ($page->messages as $change) {
            if (! MailMessage::query()->forAccount($account)
                ->where('provider_message_id', $change->reference->providerMessageId)
                ->exists()) {
                $references[] = $change->reference;
            }
        }

        return $this->retrieveReferences($account, $references, $budget);
    }

    /** @param list<string> $providerMessageIds */
    private function retryDeltaPendingMessages(
        MailAccount $account,
        array $providerMessageIds,
        ?SyncWorkBudget $budget,
    ): void {
        if ($providerMessageIds === []) {
            return;
        }

        $pending = MailDeltaPendingMessage::query()->forAccount($account)
            ->whereIn('provider_message_id', $providerMessageIds)
            ->get()
            ->keyBy('provider_message_id');

        if ($pending->isEmpty()) {
            return;
        }

        $references = array_values(array_filter(array_map(
            function (string $providerMessageId) use ($account, $pending): ?MessageReference {
                $message = $pending->get($providerMessageId);

                return $message instanceof MailDeltaPendingMessage ? new MessageReference(
                    $account->id,
                    $account->driver,
                    $message->provider_message_id,
                    $message->provider_thread_id,
                ) : null;
            },
            $providerMessageIds,
        )));
        $outcomes = $this->retrieveReferences($account, $references, $budget);
        /** @var list<string> $rollbackObjectKeys */
        $rollbackObjectKeys = [];

        try {
            $account->getConnection()->transaction(function () use ($account, $references, $outcomes, &$rollbackObjectKeys): void {
                MailAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();

                foreach ($references as $reference) {
                    if (! MailDeltaPendingMessage::query()->forAccount($account)
                        ->where('provider_message_id', $reference->providerMessageId)
                        ->exists()) {
                        continue;
                    }

                    $outcome = $outcomes[$reference->providerMessageId];
                    $this->persistOutcome($account, $reference, $outcome, $rollbackObjectKeys);

                    if ($outcome instanceof RetrievedMessage) {
                        MailDeltaPendingMessage::query()->forAccount($account)
                            ->where('provider_message_id', $reference->providerMessageId)
                            ->delete();
                    }
                }
            }, 1);
        } catch (Throwable $failure) {
            foreach ($rollbackObjectKeys as $objectKey) {
                $this->objects->cleanupRolledBackRaw($account, $objectKey);
            }

            throw $failure;
        } finally {
            $this->closeRawSources($outcomes);
        }
    }

    /** @param array<string, RetrievedMessage|MailImportFailure> $outcomes */
    private function persistChangesPage(
        MailAccount $account,
        ?MailDeltaCheckpoint $expected,
        MailboxChangesPage $page,
        array $outcomes,
    ): MailDeltaCheckpoint {
        /** @var list<string> $rollbackObjectKeys */
        $rollbackObjectKeys = [];

        try {
            return $account->getConnection()->transaction(function () use ($account, $expected, $page, $outcomes, &$rollbackObjectKeys): MailDeltaCheckpoint {
                $durableAccount = MailAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
                $checkpoint = $this->lockDeltaCheckpoint($account, $expected);
                $hasFailures = collect($outcomes)->contains(fn (RetrievedMessage|MailImportFailure $outcome): bool => $this->isBlockingDeltaFailure($outcome));

                if ($page->accountProfile !== null && ! $hasFailures) {
                    if ($page->accountProfile->providerAccountId !== $durableAccount->provider_account_id) {
                        throw new AccountResourceMismatch('The provider profile does not match the durable account identity.');
                    }

                    $durableAccount->forceFill(['provider_metadata' => $page->accountProfile->providerMetadata])->save();
                }

                $this->persistContainerSnapshot($account, $page);

                foreach ($page->unavailableMessages as $reference) {
                    $this->recordDeltaPendingMessage($account, $reference);
                }

                foreach ($page->messages as $change) {
                    $message = MailMessage::query()->forAccount($account)
                        ->where('provider_message_id', $change->reference->providerMessageId)
                        ->first();

                    if ($message === null) {
                        $outcome = $outcomes[$change->reference->providerMessageId];

                        if ($outcome instanceof MailImportFailure
                            && $outcome->safeCode === MailImportCode::MessageUnavailable) {
                            $this->recordDeltaPendingMessage($account, $change->reference, $outcome);
                        } else {
                            $this->persistOutcome(
                                $account,
                                $change->reference,
                                $outcome,
                                $rollbackObjectKeys,
                            );
                        }
                    } else {
                        $this->applyChangedState($account, $message, $change);
                    }
                }

                foreach ($page->deletions as $deletion) {
                    $evidence = MailProviderDeletionEvidence::query()->firstOrNew(
                        ['mail_account_id' => $account->id, 'provider_message_id' => $deletion->providerMessageId],
                    );
                    $evidence->fill([
                        'proof_code' => $deletion->proofCode,
                        'audit_reference' => $deletion->auditReference,
                        'provider_metadata' => $deletion->providerMetadata,
                    ]);

                    if (! $evidence->exists) {
                        $evidence->scan_id = (string) Str::uuid();
                    }

                    $evidence->save();

                    if ($evidence->wasRecentlyCreated || $evidence->wasChanged()) {
                        $this->recordDeletionChange($account, $deletion->providerMessageId, true);
                        $this->events->dispatch(new ProviderDeletionStateChanged(
                            $account->id,
                            $deletion->providerMessageId,
                            true,
                        ));
                    }

                    MailDeltaPendingMessage::query()->forAccount($account)
                        ->where('provider_message_id', $deletion->providerMessageId)
                        ->delete();
                    MailImportError::query()->forAccount($account)
                        ->where('provider_message_id', $deletion->providerMessageId)
                        ->whereNull('resolved_at')
                        ->update(['resolved_at' => now()]);
                }

                if ($checkpoint === null) {
                    return MailDeltaCheckpoint::query()->create([
                        'mail_account_id' => $account->id,
                        'provider_cursor' => $hasFailures ? null : $page->nextCursor,
                        'version' => 1,
                    ]);
                }

                $checkpoint->forceFill([
                    'provider_cursor' => $hasFailures ? $checkpoint->provider_cursor : $page->nextCursor,
                    'version' => $checkpoint->version + 1,
                ])->save();

                return $checkpoint;
            }, 1);
        } catch (Throwable $failure) {
            foreach ($rollbackObjectKeys as $objectKey) {
                $this->objects->cleanupRolledBackRaw($account, $objectKey);
            }

            throw $failure;
        }
    }

    private function recordDeltaPendingMessage(
        MailAccount $account,
        MessageReference $reference,
        ?MailImportFailure $failure = null,
    ): void {
        MailDeltaPendingMessage::query()->updateOrCreate(
            [
                'mail_account_id' => $account->id,
                'provider_message_id' => $reference->providerMessageId,
            ],
            ['provider_thread_id' => $reference->providerThreadId],
        );
        $this->recordFailure(
            $account,
            $reference->providerMessageId,
            $failure ?? new MailImportFailure(MailImportStage::Retrieve, MailImportCode::MessageUnavailable),
        );
    }

    private function isBlockingDeltaFailure(RetrievedMessage|MailImportFailure $outcome): bool
    {
        return $outcome instanceof MailImportFailure
            && $outcome->safeCode !== MailImportCode::MessageUnavailable;
    }

    private function beginDeltaRepair(
        MailAccount $account,
        ?MailDeltaCheckpoint $expected,
        string $recoveryCursor,
    ): MailDeltaCheckpoint {
        return $account->getConnection()->transaction(function () use ($account, $expected, $recoveryCursor): MailDeltaCheckpoint {
            MailAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            $checkpoint = $this->lockDeltaCheckpoint($account, $expected);
            $repairScanId = $this->startFreshInventoryScan($account);

            if ($checkpoint === null) {
                return MailDeltaCheckpoint::query()->create([
                    'mail_account_id' => $account->id,
                    'version' => 0,
                    'repair_cursor' => $recoveryCursor,
                    'repair_scan_id' => $repairScanId,
                    'repair_started_at' => now(),
                ]);
            }

            $checkpoint->forceFill([
                'version' => $checkpoint->version + 1,
                'repair_cursor' => $recoveryCursor,
                'repair_scan_id' => $repairScanId,
                'repair_started_at' => now(),
            ])->save();

            return $checkpoint;
        }, 1);
    }

    private function continueDeltaRepair(
        MailAccount $account,
        MailDeltaCheckpoint $expected,
        ?int $pageLimit,
        int $repairPageLimit,
        ?Closure $afterDurablePage,
        ?SyncWorkBudget $budget,
    ): DeltaSyncResult {
        $inventory = MailSyncCheckpoint::query()->forAccount($account)->first();

        if ($inventory === null || $inventory->scan_id !== $expected->repair_scan_id) {
            throw new StaleCheckpoint;
        }

        $report = $inventory->scan_completed_at === null
            ? $this->sync($account, $repairPageLimit, budget: $budget)
            : (MailReconciliationReport::query()->forAccount($account)
                ->where('scan_id', $inventory->scan_id)
                ->first() ?? $this->reconciliation->reconcile($account, $inventory->scan_id));

        if ($report === null) {
            return new DeltaSyncResult(0, false, true);
        }

        if ($report->scan_id !== $expected->repair_scan_id) {
            throw new StaleCheckpoint;
        }

        $budget?->assertElapsed();

        if ($report->transient_error_count > 0
            || $report->unexplained_missing_count > 0) {
            $this->restartDeltaRepairScan($account, $expected);

            return new DeltaSyncResult(0, false, true);
        }

        if ($report->unexpected_active_count > 0
            && ! $this->resolveRepairUnexpectedActive($account, $expected, $report, $budget)) {
            $this->restartDeltaRepairScan($account, $expected);

            return new DeltaSyncResult(0, false, true);
        }

        $budget?->assertElapsed();

        $account->getConnection()->transaction(function () use ($account, $expected): void {
            MailAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            $checkpoint = $this->lockDeltaCheckpoint($account, $expected);

            if ($checkpoint === null || $checkpoint->repair_cursor === null) {
                throw new StaleCheckpoint;
            }

            $checkpoint->forceFill([
                'provider_cursor' => $checkpoint->repair_cursor,
                'version' => $checkpoint->version + 1,
                'repair_cursor' => null,
                'repair_scan_id' => null,
                'repair_started_at' => null,
            ])->save();
        }, 1);

        return $this->syncChanges($account, $pageLimit, $repairPageLimit, $afterDurablePage, $budget);
    }

    private function restartDeltaRepairScan(MailAccount $account, MailDeltaCheckpoint $expected): void
    {
        $account->getConnection()->transaction(function () use ($account, $expected): void {
            MailAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            $checkpoint = $this->lockDeltaCheckpoint($account, $expected);

            if ($checkpoint === null || $checkpoint->repair_cursor === null) {
                throw new StaleCheckpoint;
            }

            $previousScanId = $checkpoint->repair_scan_id;
            $nextScanId = $this->startFreshInventoryScan($account);

            MailProviderDeletionEvidence::query()->forAccount($account)
                ->where('scan_id', $previousScanId)
                ->update(['scan_id' => $nextScanId]);

            $checkpoint->forceFill([
                'version' => $checkpoint->version + 1,
                'repair_scan_id' => $nextScanId,
                'repair_started_at' => now(),
            ])->save();
        }, 1);
    }

    private function resolveRepairUnexpectedActive(
        MailAccount $account,
        MailDeltaCheckpoint $expected,
        MailReconciliationReport $report,
        ?SyncWorkBudget $budget,
    ): bool {
        $limit = config('mail-mirror.inventory_page_max_messages', 500);
        $limit = is_int($limit) && $limit > 0 ? $limit : 500;
        $messages = MailMessage::query()->forAccount($account)
            ->whereNotExists(function (Builder $query) use ($account, $report): void {
                $query->selectRaw('1')->from('mail_inventory_items as repair_inventory')
                    ->where('repair_inventory.mail_account_id', $account->id)
                    ->where('repair_inventory.scan_id', $report->scan_id)
                    ->whereColumn('repair_inventory.provider_message_id', 'mail_messages.provider_message_id');
            })
            ->whereNotExists(function (Builder $query) use ($account, $report): void {
                $query->selectRaw('1')->from('mail_provider_deletion_evidence as repair_deletions')
                    ->where('repair_deletions.mail_account_id', $account->id)
                    ->where('repair_deletions.scan_id', $report->scan_id)
                    ->whereColumn('repair_deletions.provider_message_id', 'mail_messages.provider_message_id');
            })
            ->orderBy('provider_message_id')
            ->limit($limit + 1)
            ->get(['provider_message_id']);

        if ($messages->count() !== $report->unexpected_active_count || $messages->count() > $limit) {
            return false;
        }

        $references = array_values($messages->map(fn (MailMessage $message): MessageReference => new MessageReference(
            $account->id,
            $account->driver,
            $message->provider_message_id,
        ))->all());
        $outcomes = $this->retrieveReferences($account, $references, $budget);
        $resolved = ! collect($outcomes)->contains(
            fn (RetrievedMessage|MailImportFailure $outcome): bool => $outcome instanceof MailImportFailure
                && $outcome->safeCode !== MailImportCode::MessageUnavailable,
        );

        try {
            $account->getConnection()->transaction(function () use ($account, $expected, $outcomes, $report): void {
                MailAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
                $checkpoint = $this->lockDeltaCheckpoint($account, $expected);

                if ($checkpoint === null || $checkpoint->repair_scan_id !== $report->scan_id) {
                    throw new StaleCheckpoint;
                }

                foreach ($outcomes as $providerMessageId => $outcome) {
                    if (! $outcome instanceof MailImportFailure
                        || $outcome->safeCode !== MailImportCode::MessageUnavailable) {
                        continue;
                    }

                    $evidence = MailProviderDeletionEvidence::query()->firstOrNew([
                        'mail_account_id' => $account->id,
                        'provider_message_id' => $providerMessageId,
                    ]);
                    $evidence->fill([
                        'scan_id' => $report->scan_id,
                        'proof_code' => 'exact_source_absent',
                        'audit_reference' => 'repair-absence-'.hash('sha256', $report->scan_id.':'.$providerMessageId),
                        'provider_metadata' => [],
                    ])->save();

                    if ($evidence->wasRecentlyCreated) {
                        $this->recordDeletionChange($account, $providerMessageId, true);
                        $this->events->dispatch(new ProviderDeletionStateChanged(
                            $account->id,
                            $providerMessageId,
                            true,
                        ));
                    }

                    MailDeltaPendingMessage::query()->forAccount($account)
                        ->where('provider_message_id', $providerMessageId)
                        ->delete();
                    MailImportError::query()->forAccount($account)
                        ->where('provider_message_id', $providerMessageId)
                        ->whereNull('resolved_at')
                        ->update(['resolved_at' => now()]);
                }
            }, 1);
        } finally {
            $this->closeRawSources($outcomes);
        }

        return $resolved;
    }

    private function startFreshInventoryScan(MailAccount $account): string
    {
        $scanId = (string) Str::uuid();
        $checkpoint = MailSyncCheckpoint::query()->forAccount($account)->lockForUpdate()->first();

        if ($checkpoint === null) {
            MailSyncCheckpoint::query()->create([
                'mail_account_id' => $account->id,
                'scan_id' => $scanId,
                'version' => 0,
                'processed_count' => 0,
                'scan_started_at' => now(),
            ]);

            return $scanId;
        }

        $checkpoint->forceFill([
            'scan_id' => $scanId,
            'provider_cursor' => null,
            'version' => $checkpoint->version + 1,
            'processed_count' => 0,
            'scan_started_at' => now(),
            'scan_completed_at' => null,
        ])->save();

        return $scanId;
    }

    private function lockDeltaCheckpoint(
        MailAccount $account,
        ?MailDeltaCheckpoint $expected,
    ): ?MailDeltaCheckpoint {
        $query = MailDeltaCheckpoint::query()->forAccount($account)->lockForUpdate();

        if ($expected === null) {
            if ($query->exists()) {
                throw new StaleCheckpoint;
            }

            return null;
        }

        return $query->whereKey($expected->id)
            ->where('version', $expected->version)
            ->first() ?? throw new StaleCheckpoint;
    }

    private function restartCheckpoint(MailAccount $account, MailSyncCheckpoint $expected, string $cursor): MailSyncCheckpoint
    {
        return $account->getConnection()->transaction(function () use ($account, $expected, $cursor): MailSyncCheckpoint {
            MailAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            $checkpoint = MailSyncCheckpoint::query()->forAccount($account)
                ->where('scan_id', $expected->scan_id)
                ->where('version', $expected->version)
                ->lockForUpdate()
                ->first();

            if ($checkpoint === null) {
                throw new StaleCheckpoint;
            }

            $previousScanId = $checkpoint->scan_id;
            $nextScanId = (string) Str::uuid();
            MailProviderDeletionEvidence::query()->forAccount($account)
                ->where('scan_id', $previousScanId)
                ->update(['scan_id' => $nextScanId]);

            $checkpoint->forceFill([
                'scan_id' => $nextScanId,
                'provider_cursor' => $cursor,
                'version' => $checkpoint->version + 1,
                'processed_count' => 0,
                'scan_started_at' => now(),
                'scan_completed_at' => null,
            ])->save();

            return $checkpoint;
        }, 3);
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
    private function retrieve(
        MailAccount $account,
        InventoryPage $page,
        ?SyncWorkBudget $budget,
    ): array {
        return $this->retrieveReferences($account, $page->messages, $budget);
    }

    /**
     * @param  list<MessageReference>  $references
     * @return array<string, RetrievedMessage|MailImportFailure>
     */
    private function retrieveReferences(
        MailAccount $account,
        array $references,
        ?SyncWorkBudget $budget,
    ): array {
        $outcomes = [];
        $maxAttempts = config('mail-mirror.import_max_attempts', 3);
        $maxAttempts = is_int($maxAttempts) && $maxAttempts > 0 ? $maxAttempts : 3;

        foreach ($references as $reference) {
            $attempt = 0;

            do {
                $attempt++;

                try {
                    $message = $this->reads->retrieve($account, $reference, $budget);
                    $this->assertRetrieved($reference, $message);
                    $outcomes[$reference->providerMessageId] = $message;
                    break;
                } catch (SyncBudgetExhausted $failure) {
                    $this->closeRawSources($outcomes);

                    throw $failure;
                } catch (MailImportFailure $failure) {
                    if (! $failure->retryable || $attempt >= $maxAttempts) {
                        $outcomes[$reference->providerMessageId] = new MailImportFailure(
                            $failure->stage,
                            $failure->safeCode,
                            false,
                            $attempt,
                        );
                        break;
                    }

                    if ($failure->retryAfterSeconds !== null) {
                        Sleep::sleep($failure->retryAfterSeconds);
                    }
                } catch (InvalidArgumentException) {
                    $outcomes[$reference->providerMessageId] = new MailImportFailure(
                        MailImportStage::Retrieve,
                        MailImportCode::MalformedPayload,
                    );
                    break;
                } catch (Throwable) {
                    $outcomes[$reference->providerMessageId] = new MailImportFailure(
                        MailImportStage::Retrieve,
                        MailImportCode::UnexpectedFailure,
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
            throw new MailImportFailure(MailImportStage::Retrieve, MailImportCode::MalformedPayload);
        }
    }

    /** @param array<string, RetrievedMessage|MailImportFailure> $outcomes */
    private function persistPage(
        MailAccount $account,
        MailSyncCheckpoint $expected,
        InventoryPage $page,
        array $outcomes,
    ): MailSyncCheckpoint {
        /** @var list<string> $rollbackObjectKeys */
        $rollbackObjectKeys = [];

        try {
            return $account->getConnection()->transaction(function () use ($account, $expected, $page, $outcomes, &$rollbackObjectKeys): MailSyncCheckpoint {
                $durableAccount = MailAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
                $checkpoint = MailSyncCheckpoint::query()->forAccount($account)
                    ->where('scan_id', $expected->scan_id)
                    ->where('version', $expected->version)
                    ->lockForUpdate()
                    ->first();

                if ($checkpoint === null) {
                    throw new StaleCheckpoint;
                }

                if ($page->accountProfile !== null) {
                    if ($page->accountProfile->providerAccountId !== $durableAccount->provider_account_id) {
                        throw new AccountResourceMismatch('The provider profile does not match the durable account identity.');
                    }

                    $durableAccount->forceFill(['provider_metadata' => $page->accountProfile->providerMetadata])->save();
                }

                foreach ($page->identities as $identity) {
                    MailIdentity::query()->updateOrCreate(
                        ['mail_account_id' => $account->id, 'provider_identity_id' => $identity->providerIdentityId],
                        [
                            'email_address' => $identity->emailAddress,
                            'display_name' => $identity->displayName,
                            'provider_metadata' => $identity->providerMetadata,
                        ],
                    );
                }

                if ($page->identitiesComplete) {
                    $identityIds = array_map(fn ($identity): string => $identity->providerIdentityId, $page->identities);
                    $staleIdentities = MailIdentity::query()->forAccount($account);

                    if ($identityIds !== []) {
                        $staleIdentities->whereNotIn('provider_identity_id', $identityIds);
                    }

                    $staleIdentities->delete();
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
                    $this->persistOutcome($account, $reference, $outcome, $rollbackObjectKeys);
                }

                foreach ($page->deletions as $deletion) {
                    $evidence = MailProviderDeletionEvidence::query()->updateOrCreate(
                        ['mail_account_id' => $account->id, 'provider_message_id' => $deletion->providerMessageId],
                        [
                            'scan_id' => $checkpoint->scan_id,
                            'proof_code' => $deletion->proofCode,
                            'audit_reference' => $deletion->auditReference,
                            'provider_metadata' => $deletion->providerMetadata,
                        ],
                    );

                    if ($evidence->wasRecentlyCreated || $evidence->wasChanged()) {
                        $this->recordDeletionChange($account, $deletion->providerMessageId, true);
                        $this->events->dispatch(new ProviderDeletionStateChanged(
                            $account->id,
                            $deletion->providerMessageId,
                            true,
                        ));
                    }
                }

                if ($page->deletionResolutions !== []) {
                    $this->clearDeletionEvidence(
                        $account,
                        array_map(
                            fn ($resolution): string => $resolution->providerMessageId,
                            $page->deletionResolutions,
                        ),
                    );
                }

                $checkpoint->forceFill([
                    'provider_cursor' => $page->nextCursor,
                    'version' => $checkpoint->version + 1,
                    'processed_count' => $checkpoint->processed_count + count($page->messages),
                    'scan_completed_at' => $page->complete ? now() : null,
                ])->save();

                return $checkpoint;
            }, 1);
        } catch (Throwable $failure) {
            foreach ($rollbackObjectKeys as $objectKey) {
                $this->objects->cleanupRolledBackRaw($account, $objectKey);
            }

            throw $failure;
        }
    }

    /** @param list<string> $rollbackObjectKeys */
    private function persistOutcome(
        MailAccount $account,
        MessageReference $reference,
        RetrievedMessage|MailImportFailure $outcome,
        array &$rollbackObjectKeys,
    ): void {
        if ($outcome instanceof MailImportFailure) {
            $this->recordFailure($account, $reference->providerMessageId, $outcome);

            return;
        }

        $message = $this->hydrate($account, $reference, $outcome);

        if ($outcome->rawSource !== null) {
            $rawExisted = MailRawObject::query()->forAccount($account)
                ->where('mail_message_id', $message->id)->exists();
            $raw = $this->objects->storeRaw(
                $account,
                $message,
                $outcome->rawSource->stream,
                $outcome->rawSource->providerObjectId,
                $outcome->rawSource->mediaType,
                $outcome->rawSource->providerMetadata,
            );

            if (! $rawExisted && is_string($raw->object_key)) {
                $rollbackObjectKeys[] = $raw->object_key;
            }
        }

        MailImportError::query()->forAccount($account)
            ->where('provider_message_id', $reference->providerMessageId)
            ->whereNull('resolved_at')
            ->update(['resolved_at' => now()]);

        $this->clearDeletionEvidence($account, [$reference->providerMessageId]);
        $this->recordMessageChange($account, $message);
    }

    private function recordFailure(MailAccount $account, string $providerMessageId, MailImportFailure $failure): void
    {
        $error = MailImportError::query()->forAccount($account)
            ->where('provider_message_id', $providerMessageId)
            ->where('stage', $failure->stage->value)
            ->first();

        if ($error === null) {
            MailImportError::query()->create([
                'mail_account_id' => $account->id,
                'provider_message_id' => $providerMessageId,
                'stage' => $failure->stage->value,
                'code' => $failure->safeCode->value,
                'summary' => $failure->safeCode->summary(),
                'attempt_count' => $failure->attempts,
                'last_failed_at' => now(),
            ]);

            return;
        }

        $error->forceFill([
            'code' => $failure->safeCode->value,
            'summary' => $failure->safeCode->summary(),
            'attempt_count' => $error->attempt_count + $failure->attempts,
            'last_failed_at' => now(),
            'resolved_at' => null,
        ] + ($error->resolved_at === null ? [] : [
            'waived_at' => null,
            'waiver_reason' => null,
            'waiver_audit_reference' => null,
        ]))->save();
    }

    private function hydrate(MailAccount $account, MessageReference $reference, RetrievedMessage $retrieved): MailMessage
    {
        MailLocalMessagePurge::query()->forAccount($account)
            ->where('provider_message_id', $reference->providerMessageId)
            ->delete();

        $thread = null;

        if ($reference->providerThreadId !== null) {
            $thread = MailThread::query()->updateOrCreate(
                ['mail_account_id' => $account->id, 'provider_thread_id' => $reference->providerThreadId],
                ['subject' => $retrieved->subject, 'provider_metadata' => $retrieved->providerThreadMetadata],
            );
        }

        $message = MailMessage::query()->updateOrCreate(
            ['mail_account_id' => $account->id, 'provider_message_id' => $reference->providerMessageId],
            [
                'mail_thread_id' => $thread?->id,
                'internet_message_id' => $retrieved->internetMessageId,
                'subject' => $retrieved->subject,
                'sent_at' => $retrieved->sentAt,
                'received_at' => $retrieved->receivedAt,
                'provider_metadata' => $retrieved->providerMetadata,
            ],
        );

        MailMessageHeader::query()->forAccount($account)->where('mail_message_id', $message->id)->delete();

        foreach ($retrieved->headers as $position => $header) {
            /** @var array{name: string, value: string, provider_metadata?: array<string, mixed>} $header */
            MailMessageHeader::query()->create([
                'mail_account_id' => $account->id,
                'mail_message_id' => $message->id,
                'name' => $header['name'],
                'value' => $header['value'],
                'position' => $position,
                'provider_metadata' => $header['provider_metadata'] ?? null,
            ]);
        }

        MailMessageParticipant::query()->forAccount($account)->where('mail_message_id', $message->id)->delete();

        foreach ($retrieved->participants as $position => $participant) {
            /** @var array{role: string, address: string, name?: string, provider_metadata?: array<string, mixed>} $participant */
            $address = MailAddress::query()->updateOrCreate(
                ['mail_account_id' => $account->id, 'address' => trim($participant['address'])],
                [
                    'display_name' => $participant['name'] ?? null,
                    'provider_metadata' => $participant['provider_metadata'] ?? null,
                ],
            );
            MailMessageParticipant::query()->create([
                'mail_account_id' => $account->id,
                'mail_message_id' => $message->id,
                'mail_address_id' => $address->id,
                'role' => $participant['role'],
                'position' => $position,
                'provider_metadata' => $participant['provider_metadata'] ?? null,
            ]);
        }

        MailAttachment::query()->forAccount($account)
            ->where('mail_message_id', $message->id)
            ->whereNull('source_part_id')
            ->delete();

        foreach ($retrieved->attachments as $attachment) {
            /** @var array{provider_id: string, filename?: string, media_type?: string, byte_size?: int, content_id?: string, is_inline?: bool, provider_metadata?: array<string, mixed>} $attachment */
            if (MailAttachment::query()->forAccount($account)
                ->where('mail_message_id', $message->id)
                ->where('provider_attachment_id', $attachment['provider_id'])
                ->whereNotNull('source_part_id')
                ->exists()) {
                continue;
            }

            MailAttachment::query()->create([
                'mail_account_id' => $account->id,
                'mail_message_id' => $message->id,
                'provider_attachment_id' => $attachment['provider_id'],
                'filename' => $attachment['filename'] ?? null,
                'media_type' => $attachment['media_type'] ?? null,
                'byte_size' => $attachment['byte_size'] ?? null,
                'content_id' => $attachment['content_id'] ?? null,
                'is_inline' => $attachment['is_inline'] ?? false,
                'provider_metadata' => $attachment['provider_metadata'] ?? [],
            ]);
        }

        $this->replaceMessageContainers($account, $message, $retrieved->containers);

        return $message;
    }

    private function applyChangedState(MailAccount $account, MailMessage $message, ChangedMessageState $change): void
    {
        $thread = null;

        if ($change->reference->providerThreadId !== null) {
            $thread = MailThread::query()->firstOrCreate([
                'mail_account_id' => $account->id,
                'provider_thread_id' => $change->reference->providerThreadId,
            ]);
        }

        $metadata = is_array($message->provider_metadata) ? $message->provider_metadata : [];
        $message->forceFill([
            'mail_thread_id' => $thread?->id,
            'provider_metadata' => array_replace($metadata, $change->providerMetadata),
        ])->save();
        $this->replaceMessageContainers($account, $message, $change->containers);
        MailImportError::query()->forAccount($account)
            ->where('provider_message_id', $message->provider_message_id)
            ->whereNull('resolved_at')
            ->update(['resolved_at' => now()]);
        $this->clearDeletionEvidence($account, [$message->provider_message_id]);
        $this->recordMessageChange($account, $message);
    }

    private function persistContainerSnapshot(MailAccount $account, MailboxChangesPage $page): void
    {
        $providerIds = [];

        foreach ($page->containers as $state) {
            $providerIds[] = $state->providerContainerId;
            $container = MailContainer::query()->firstOrNew([
                'mail_account_id' => $account->id,
                'provider_container_id' => $state->providerContainerId,
            ]);
            $existed = $container->exists;
            $container->fill([
                'name' => $state->name,
                'kind' => $state->kind,
                'provider_metadata' => $state->providerMetadata,
            ]);
            $changed = ! $existed || $container->isDirty(['name', 'kind', 'provider_metadata']);
            $container->save();

            if ($changed) {
                $this->recordContainerChange($account, $container, MailSourceChangeKind::ContainerChanged);

                if ($existed) {
                    $this->events->dispatch(new MailContainerStateChanged($account->id, $container->id));
                }
            }
        }

        if (! $page->containersComplete) {
            return;
        }

        $stale = MailContainer::query()->forAccount($account);

        if ($providerIds !== []) {
            $stale->whereNotIn('provider_container_id', $providerIds);
        }

        $stale->get()->each(function (MailContainer $container) use ($account): void {
            $messageIds = MailMessageContainerMembership::query()->forAccount($account)
                ->where('mail_container_id', $container->id)
                ->distinct()
                ->pluck('mail_message_id');

            MailMessage::query()->forAccount($account)
                ->whereIn('id', $messageIds)
                ->each(fn (MailMessage $message) => $this->recordMessageChange($account, $message));
            $this->recordContainerChange($account, $container, MailSourceChangeKind::ContainerDeleted);
            $container->delete();
        });
    }

    /** @param list<array<string, mixed>> $containers */
    private function replaceMessageContainers(MailAccount $account, MailMessage $message, array $containers): void
    {
        MailMessageContainerMembership::query()->forAccount($account)
            ->where('mail_message_id', $message->id)->delete();

        foreach ($containers as $containerData) {
            $container = MailContainer::query()->firstOrNew([
                'mail_account_id' => $account->id,
                'provider_container_id' => $containerData['provider_id'],
            ]);
            $existed = $container->exists;
            $container->fill([
                'name' => $containerData['name'] ?? $containerData['provider_id'],
                'kind' => $containerData['kind'] ?? null,
                'provider_metadata' => $containerData['provider_metadata'] ?? [],
            ]);
            $containerChanged = ! $existed || $container->isDirty(['name', 'kind', 'provider_metadata']);
            $container->save();

            if ($containerChanged) {
                $this->recordContainerChange($account, $container, MailSourceChangeKind::ContainerChanged);

                if ($existed) {
                    $this->events->dispatch(new MailContainerStateChanged($account->id, $container->id));
                }
            }

            MailMessageContainerMembership::query()->create([
                'mail_account_id' => $account->id,
                'mail_message_id' => $message->id,
                'mail_container_id' => $container->id,
                'provider_membership_id' => $containerData['membership_id'] ?? null,
                'provider_metadata' => $containerData['membership_metadata'] ?? null,
            ]);
        }
    }

    /** @param list<string> $providerMessageIds */
    private function clearDeletionEvidence(MailAccount $account, array $providerMessageIds): void
    {
        $cleared = MailProviderDeletionEvidence::query()->forAccount($account)
            ->whereIn('provider_message_id', $providerMessageIds)
            ->pluck('provider_message_id')
            ->all();

        if ($cleared === []) {
            return;
        }

        MailProviderDeletionEvidence::query()->forAccount($account)
            ->whereIn('provider_message_id', $cleared)
            ->delete();

        foreach ($cleared as $providerMessageId) {
            if (is_string($providerMessageId)) {
                $this->recordDeletionChange($account, $providerMessageId, false);
                $this->events->dispatch(new ProviderDeletionStateChanged(
                    $account->id,
                    $providerMessageId,
                    false,
                ));
            }
        }
    }

    private function recordMessageChange(MailAccount $account, MailMessage $message): void
    {
        MailSourceChange::query()->create([
            'mail_account_id' => $account->id,
            'kind' => MailSourceChangeKind::MessageChanged,
            'mail_message_id' => $message->id,
            'provider_message_id' => $message->provider_message_id,
        ]);
    }

    private function recordContainerChange(
        MailAccount $account,
        MailContainer $container,
        MailSourceChangeKind $kind,
    ): void {
        MailSourceChange::query()->create([
            'mail_account_id' => $account->id,
            'kind' => $kind,
            'mail_container_id' => $container->id,
            'provider_container_id' => $container->provider_container_id,
        ]);
    }

    private function recordDeletionChange(MailAccount $account, string $providerMessageId, bool $deleted): void
    {
        MailSourceChange::query()->create([
            'mail_account_id' => $account->id,
            'kind' => MailSourceChangeKind::ProviderDeletionChanged,
            'provider_message_id' => $providerMessageId,
            'provider_deleted' => $deleted,
        ]);
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
