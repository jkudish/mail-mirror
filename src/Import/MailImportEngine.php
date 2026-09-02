<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Import;

use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Jkudish\MailMirror\Enums\MailImportCode;
use Jkudish\MailMirror\Enums\MailImportStage;
use Jkudish\MailMirror\Exceptions\AccountResourceMismatch;
use Jkudish\MailMirror\Exceptions\InventoryRestartRequired;
use Jkudish\MailMirror\Exceptions\MailImportFailure;
use Jkudish\MailMirror\Exceptions\StaleCheckpoint;
use Jkudish\MailMirror\Models\MailAccount;
use Jkudish\MailMirror\Models\MailAddress;
use Jkudish\MailMirror\Models\MailAttachment;
use Jkudish\MailMirror\Models\MailContainer;
use Jkudish\MailMirror\Models\MailIdentity;
use Jkudish\MailMirror\Models\MailImportError;
use Jkudish\MailMirror\Models\MailInventoryItem;
use Jkudish\MailMirror\Models\MailMessage;
use Jkudish\MailMirror\Models\MailMessageContainerMembership;
use Jkudish\MailMirror\Models\MailMessageHeader;
use Jkudish\MailMirror\Models\MailMessageParticipant;
use Jkudish\MailMirror\Models\MailProviderDeletionEvidence;
use Jkudish\MailMirror\Models\MailRawObject;
use Jkudish\MailMirror\Models\MailReconciliationReport;
use Jkudish\MailMirror\Models\MailSyncCheckpoint;
use Jkudish\MailMirror\Models\MailThread;
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

                $outcomes = $this->retrieveReferences($account, $references);
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
        $restarts = 0;
        $maximumRestarts = config('mail-mirror.import_max_scan_restarts', 1);
        $maximumRestarts = is_int($maximumRestarts) && $maximumRestarts >= 0 && $maximumRestarts <= 3
            ? $maximumRestarts : 1;

        while ($pageLimit === null || $pages < $pageLimit) {
            try {
                $page = $this->reads->inventoryPage($account, $checkpoint->provider_cursor);
            } catch (InventoryRestartRequired $restart) {
                if ($restarts >= $maximumRestarts) {
                    throw new MailImportFailure(MailImportStage::Inventory, MailImportCode::StateMismatch);
                }

                $checkpoint = $this->restartCheckpoint($account, $checkpoint, $restart->restartCursor);
                $restarts++;

                continue;
            }
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
    private function retrieve(MailAccount $account, InventoryPage $page): array
    {
        return $this->retrieveReferences($account, $page->messages);
    }

    /**
     * @param  list<MessageReference>  $references
     * @return array<string, RetrievedMessage|MailImportFailure>
     */
    private function retrieveReferences(MailAccount $account, array $references): array
    {
        $outcomes = [];
        $maxAttempts = config('mail-mirror.import_max_attempts', 3);
        $maxAttempts = is_int($maxAttempts) && $maxAttempts > 0 ? $maxAttempts : 3;

        foreach ($references as $reference) {
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
                    MailProviderDeletionEvidence::query()->updateOrCreate(
                        ['mail_account_id' => $account->id, 'provider_message_id' => $deletion->providerMessageId],
                        [
                            'scan_id' => $checkpoint->scan_id,
                            'proof_code' => $deletion->proofCode,
                            'audit_reference' => $deletion->auditReference,
                            'provider_metadata' => $deletion->providerMetadata,
                        ],
                    );
                }

                if ($page->deletionResolutions !== []) {
                    MailProviderDeletionEvidence::query()->forAccount($account)
                        ->whereIn('provider_message_id', array_map(
                            fn ($resolution): string => $resolution->providerMessageId,
                            $page->deletionResolutions,
                        ))
                        ->delete();
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

        MailProviderDeletionEvidence::query()->forAccount($account)
            ->where('provider_message_id', $reference->providerMessageId)
            ->delete();
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

        MailMessageContainerMembership::query()->forAccount($account)
            ->where('mail_message_id', $message->id)->delete();

        foreach ($retrieved->containers as $containerData) {
            /** @var array{provider_id: string, name?: string, kind?: string, membership_id?: string, provider_metadata?: array<string, mixed>, membership_metadata?: array<string, mixed>} $containerData */
            $container = MailContainer::query()->updateOrCreate(
                ['mail_account_id' => $account->id, 'provider_container_id' => $containerData['provider_id']],
                [
                    'name' => $containerData['name'] ?? $containerData['provider_id'],
                    'kind' => $containerData['kind'] ?? null,
                    'provider_metadata' => $containerData['provider_metadata'] ?? [],
                ],
            );
            MailMessageContainerMembership::query()->create([
                'mail_account_id' => $account->id,
                'mail_message_id' => $message->id,
                'mail_container_id' => $container->id,
                'provider_membership_id' => $containerData['membership_id'] ?? null,
                'provider_metadata' => $containerData['membership_metadata'] ?? null,
            ]);
        }

        return $message;
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
