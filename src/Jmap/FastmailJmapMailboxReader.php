<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Jmap;

use Closure;
use DateTimeImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use Jkudish\MailMirror\Contracts\BudgetedDeltaMailboxReader;
use Jkudish\MailMirror\Contracts\DraftDriver;
use Jkudish\MailMirror\Contracts\MailboxMutationDriver;
use Jkudish\MailMirror\Credentials\ApiTokenCredential;
use Jkudish\MailMirror\Credentials\MailAccountConnection;
use Jkudish\MailMirror\Enums\MailboxAction;
use Jkudish\MailMirror\Enums\MailDriver;
use Jkudish\MailMirror\Enums\MailImportCode;
use Jkudish\MailMirror\Enums\MailImportStage;
use Jkudish\MailMirror\Enums\MailWriteCode;
use Jkudish\MailMirror\Exceptions\DeltaRepairRequired;
use Jkudish\MailMirror\Exceptions\InventoryRestartRequired;
use Jkudish\MailMirror\Exceptions\MailImportFailure;
use Jkudish\MailMirror\Exceptions\MailWriteFailure;
use Jkudish\MailMirror\Exceptions\SyncBudgetExhausted;
use Jkudish\MailMirror\Models\MailAccount;
use Jkudish\MailMirror\Models\MailAccountCredential;
use Jkudish\MailMirror\Read\AccountProfile;
use Jkudish\MailMirror\Read\ChangedMessageState;
use Jkudish\MailMirror\Read\InventoryPage;
use Jkudish\MailMirror\Read\MailboxChangesPage;
use Jkudish\MailMirror\Read\MailboxContainerState;
use Jkudish\MailMirror\Read\MailboxIdentity;
use Jkudish\MailMirror\Read\MessageReference;
use Jkudish\MailMirror\Read\ProviderDeletionEvidence;
use Jkudish\MailMirror\Read\ProviderDeletionResolution;
use Jkudish\MailMirror\Read\RawMessageSource;
use Jkudish\MailMirror\Read\RetrievedMessage;
use Jkudish\MailMirror\Read\SyncWorkBudget;
use Jkudish\MailMirror\Write\DraftContent;
use Jkudish\MailMirror\Write\DraftRevision;
use Jkudish\MailMirror\Write\MailboxChange;
use Jkudish\MailMirror\Write\MessageState;
use Throwable;

final class FastmailJmapMailboxReader implements BudgetedDeltaMailboxReader, DraftDriver, MailboxMutationDriver
{
    private const CORE = 'urn:ietf:params:jmap:core';

    private const MAIL = 'urn:ietf:params:jmap:mail';

    private const SUBMISSION = 'urn:ietf:params:jmap:submission';

    private const SESSION_URL = 'https://api.fastmail.com/jmap/session';

    /** @var array<int, array{api_url: string, download_url: string, session_state: string}> */
    private array $sessions = [];

    /** @var array<int, array<string, array{id: string, name: string, role: string|null, sort_order: int, metadata: array<string, mixed>}>> */
    private array $mailboxes = [];

    /** @var array<int, array<string, string>> */
    private array $profileMetadata = [];

    /** @var array<int, string|null> session upload URL templates keyed by account */
    private array $uploadUrls = [];

    private ?SyncWorkBudget $budget = null;

    public function __construct(
        private readonly Factory $http,
        private readonly MailAccountConnection $connections,
    ) {}

    public function driver(): MailDriver
    {
        return MailDriver::Jmap;
    }

    public function inventoryPage(
        MailAccount $account,
        ?string $cursor,
        ?SyncWorkBudget $budget = null,
    ): InventoryPage {
        /** @var InventoryPage */
        return $this->withinBudget($budget, fn (): InventoryPage => $this->readInventoryPage($account, $cursor));
    }

    private function readInventoryPage(MailAccount $account, ?string $cursor): InventoryPage
    {
        $this->assertAccount($account);
        $session = $this->session($account, true);
        $state = $cursor === null ? null : $this->decodeCursor($cursor);

        if ($state === null || $state['phase'] === 'resources') {
            return $this->resourcePage($account, $session);
        }

        // Session state describes metadata, not the Email collection. The
        // freshly validated Session may change without invalidating this cursor.
        if ($state['account_id'] !== $account->provider_account_id) {
            throw new InventoryRestartRequired($this->resourceRestartCursor($account, $session));
        }

        if ($state['phase'] === 'changes') {
            return $this->inventoryChangesPage($account, $session, $state);
        }

        return $this->fullPage($account, $session, $state);
    }

    public function changesPage(
        MailAccount $account,
        ?string $cursor,
        ?SyncWorkBudget $budget = null,
    ): MailboxChangesPage {
        /** @var MailboxChangesPage */
        return $this->withinBudget($budget, fn (): MailboxChangesPage => $this->readChangesPage($account, $cursor));
    }

    private function readChangesPage(MailAccount $account, ?string $cursor): MailboxChangesPage
    {
        $this->assertAccount($account);
        $session = $this->session($account, true);
        $mailboxResult = $this->fetchMailboxes($account, $session);
        $containers = array_values(array_map(
            fn (array $mailbox): MailboxContainerState => new MailboxContainerState(
                $account->id,
                $mailbox['id'],
                $mailbox['name'],
                $mailbox['role'] ?? 'mailbox',
                $mailbox['metadata'],
            ),
            $mailboxResult['mailboxes'],
        ));
        $state = $cursor === null ? [
            'phase' => 'changes',
            'account_id' => $account->provider_account_id,
            'session_state' => $session['session_state'],
            'email_state' => $account->provider_metadata['email_state'] ?? null,
            'anchor' => null,
            'progress' => 0,
        ] : $this->decodeCursor($cursor);

        if ($state['phase'] !== 'changes'
            || $state['account_id'] !== $account->provider_account_id
            || ! is_string($state['email_state']) || $state['email_state'] === '') {
            throw new DeltaRepairRequired($this->deltaCursor($account, $session, $this->currentEmailState($account, $session)));
        }

        try {
            $result = $this->call($account, $session, 'Email/changes', [
                'accountId' => $account->provider_account_id,
                'sinceState' => $state['email_state'],
                'maxChanges' => $this->listedPageLimit(max(1, $this->resourceLimit() - count($containers))),
            ], 'delta-changes', MailImportStage::Inventory);
        } catch (MailImportFailure $failure) {
            if (in_array($failure->safeCode, [MailImportCode::HistoryExpired, MailImportCode::StateMismatch], true)) {
                throw new DeltaRepairRequired($this->deltaCursor($account, $session, $this->currentEmailState($account, $session)));
            }

            throw $failure;
        } catch (InventoryRestartRequired) {
            $fresh = $this->session($account, true);
            throw new DeltaRepairRequired($this->deltaCursor($account, $fresh, $this->currentEmailState($account, $fresh)));
        }

        $created = $this->stringList($result['created'] ?? [], 255, MailImportStage::Inventory);
        $updated = $this->stringList($result['updated'] ?? [], 255, MailImportStage::Inventory);
        $destroyed = $this->stringList($result['destroyed'] ?? [], 255, MailImportStage::Inventory);
        $oldState = $this->boundedString($result['oldState'] ?? null, 255, MailImportStage::Inventory);
        $newState = $this->boundedString($result['newState'] ?? null, 255, MailImportStage::Inventory);
        $hasMore = $result['hasMoreChanges'] ?? null;
        $changedIds = array_values(array_unique(array_merge($created, $updated)));
        $destroyed = array_values(array_diff(array_unique($destroyed), $changedIds));

        if ($oldState !== $state['email_state'] || ! is_bool($hasMore)
            || ($hasMore && $newState === $oldState)) {
            throw new MailImportFailure(MailImportStage::Inventory, MailImportCode::StateMismatch);
        }

        if (count($changedIds) + count($destroyed) + count($containers) > $this->resourceLimit()) {
            throw new DeltaRepairRequired($this->deltaCursor($account, $session, $this->currentEmailState($account, $session)));
        }

        $changed = $this->changedMessageStates($account, $session, $changedIds, $mailboxResult['mailboxes'], $newState);
        $deletions = array_map(fn (string $id): ProviderDeletionEvidence => new ProviderDeletionEvidence(
            $account->id,
            $id,
            'jmap_email_destroyed',
            'jmap-change-'.hash('sha256', $newState.':'.$id),
            ['email_state' => $newState],
        ), $destroyed);

        return new MailboxChangesPage(
            $changed['messages'],
            $deletions,
            $containers,
            true,
            $this->deltaCursor($account, $session, $newState),
            ! $hasMore,
            $this->profile($account, $session, [
                'email_state' => $newState,
                'mailbox_state' => $mailboxResult['state'],
            ]),
            $changed['unavailable'],
        );
    }

    public function retrieve(
        MailAccount $account,
        MessageReference $message,
        ?SyncWorkBudget $budget = null,
    ): RetrievedMessage {
        /** @var RetrievedMessage */
        return $this->withinBudget($budget, fn (): RetrievedMessage => $this->readMessage($account, $message));
    }

    private function readMessage(MailAccount $account, MessageReference $message): RetrievedMessage
    {
        $this->assertAccount($account);

        if ($message->mailAccountId !== $account->id || $message->driver !== MailDriver::Jmap) {
            throw new MailImportFailure(MailImportStage::Retrieve, MailImportCode::StateMismatch);
        }

        $session = $this->session($account);
        $emailResult = $this->call($account, $session, 'Email/get', [
            'accountId' => $account->provider_account_id,
            'ids' => [$message->providerMessageId],
            'properties' => [
                'id', 'blobId', 'threadId', 'mailboxIds', 'keywords', 'size', 'receivedAt', 'sentAt',
                'messageId', 'subject', 'preview', 'hasAttachment', 'sender', 'from', 'to', 'cc', 'bcc',
                'replyTo', 'attachments',
            ],
        ], 'email', MailImportStage::Retrieve);
        /** @var array<string, mixed> $email */
        $email = $this->singleObject($emailResult, $message->providerMessageId, MailImportStage::Retrieve);
        $threadId = $this->boundedString($email['threadId'] ?? null, 255, MailImportStage::Retrieve);
        $blobId = $this->boundedString($email['blobId'] ?? null, 255, MailImportStage::Retrieve);
        $rawBytes = $this->download($account, $session, $blobId);
        $headers = $this->rawHeaders($rawBytes);
        $mailboxes = $this->mailboxes[$account->id] ?? $this->fetchMailboxes($account, $session)['mailboxes'];
        $mailboxIds = $this->truthMap($email['mailboxIds'] ?? null, MailImportStage::Retrieve);
        $keywords = $this->truthMap($email['keywords'] ?? null, MailImportStage::Retrieve);
        $containers = [];

        foreach (array_keys($mailboxIds) as $mailboxId) {
            $native = $mailboxes[$mailboxId] ?? [
                'id' => $mailboxId,
                'name' => $mailboxId,
                'role' => null,
                'sort_order' => 0,
                'metadata' => [],
            ];
            $containers[] = [
                'provider_id' => $native['id'],
                'name' => $native['name'],
                'kind' => $native['role'] ?? 'mailbox',
                // JMAP mailbox membership is a map entry, not a separately identified resource.
                'provider_metadata' => $native['metadata'],
                'membership_metadata' => ['mailbox_id' => $mailboxId],
            ];
        }

        $threadResult = $this->call($account, $session, 'Thread/get', [
            'accountId' => $account->provider_account_id,
            'ids' => [$threadId],
        ], 'thread', MailImportStage::Retrieve);
        $thread = $this->singleObject($threadResult, $threadId, MailImportStage::Retrieve);
        $emailState = $this->boundedString($emailResult['state'] ?? null, 255, MailImportStage::Retrieve);
        $threadState = $this->boundedString($threadResult['state'] ?? null, 255, MailImportStage::Retrieve);
        $messageIds = $this->stringList($email['messageId'] ?? [], 255, MailImportStage::Retrieve);

        return new RetrievedMessage(
            reference: new MessageReference(
                $account->id,
                MailDriver::Jmap,
                $message->providerMessageId,
                $threadId,
                $message->providerMetadata,
            ),
            subject: $this->optionalBoundedContentString($email['subject'] ?? null, 255, MailImportStage::Retrieve),
            internetMessageId: $messageIds[0] ?? null,
            headers: $headers,
            participants: $this->participants($email),
            attachments: $this->attachments($email['attachments'] ?? []),
            containers: $containers,
            providerMetadata: [
                'blob_id' => $blobId,
                'size' => $this->nonNegativeInteger($email['size'] ?? null, MailImportStage::Retrieve),
                'keywords' => $keywords,
                'mailbox_ids' => $mailboxIds,
                'mailbox_state' => $this->mailboxState($keywords, $containers),
                'has_attachment' => ($email['hasAttachment'] ?? false) === true,
                'preview' => $this->optionalBoundedContentString($email['preview'] ?? null, 1024, MailImportStage::Retrieve),
                'draft' => isset($keywords['$draft']),
                'email_state' => $emailState,
            ],
            rawSource: new RawMessageSource(
                $this->stream($rawBytes),
                $blobId,
                providerMetadata: ['blob_id' => $blobId],
            ),
            providerThreadMetadata: [
                'email_ids' => $this->stringList($thread['emailIds'] ?? [], 255, MailImportStage::Retrieve),
                'thread_state' => $threadState,
            ],
            sentAt: $this->date($email['sentAt'] ?? null, MailImportStage::Retrieve),
            receivedAt: $this->date($email['receivedAt'] ?? null, MailImportStage::Retrieve),
        );
    }

    /**
     * @param  array<string, true>  $keywords
     * @param  list<array<string, mixed>>  $containers
     * @return array{unread: bool, flagged: bool, draft: bool, sent: bool, spam: bool, trash: bool}
     */
    private function mailboxState(array $keywords, array $containers): array
    {
        $roles = array_map(
            fn (array $container): string => is_string($container['kind'] ?? null)
                ? strtolower($container['kind'])
                : '',
            $containers,
        );

        return [
            'unread' => ! isset($keywords['$seen']),
            'flagged' => isset($keywords['$flagged']),
            'draft' => isset($keywords['$draft']) || in_array('drafts', $roles, true),
            'sent' => in_array('sent', $roles, true),
            'spam' => array_intersect(['junk', 'spam'], $roles) !== [],
            'trash' => in_array('trash', $roles, true),
        ];
    }

    /** @param array{api_url: string, download_url: string, session_state: string} $session */
    private function resourcePage(MailAccount $account, array $session): InventoryPage
    {
        $mailboxResult = $this->call($account, $session, 'Mailbox/get', [
            'accountId' => $account->provider_account_id,
            'ids' => null,
        ], 'mailboxes', MailImportStage::Inventory);
        $identityResult = $this->call($account, $session, 'Identity/get', [
            'accountId' => $account->provider_account_id,
            'ids' => null,
        ], 'identities', MailImportStage::Inventory);
        $parsedMailboxes = $this->parseMailboxes($mailboxResult);
        $identities = $this->parseIdentities($identityResult);

        $this->mailboxes[$account->id] = $parsedMailboxes['mailboxes'];
        $previousState = $account->provider_metadata['email_state'] ?? null;
        $next = [
            'phase' => is_string($previousState) && $previousState !== '' ? 'changes' : 'full',
            'account_id' => $account->provider_account_id,
            'session_state' => $session['session_state'],
            'email_state' => is_string($previousState) && $previousState !== ''
                ? $previousState
                : $this->currentEmailState($account, $session),
            'anchor' => null,
            'progress' => 0,
        ];

        return new InventoryPage(
            [],
            $this->encodeCursor($next),
            false,
            accountProfile: $this->profile($account, $session, [
                'mailbox_state' => $parsedMailboxes['state'],
                'identity_state' => $this->boundedString($identityResult['state'] ?? null, 255, MailImportStage::Inventory),
            ]),
            identities: $identities,
            identitiesComplete: true,
        );
    }

    /**
     * @param  array{api_url: string, download_url: string, session_state: string}  $session
     * @param  array{phase: string, account_id: string, session_state: string, email_state: string|null, anchor: string|null, progress: int}  $cursor
     */
    private function inventoryChangesPage(MailAccount $account, array $session, array $cursor): InventoryPage
    {
        try {
            $result = $this->call($account, $session, 'Email/changes', [
                'accountId' => $account->provider_account_id,
                'sinceState' => $cursor['email_state'],
                'maxChanges' => $this->listedPageLimit($this->resourceLimit()),
            ], 'changes', MailImportStage::Inventory);
        } catch (MailImportFailure $failure) {
            if (in_array($failure->safeCode, [MailImportCode::HistoryExpired, MailImportCode::StateMismatch], true)) {
                return new InventoryPage([], $this->freshFullCursor($account, $session), false);
            }

            throw $failure;
        }

        $created = $this->stringList($result['created'] ?? [], 255, MailImportStage::Inventory);
        $updated = $this->stringList($result['updated'] ?? [], 255, MailImportStage::Inventory);
        $destroyed = $this->stringList($result['destroyed'] ?? [], 255, MailImportStage::Inventory);
        $oldState = $this->boundedString($result['oldState'] ?? null, 255, MailImportStage::Inventory);
        $changedIds = array_values(array_unique(array_merge($created, $updated)));
        $destroyed = array_values(array_diff(array_unique($destroyed), $changedIds));

        if ($oldState !== $cursor['email_state']) {
            throw new MailImportFailure(MailImportStage::Inventory, MailImportCode::StateMismatch);
        }

        if (count($changedIds) + count($destroyed) > $this->resourceLimit()) {
            throw new InventoryRestartRequired($this->freshFullCursor($account, $session));
        }

        $newState = $this->boundedString($result['newState'] ?? null, 255, MailImportStage::Inventory);
        $hasMore = $result['hasMoreChanges'] ?? null;

        if (! is_bool($hasMore)) {
            throw new MailImportFailure(MailImportStage::Inventory, MailImportCode::MalformedPayload);
        }

        if ($hasMore && $newState === $oldState) {
            throw new MailImportFailure(MailImportStage::Inventory, MailImportCode::StateMismatch);
        }

        $deletions = array_map(fn (string $id): ProviderDeletionEvidence => new ProviderDeletionEvidence(
            $account->id,
            $id,
            'jmap_email_destroyed',
            'jmap-change-'.hash('sha256', $newState.':'.$id),
            ['email_state' => $newState],
        ), $destroyed);
        $resolutions = array_map(
            fn (string $id): ProviderDeletionResolution => new ProviderDeletionResolution($account->id, $id),
            $changedIds,
        );
        $next = $hasMore ? [
            'phase' => 'changes',
            'account_id' => $account->provider_account_id,
            'session_state' => $session['session_state'],
            'email_state' => $newState,
            'anchor' => null,
            'progress' => 0,
        ] : $this->fullState($account, $session, $newState);

        return new InventoryPage(
            [],
            $this->encodeCursor($next),
            false,
            $deletions,
            $this->profile($account, $session, ['email_state' => $newState]),
            deletionResolutions: $resolutions,
        );
    }

    /**
     * @param  array{api_url: string, download_url: string, session_state: string}  $session
     * @param  array{phase: string, account_id: string, session_state: string, email_state: string|null, anchor: string|null, progress: int}  $cursor
     */
    private function fullPage(MailAccount $account, array $session, array $cursor): InventoryPage
    {
        // Eventual-convergence contract for the full-scan traversal:
        //
        // - Query-state drift is intentionally tolerated. The responses on this
        //   traversal may report a queryState that differs from the one observed
        //   on earlier pages because traversal is anchor-stable rather than a
        //   consistent JMAP query snapshot; a consistent snapshot view would
        //   require /queryChanges (RFC 8620 Section 5.5), which is not used here.
        // - Provider mutations that happen while the scan runs are therefore not
        //   reflected in this pass. They are converged by the following
        //   Email/changes pass plus subsequent full scans, not by this one.
        $arguments = [
            'accountId' => $account->provider_account_id,
            'limit' => $this->pageSize(),
            'calculateTotal' => true,
        ];

        if ($cursor['anchor'] === null) {
            $arguments['position'] = 0;
        } else {
            $arguments['anchor'] = $cursor['anchor'];
            $arguments['anchorOffset'] = 1;
        }

        try {
            $result = $this->call($account, $session, 'Email/query', $arguments, 'query', MailImportStage::Inventory);
        } catch (MailImportFailure $failure) {
            if ($cursor['anchor'] !== null && $failure->cursorRejected) {
                // The anchor no longer exists in the provider query. Restart the
                // full scan from a fresh cursor while preserving THIS cursor's
                // email_state (not a newer one) so deletion evidence recorded
                // during the abandoned scan stays replayable.
                throw new InventoryRestartRequired($this->encodeCursor($this->fullState(
                    $account,
                    $session,
                    $this->boundedString($cursor['email_state'], 255, MailImportStage::Inventory),
                )));
            }

            throw $failure;
        }

        $ids = $this->stringList($result['ids'] ?? [], 255, MailImportStage::Inventory);
        $this->boundedString($result['queryState'] ?? null, 255, MailImportStage::Inventory);
        $total = $this->nonNegativeInteger($result['total'] ?? null, MailImportStage::Inventory);
        $responsePosition = $this->nonNegativeInteger($result['position'] ?? null, MailImportStage::Inventory);

        if (($cursor['anchor'] === null && $responsePosition !== 0)
            || ($cursor['anchor'] !== null && $responsePosition < 1)) {
            throw new MailImportFailure(MailImportStage::Inventory, MailImportCode::StateMismatch);
        }

        if (count($ids) > $this->pageSize() || count(array_unique($ids)) !== count($ids)
            || ($cursor['anchor'] !== null && in_array($cursor['anchor'], $ids, true))) {
            throw new MailImportFailure(MailImportStage::Inventory, MailImportCode::MalformedPayload);
        }

        $endPosition = $responsePosition + count($ids);

        if ($endPosition > $total || ($ids === [] && $endPosition < $total)
            || $cursor['progress'] > PHP_INT_MAX - count($ids)) {
            throw new MailImportFailure(MailImportStage::Inventory, MailImportCode::StateMismatch);
        }

        $references = $this->referencesForIds($account, $session, $ids, 'full');

        // Completion rule: a NON-EMPTY anchored page never completes the scan. A
        // provider reporting a plausible-but-wrong position (for example 200
        // instead of 101 with total 300) would otherwise satisfy
        // endPosition === total and silently skip the tail. The scan completes
        // only when the first (position 0) page exhausts the mailbox, or when a
        // final EMPTY anchored query reports endPosition === total.
        $complete = $cursor['anchor'] === null
            ? $endPosition === $total
            : ($ids === [] && $endPosition === $total);
        $emailState = $this->boundedString($cursor['email_state'], 255, MailImportStage::Inventory);
        $profile = $this->profile($account, $session, ['email_state' => $emailState]);
        $nextAnchor = $complete ? null : ($ids[count($ids) - 1] ?? throw new MailImportFailure(
            MailImportStage::Inventory,
            MailImportCode::StateMismatch,
        ));

        return new InventoryPage(
            $references,
            $complete ? null : $this->encodeCursor([
                'phase' => 'full',
                'account_id' => $account->provider_account_id,
                'session_state' => $session['session_state'],
                'email_state' => $emailState,
                'anchor' => $nextAnchor,
                'progress' => $cursor['progress'] + count($ids),
            ]),
            $complete,
            accountProfile: $profile,
        );
    }

    /**
     * @param  array{api_url: string, download_url: string, session_state: string}  $session
     * @param  list<string>  $ids
     * @return list<MessageReference>
     */
    private function referencesForIds(MailAccount $account, array $session, array $ids, string $inventory): array
    {
        if ($ids === []) {
            return [];
        }

        $result = $this->call($account, $session, 'Email/get', [
            'accountId' => $account->provider_account_id,
            'ids' => $ids,
            'properties' => ['id', 'threadId'],
        ], 'references', MailImportStage::Inventory);
        $objects = $this->objectList($result, MailImportStage::Inventory);
        $byId = [];

        foreach ($objects as $object) {
            $id = $this->boundedString($object['id'] ?? null, 255, MailImportStage::Inventory);
            $byId[$id] = $this->boundedString($object['threadId'] ?? null, 255, MailImportStage::Inventory);
        }

        if (count($byId) !== count($ids)) {
            throw new MailImportFailure(MailImportStage::Inventory, MailImportCode::StateMismatch);
        }

        return array_map(fn (string $id): MessageReference => new MessageReference(
            $account->id,
            MailDriver::Jmap,
            $id,
            $byId[$id] ?? throw new MailImportFailure(MailImportStage::Inventory, MailImportCode::StateMismatch),
            ['inventory' => $inventory],
        ), $ids);
    }

    /**
     * @param  array{api_url: string, download_url: string, session_state: string}  $session
     */
    private function currentEmailState(MailAccount $account, array $session): string
    {
        $result = $this->call($account, $session, 'Email/get', [
            'accountId' => $account->provider_account_id,
            'ids' => [],
            'properties' => ['id'],
        ], 'state', MailImportStage::Inventory);

        return $this->boundedString($result['state'] ?? null, 255, MailImportStage::Inventory);
    }

    /**
     * @param  array{api_url: string, download_url: string, session_state: string}  $session
     * @param  list<string>  $ids
     * @param  array<string, array{id: string, name: string, role: string|null, sort_order: int, metadata: array<string, mixed>}>  $mailboxes
     * @return array{messages: list<ChangedMessageState>, unavailable: list<MessageReference>}
     */
    private function changedMessageStates(MailAccount $account, array $session, array $ids, array $mailboxes, string $emailState): array
    {
        if ($ids === []) {
            return ['messages' => [], 'unavailable' => []];
        }

        $result = $this->call($account, $session, 'Email/get', [
            'accountId' => $account->provider_account_id,
            'ids' => $ids,
            'properties' => [
                'id', 'blobId', 'threadId', 'mailboxIds', 'keywords', 'size', 'preview', 'hasAttachment',
            ],
        ], 'delta-state', MailImportStage::Retrieve);
        $objects = $this->objectList($result, MailImportStage::Retrieve);
        $notFound = $this->stringList($result['notFound'] ?? [], 255, MailImportStage::Retrieve);

        $byId = [];

        foreach ($objects as $email) {
            $id = $this->boundedString($email['id'] ?? null, 255, MailImportStage::Retrieve);
            $threadId = $this->boundedString($email['threadId'] ?? null, 255, MailImportStage::Retrieve);
            $blobId = $this->boundedString($email['blobId'] ?? null, 255, MailImportStage::Retrieve);
            $mailboxIds = $this->truthMap($email['mailboxIds'] ?? null, MailImportStage::Retrieve);
            $keywords = $this->truthMap($email['keywords'] ?? null, MailImportStage::Retrieve);
            $containers = [];

            foreach (array_keys($mailboxIds) as $mailboxId) {
                $native = $mailboxes[$mailboxId] ?? [
                    'id' => $mailboxId,
                    'name' => $mailboxId,
                    'role' => null,
                    'sort_order' => 0,
                    'metadata' => [],
                ];
                $containers[] = [
                    'provider_id' => $native['id'],
                    'name' => $native['name'],
                    'kind' => $native['role'] ?? 'mailbox',
                    'provider_metadata' => $native['metadata'],
                    'membership_metadata' => ['mailbox_id' => $mailboxId],
                ];
            }

            $byId[$id] = new ChangedMessageState(
                new MessageReference($account->id, MailDriver::Jmap, $id, $threadId),
                [
                    'blob_id' => $blobId,
                    'size' => $this->nonNegativeInteger($email['size'] ?? null, MailImportStage::Retrieve),
                    'keywords' => $keywords,
                    'mailbox_ids' => $mailboxIds,
                    'mailbox_state' => $this->mailboxState($keywords, $containers),
                    'has_attachment' => ($email['hasAttachment'] ?? false) === true,
                    'preview' => $this->optionalBoundedContentString($email['preview'] ?? null, 1024, MailImportStage::Retrieve),
                    'draft' => isset($keywords['$draft']),
                    'email_state' => $emailState,
                ],
                $containers,
            );
        }

        if (count($byId) + count($notFound) !== count($ids)
            || array_diff(array_keys($byId), $ids) !== []
            || array_diff($notFound, $ids) !== []
            || array_intersect(array_keys($byId), $notFound) !== []) {
            throw new MailImportFailure(MailImportStage::Retrieve, MailImportCode::MalformedPayload);
        }

        return [
            'messages' => array_values(array_filter(array_map(
                fn (string $id): ?ChangedMessageState => $byId[$id] ?? null,
                $ids,
            ))),
            'unavailable' => array_map(
                fn (string $id): MessageReference => new MessageReference($account->id, MailDriver::Jmap, $id),
                $notFound,
            ),
        ];
    }

    public function messageState(MailAccount $account, string $providerMessageId, MailboxChange $change): MessageState
    {
        $this->assertAccount($account);
        $session = $this->session($account);
        $mailboxes = $this->fetchMailboxes($account, $session)['mailboxes'];
        $roles = [];

        foreach ($this->rolesFor($change->action) as $role) {
            $roles[$role] = $this->mailboxWithRole($mailboxes, $role);
        }

        $container = $change->containerId;

        if ($container !== null && ! isset($mailboxes[$container])) {
            throw new MailWriteFailure(MailWriteCode::ContainerNotFound);
        }

        if ($container !== null && $mailboxes[$container]['role'] !== null) {
            throw new MailWriteFailure(MailWriteCode::UnsupportedContainer);
        }

        $keywordChange = in_array($change->action, [
            MailboxAction::MarkRead, MailboxAction::MarkUnread, MailboxAction::Star, MailboxAction::Unstar,
        ], true);
        $result = $this->call($account, $session, 'Email/get', [
            'accountId' => $account->provider_account_id,
            'ids' => [$providerMessageId],
            'properties' => $keywordChange ? ['id', 'mailboxIds', 'keywords'] : ['id', 'mailboxIds'],
        ], 'email', MailImportStage::Retrieve);
        $emailState = $this->boundedString($result['state'] ?? null, 255, MailImportStage::Retrieve);
        $email = $this->singleObject($result, $providerMessageId, MailImportStage::Retrieve);
        $mailboxIds = array_keys($this->truthMap($email['mailboxIds'] ?? null, MailImportStage::Retrieve));
        sort($mailboxIds);
        $keywords = $keywordChange ? array_keys($this->truthMap($email['keywords'] ?? null, MailImportStage::Retrieve)) : [];
        sort($keywords);
        $in = fn (string $role): bool => in_array($roles[$role], $mailboxIds, true);
        $evidence = ['mailbox_ids' => $mailboxIds];

        foreach ($roles as $role => $mailboxId) {
            $evidence[$role.'_mailbox_id'] = $mailboxId;
        }

        if ($keywordChange) {
            $evidence['keywords'] = $keywords;
        }

        if ($container !== null) {
            $evidence['container_id'] = $container;
        }

        $evidence['email_state'] = $emailState;

        return new MessageState(
            $account->id,
            MailDriver::Jmap,
            $providerMessageId,
            match ($change->action) {
                MailboxAction::MarkRead => in_array('$seen', $keywords, true),
                MailboxAction::MarkUnread => ! in_array('$seen', $keywords, true),
                MailboxAction::Star => in_array('$flagged', $keywords, true),
                MailboxAction::Unstar => ! in_array('$flagged', $keywords, true),
                MailboxAction::Archive => ! $in('inbox') && $in('archive'),
                MailboxAction::Unarchive => $in('inbox') && ! $in('archive'),
                MailboxAction::AddContainer => in_array($container, $mailboxIds, true),
                MailboxAction::RemoveContainer => ! in_array($container, $mailboxIds, true),
                MailboxAction::Trash => $in('trash'),
                MailboxAction::Untrash => ! $in('trash') && $in('inbox'),
                MailboxAction::Spam => $in('junk'),
                MailboxAction::NotSpam => ! $in('junk') && $in('inbox'),
            },
            $evidence,
        );
    }

    /** API tokens do not expire and the pre-write read cached the session, so nothing to prepare. */
    public function prepareWrite(MailAccount $account, int $validForSeconds): void
    {
        $this->assertAccount($account);
    }

    /**
     * Patches only the observed memberships or keyword of one email, and only
     * while the account's Email state still matches the observed read.
     */
    public function applyChange(MailAccount $account, MessageState $observed, MailboxChange $change): void
    {
        $this->assertAccount($account);
        $evidence = $observed->providerEvidence;
        $state = $evidence['email_state'] ?? null;
        $observedIds = $evidence['mailbox_ids'] ?? null;

        if ($observed->mailAccountId !== $account->id || $observed->driver !== MailDriver::Jmap
            || $observed->desiredStateHolds || ! is_string($state) || ! is_array($observedIds)
            || ! array_is_list($observedIds)) {
            throw new MailWriteFailure(MailWriteCode::UnsupportedState);
        }

        $roles = [];
        $mailboxIds = [];

        foreach ($this->rolesFor($change->action) as $role) {
            $roles[$role] = $this->writableMailboxId($evidence[$role.'_mailbox_id'] ?? null);
        }

        foreach ($observedIds as $mailboxId) {
            $mailboxIds[] = $this->writableMailboxId($mailboxId);
        }

        if ($change->containerId !== null) {
            $this->writableMailboxId($change->containerId);
        }

        if (count(array_unique($roles)) !== count($roles)) {
            throw new MailWriteFailure(MailWriteCode::UnsupportedState);
        }

        $in = fn (string $mailboxId): bool => in_array($mailboxId, $mailboxIds, true);
        $move = function (string $from, string $to) use ($mailboxIds): array {
            // Leave a role mailbox only from exactly that mailbox, so nothing else is dropped.
            if ($mailboxIds !== [$from]) {
                throw new MailWriteFailure(MailWriteCode::UnsupportedState);
            }

            return ['mailboxIds/'.$from => null, 'mailboxIds/'.$to => true];
        };
        $replaceAll = function (string $to) use ($mailboxIds): array {
            return array_fill_keys(array_map(fn (string $id): string => 'mailboxIds/'.$id, $mailboxIds), null)
                + ['mailboxIds/'.$to => true];
        };

        if ($change->action === MailboxAction::Untrash && ! $in($roles['trash'])) {
            throw new MailWriteFailure(MailWriteCode::NotInTrash);
        }

        $patch = match ($change->action) {
            MailboxAction::MarkRead => ['keywords/$seen' => true],
            MailboxAction::MarkUnread => ['keywords/$seen' => null],
            MailboxAction::Star => ['keywords/$flagged' => true],
            MailboxAction::Unstar => ['keywords/$flagged' => null],
            MailboxAction::Archive => $in($roles['inbox'])
                ? ['mailboxIds/'.$roles['inbox'] => null, 'mailboxIds/'.$roles['archive'] => true]
                : throw new MailWriteFailure(MailWriteCode::UnsupportedState),
            MailboxAction::Unarchive => $in($roles['archive'])
                ? ['mailboxIds/'.$roles['archive'] => null, 'mailboxIds/'.$roles['inbox'] => true]
                : throw new MailWriteFailure(MailWriteCode::UnsupportedState),
            MailboxAction::AddContainer => ['mailboxIds/'.$change->containerId => true],
            // An email must stay in at least one mailbox.
            MailboxAction::RemoveContainer => $mailboxIds === [$change->containerId]
                ? throw new MailWriteFailure(MailWriteCode::UnsupportedState)
                : ['mailboxIds/'.$change->containerId => null],
            MailboxAction::Trash => $replaceAll($roles['trash']),
            MailboxAction::Untrash => $move($roles['trash'], $roles['inbox']),
            MailboxAction::Spam => $replaceAll($roles['junk']),
            MailboxAction::NotSpam => $move($roles['junk'], $roles['inbox']),
        };

        $id = $observed->providerMessageId;
        // Session discovery and credential loading are pre-send; their failures propagate unwrapped.
        $session = $this->session($account);
        $this->credential($account, MailImportStage::Retrieve);
        // "restore" is the call ID the Trash restore has always sent.
        $callId = $change->action === MailboxAction::Untrash ? 'restore' : 'mutate';
        $result = $this->sendSet($account, $session, 'Email/set', [
            'accountId' => $account->provider_account_id,
            'ifInState' => $state,
            'update' => [$id => $patch],
        ], $callId);

        $updated = $result['updated'] ?? null;
        $notUpdated = $result['notUpdated'] ?? null;

        if (is_array($updated) && array_key_exists($id, $updated)) {
            return;
        }

        $error = is_array($notUpdated) ? ($notUpdated[$id] ?? null) : null;

        if (! is_array($error)) {
            throw new MailWriteFailure(MailWriteCode::ProviderFailed, true, MailImportCode::MalformedPayload);
        }

        throw $this->setItemFailure($error);
    }

    /** A draft is an email in the single Drafts-role mailbox with the $draft keyword. */
    public function draft(MailAccount $account, string $draftId): ?DraftRevision
    {
        $this->assertAccount($account);
        $session = $this->session($account);
        $draftsMailboxId = $this->mailboxWithRole($this->fetchMailboxes($account, $session)['mailboxes'], 'drafts');
        $result = $this->call($account, $session, 'Email/get', [
            'accountId' => $account->provider_account_id,
            'ids' => [$draftId],
            'properties' => ['id', 'blobId', 'threadId', 'mailboxIds', 'keywords'],
        ], 'draft', MailImportStage::Retrieve);
        $emailState = $this->boundedString($result['state'] ?? null, 255, MailImportStage::Retrieve);

        if (in_array($draftId, $this->stringList($result['notFound'] ?? [], 255, MailImportStage::Retrieve), true)) {
            return null;
        }

        $email = $this->singleObject($result, $draftId, MailImportStage::Retrieve);
        $mailboxIds = array_keys($this->truthMap($email['mailboxIds'] ?? null, MailImportStage::Retrieve));
        $keywords = $this->truthMap($email['keywords'] ?? null, MailImportStage::Retrieve);

        if (! in_array($draftsMailboxId, $mailboxIds, true) || ! isset($keywords['$draft'])) {
            return null;
        }

        sort($mailboxIds);
        $blobId = $this->boundedString($email['blobId'] ?? null, 255, MailImportStage::Retrieve);
        $bytes = $this->download($account, $session, $blobId);

        try {
            return new DraftRevision(
                $account->id,
                MailDriver::Jmap,
                $draftId,
                $draftId,
                DraftContent::messageIdOf($bytes),
                $this->boundedString($email['threadId'] ?? null, 255, MailImportStage::Retrieve),
                hash('sha256', $bytes),
                [
                    'blob_id' => $blobId,
                    'mailbox_ids' => $mailboxIds,
                    'drafts_mailbox_id' => $draftsMailboxId,
                    'email_state' => $emailState,
                ],
            );
        } catch (InvalidArgumentException) {
            throw new MailImportFailure(MailImportStage::Retrieve, MailImportCode::MalformedPayload);
        }
    }

    /** JMAP stores an imported blob unchanged, so identical bytes identify the content. */
    public function holdsContent(DraftRevision $draft, DraftContent $content): bool
    {
        return $draft->rawSha256 === $content->sha256 && $draft->messageId === $content->messageId;
    }

    /** A JMAP draft's ID is its email ID. */
    public function draftForMessage(MailAccount $account, string $providerMessageId): ?DraftRevision
    {
        return $this->draft($account, $providerMessageId);
    }

    public function draftsWithMessageId(MailAccount $account, string $messageId): array
    {
        $this->assertAccount($account);
        $session = $this->session($account);
        $draftsMailboxId = $this->mailboxWithRole($this->fetchMailboxes($account, $session)['mailboxes'], 'drafts');
        $result = $this->call($account, $session, 'Email/query', [
            'accountId' => $account->provider_account_id,
            'filter' => ['operator' => 'AND', 'conditions' => [
                ['inMailbox' => $draftsMailboxId],
                ['header' => ['Message-ID', '<'.$messageId.'>']],
            ]],
            'limit' => 10,
        ], 'drafts', MailImportStage::Retrieve);
        $drafts = [];

        foreach ($this->stringList($result['ids'] ?? null, 255, MailImportStage::Retrieve) as $id) {
            $draft = $this->draft($account, $id);

            if ($draft !== null && $draft->messageId === $messageId) {
                $drafts[] = $draft;
            }
        }

        return $drafts;
    }

    /** Upload the bytes as a blob. An unreferenced blob is not visible mailbox state. */
    public function stageDraft(MailAccount $account, DraftContent $content): string
    {
        $this->assertAccount($account);
        $this->session($account);
        $template = $this->uploadUrls[$account->id] ?? null;

        if (! is_string($template) || ! str_contains($template, '{accountId}')) {
            throw new MailImportFailure(MailImportStage::Retrieve, MailImportCode::StateMismatch);
        }

        $url = str_replace('{accountId}', rawurlencode($account->provider_account_id), $template);
        $this->assertFastmailApiUrl($url);

        if (config('mail-mirror.jmap.enabled') !== true) {
            throw new MailImportFailure(MailImportStage::Retrieve, MailImportCode::ProviderUnavailable);
        }

        [$credential, $stored] = $this->credential($account, MailImportStage::Retrieve);

        try {
            $response = $this->prepareRequest($this->http->withToken($credential->token())->acceptJson())
                ->withBody($content->bytes, 'message/rfc822')
                ->post($url);
        } catch (Throwable) {
            throw new MailImportFailure(MailImportStage::Retrieve, MailImportCode::ProviderUnavailable, true);
        }

        if ($response->status() === 401) {
            $this->revoke($account, $stored, MailImportStage::Retrieve);
            throw new MailImportFailure(MailImportStage::Retrieve, MailImportCode::AuthenticationFailed, httpStatus: 401);
        }

        if (! $response->successful()) {
            throw $this->httpFailure($response, MailImportStage::Retrieve);
        }

        $payload = $response->json();

        if (! is_array($payload) || ($payload['accountId'] ?? null) !== $account->provider_account_id
            || ($payload['size'] ?? null) !== $content->size()) {
            throw new MailImportFailure(MailImportStage::Retrieve, MailImportCode::MalformedPayload);
        }

        return $this->boundedString($payload['blobId'] ?? null, 255, MailImportStage::Retrieve);
    }

    public function createDraft(MailAccount $account, DraftContent $content, ?string $stagedBlobId, ?string $providerThreadId): string
    {
        $this->assertAccount($account);
        // The pre-write Message-ID lookup resolved the Drafts role and cached the mailboxes.
        $draftsMailboxId = $this->mailboxWithRole($this->mailboxes[$account->id] ?? [], 'drafts');

        return $this->importDraft($account, $this->writableBlobId($stagedBlobId), $draftsMailboxId, null);
    }

    /**
     * Import the new email, then destroy the observed one in a second request
     * sent only after the import created the email. Each request carries
     * ifInState, so neither applies over an unseen change. A single request
     * cannot make the destroy depend on the import succeeding.
     */
    public function replaceDraft(MailAccount $account, DraftRevision $observed, DraftContent $content, ?string $stagedBlobId, ?DraftRevision $imported): string
    {
        $this->assertAccount($account);
        $state = $observed->providerEvidence['email_state'] ?? null;
        $draftsMailboxId = $observed->providerEvidence['drafts_mailbox_id'] ?? null;

        if ($observed->mailAccountId !== $account->id || $observed->driver !== MailDriver::Jmap
            || ! is_string($state) || ! is_string($draftsMailboxId)
            || ($imported !== null && ($imported->mailAccountId !== $account->id || ! $this->holdsContent($imported, $content)))) {
            throw new MailWriteFailure(MailWriteCode::UnsupportedState);
        }

        if ($imported !== null) {
            // An earlier replace imported the new email and stopped before destroying the old one.
            $this->destroyEmail($account, $observed->draftId, $state, false);

            return $imported->draftId;
        }

        $newState = null;
        $draftId = $this->importDraft($account, $this->writableBlobId($stagedBlobId), $this->writableMailboxId($draftsMailboxId), $state, $newState);
        $this->destroyEmail($account, $observed->draftId, (string) $newState, true);

        return $draftId;
    }

    public function deleteDraft(MailAccount $account, DraftRevision $observed): void
    {
        $this->assertAccount($account);
        $state = $observed->providerEvidence['email_state'] ?? null;

        if ($observed->mailAccountId !== $account->id || $observed->driver !== MailDriver::Jmap || ! is_string($state)) {
            throw new MailWriteFailure(MailWriteCode::UnsupportedState);
        }

        $this->destroyEmail($account, $observed->draftId, $state, false);
    }

    /** Import one staged blob into Drafts as a seen draft, and return its email ID. */
    private function importDraft(MailAccount $account, string $blobId, string $draftsMailboxId, ?string $ifInState, ?string &$newState = null): string
    {
        $this->credential($account, MailImportStage::Retrieve);
        $result = $this->sendSet($account, $this->session($account), 'Email/import', array_filter([
            'accountId' => $account->provider_account_id,
            'ifInState' => $ifInState,
            'emails' => ['draft' => [
                'blobId' => $blobId,
                'mailboxIds' => [$draftsMailboxId => true],
                'keywords' => ['$draft' => true, '$seen' => true],
            ]],
        ], fn (mixed $value): bool => $value !== null), 'import');
        $created = $result['created'] ?? null;
        $email = is_array($created) ? ($created['draft'] ?? null) : null;
        $id = is_array($email) ? ($email['id'] ?? null) : null;
        $state = $result['newState'] ?? null;

        if (is_string($id) && $id !== '' && mb_strlen($id) <= 255 && is_string($state) && $state !== '') {
            $newState = $state;

            return $id;
        }

        $notCreated = $result['notCreated'] ?? null;
        $error = is_array($notCreated) ? ($notCreated['draft'] ?? null) : null;

        throw is_array($error)
            ? $this->setItemFailure($error)
            : new MailWriteFailure(MailWriteCode::ProviderFailed, true, MailImportCode::MalformedPayload);
    }

    /**
     * Destroy one email while the account's Email state is $ifInState. After an
     * import in the same replace, every failure is writeSent: the import applied.
     */
    private function destroyEmail(MailAccount $account, string $emailId, string $ifInState, bool $afterImport): void
    {
        try {
            $this->credential($account, MailImportStage::Retrieve);
            $result = $this->sendSet($account, $this->session($account), 'Email/set', [
                'accountId' => $account->provider_account_id,
                'ifInState' => $ifInState,
                'destroy' => [$emailId],
            ], 'destroy');
            $destroyed = $result['destroyed'] ?? null;

            if (is_array($destroyed) && in_array($emailId, $destroyed, true)) {
                return;
            }

            $notDestroyed = $result['notDestroyed'] ?? null;
            $error = is_array($notDestroyed) ? ($notDestroyed[$emailId] ?? null) : null;

            throw is_array($error)
                ? $this->setItemFailure($error)
                : new MailWriteFailure(MailWriteCode::ProviderFailed, true, MailImportCode::MalformedPayload);
        } catch (MailImportFailure|MailWriteFailure $failure) {
            if (! $afterImport) {
                throw $failure instanceof MailImportFailure ? MailWriteFailure::fromProvider($failure, false) : $failure;
            }

            throw new MailWriteFailure(
                MailWriteCode::ProviderFailed,
                true,
                $failure instanceof MailImportFailure ? $failure->safeCode : $failure->providerCode,
                $failure,
            );
        }
    }

    private function writableBlobId(?string $blobId): string
    {
        if (! is_string($blobId) || preg_match('/\A[A-Za-z0-9_-]{1,255}\z/', $blobId) !== 1) {
            throw new MailWriteFailure(MailWriteCode::UnsupportedState);
        }

        return $blobId;
    }

    /** A mailbox ID safe to place in an Email/set patch path. */
    private function writableMailboxId(mixed $id): string
    {
        if (! is_string($id) || preg_match('/\A[A-Za-z0-9_-]{1,255}\z/', $id) !== 1) {
            throw new MailWriteFailure(MailWriteCode::UnsupportedState);
        }

        return $id;
    }

    /** @return list<string> the mailbox roles $action reads and writes, in evidence order */
    private function rolesFor(MailboxAction $action): array
    {
        return match ($action) {
            MailboxAction::Archive, MailboxAction::Unarchive => ['inbox', 'archive'],
            MailboxAction::Trash => ['trash'],
            MailboxAction::Untrash => ['trash', 'inbox'],
            MailboxAction::Spam => ['junk'],
            MailboxAction::NotSpam => ['junk', 'inbox'],
            default => [],
        };
    }

    /**
     * Send one JMAP write method call without transport retries and return
     * its verified result. Every failure is a MailWriteFailure classified by
     * whether the provider may have applied the write.
     *
     * @param  array{api_url: string, download_url: string, session_state: string}  $session
     * @param  array<string, mixed>  $arguments
     * @param  list<string>  $capabilities
     * @return array<string, mixed>
     */
    private function sendSet(
        MailAccount $account,
        array $session,
        string $method,
        array $arguments,
        string $callId,
        array $capabilities = [self::CORE, self::MAIL],
    ): array {
        try {
            $payload = $this->jsonRequest($account, 'POST', $session['api_url'], [
                'using' => $capabilities,
                'methodCalls' => [[$method, $arguments, $callId]],
            ], MailImportStage::Retrieve, retryTransport: false);
        } catch (MailImportFailure $failure) {
            throw MailWriteFailure::fromProvider($failure, true);
        }

        // Like call(): a changed session state means the cached session may be stale.
        // The write outcome is already decided, so drop the cache for the confirming
        // read to rediscover rather than failing a write that may have applied.
        if (($payload['sessionState'] ?? null) !== $session['session_state']) {
            unset($this->sessions[$account->id], $this->mailboxes[$account->id], $this->profileMetadata[$account->id]);
        }

        $responses = $payload['methodResponses'] ?? null;
        $response = is_array($responses) && count($responses) === 1 ? ($responses[0] ?? null) : null;

        if (! is_array($response) || count($response) !== 3 || ! is_array($response[1] ?? null)
            || ($response[2] ?? null) !== $callId) {
            throw new MailWriteFailure(MailWriteCode::ProviderFailed, true, MailImportCode::MalformedPayload);
        }

        /** @var array<string, mixed> $result */
        $result = $response[1];

        if ($response[0] === 'error') {
            // RFC 8620 method errors reject the whole call. Server failures leave state
            // undefined, and an unrecognized type might too, so both count as possibly applied.
            throw match ($result['type'] ?? null) {
                'stateMismatch', 'accountNotFound' => new MailWriteFailure(MailWriteCode::ProviderFailed, false, MailImportCode::StateMismatch),
                'forbidden', 'accountNotSupportedByMethod', 'accountReadOnly' => new MailWriteFailure(MailWriteCode::ProviderFailed, false, MailImportCode::PermissionDenied),
                'unknownMethod', 'invalidArguments', 'invalidResultReference', 'requestTooLarge' => new MailWriteFailure(MailWriteCode::ProviderFailed, false, MailImportCode::UnexpectedFailure),
                'serverFail', 'serverPartialFail', 'serverUnavailable' => new MailWriteFailure(MailWriteCode::ProviderFailed, true, MailImportCode::ProviderUnavailable),
                default => new MailWriteFailure(MailWriteCode::ProviderFailed, true, MailImportCode::UnexpectedFailure),
            };
        }

        if ($response[0] !== $method || ($result['accountId'] ?? null) !== $account->provider_account_id) {
            throw new MailWriteFailure(MailWriteCode::ProviderFailed, true, MailImportCode::MalformedPayload);
        }

        return $result;
    }

    /**
     * An RFC 8620 SetError for one object. The provider rejected that object,
     * so the write did not apply to it.
     *
     * @param  array<mixed>  $error
     */
    private function setItemFailure(array $error): MailWriteFailure
    {
        return match ($error['type'] ?? null) {
            'notFound' => new MailWriteFailure(MailWriteCode::MessageNotFound, false, MailImportCode::MessageUnavailable),
            'forbidden' => new MailWriteFailure(MailWriteCode::ProviderFailed, false, MailImportCode::PermissionDenied),
            default => new MailWriteFailure(MailWriteCode::ProviderFailed, false, MailImportCode::UnexpectedFailure),
        };
    }

    /** @param array<string, array{id: string, name: string, role: string|null, sort_order: int, metadata: array<string, mixed>}> $mailboxes */
    private function mailboxWithRole(array $mailboxes, string $role): string
    {
        $matches = array_values(array_filter($mailboxes, fn (array $mailbox): bool => $mailbox['role'] === $role));

        if (count($matches) !== 1) {
            throw new MailWriteFailure(MailWriteCode::AmbiguousMailboxRole);
        }

        return $matches[0]['id'];
    }

    /** @return array{api_url: string, download_url: string, session_state: string} */
    private function session(MailAccount $account, bool $refresh = false): array
    {
        if (! $refresh && isset($this->sessions[$account->id])) {
            return $this->sessions[$account->id];
        }

        $payload = $this->jsonRequest($account, 'GET', self::SESSION_URL, null, MailImportStage::Inventory);
        $capabilities = $payload['capabilities'] ?? null;
        $accounts = $payload['accounts'] ?? null;
        $apiUrl = $payload['apiUrl'] ?? null;
        $downloadUrl = $payload['downloadUrl'] ?? null;
        $uploadUrl = $payload['uploadUrl'] ?? null;
        $state = $payload['state'] ?? null;

        $nativeAccount = is_array($accounts) ? ($accounts[$account->provider_account_id] ?? null) : null;
        $accountCapabilities = is_array($nativeAccount) ? ($nativeAccount['accountCapabilities'] ?? null) : null;

        if (! is_array($capabilities) || ! array_key_exists(self::CORE, $capabilities)
            || ! array_key_exists(self::MAIL, $capabilities) || ! array_key_exists(self::SUBMISSION, $capabilities)
            || ! is_array($accounts)
            || ! is_array($nativeAccount) || ! is_array($accountCapabilities)
            || ! array_key_exists(self::MAIL, $accountCapabilities)
            || ! array_key_exists(self::SUBMISSION, $accountCapabilities)
            || ! is_string($apiUrl) || ! is_string($downloadUrl) || ! is_string($state)
            || $state === '' || mb_strlen($state) > 255) {
            throw new MailImportFailure(MailImportStage::Inventory, MailImportCode::StateMismatch);
        }

        $this->assertFastmailApiUrl($apiUrl);
        $this->assertFastmailDownloadUrl($downloadUrl);
        $this->uploadUrls[$account->id] = is_string($uploadUrl) ? $uploadUrl : null;

        $session = [
            'api_url' => $apiUrl,
            'download_url' => $downloadUrl,
            'session_state' => $state,
        ];

        if (($this->sessions[$account->id] ?? null) !== $session) {
            unset($this->mailboxes[$account->id], $this->profileMetadata[$account->id]);
        }

        return $this->sessions[$account->id] = $session;
    }

    /**
     * @param  array{api_url: string, download_url: string, session_state: string}  $session
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function call(MailAccount $account, array &$session, string $method, array $arguments, string $callId, MailImportStage $stage): array
    {
        $payload = $this->jsonRequest($account, 'POST', $session['api_url'], [
            'using' => [self::CORE, self::MAIL, self::SUBMISSION],
            'methodCalls' => [[$method, $arguments, $callId]],
        ], $stage);
        $responseSessionState = $this->boundedString($payload['sessionState'] ?? null, 255, $stage);

        if ($responseSessionState !== $session['session_state']) {
            $freshSession = $this->session($account, true);

            if ($freshSession['api_url'] !== $session['api_url']
                || $freshSession['download_url'] !== $session['download_url']) {
                if ($stage === MailImportStage::Inventory) {
                    throw new InventoryRestartRequired($this->resourceRestartCursor($account, $freshSession));
                }

                throw new MailImportFailure($stage, MailImportCode::StateMismatch, true);
            }

            $session = $freshSession;
        }

        $responses = $payload['methodResponses'] ?? null;
        $response = is_array($responses) ? ($responses[0] ?? null) : null;

        if (! is_array($response) || count($response) !== 3 || ! is_string($response[0] ?? null)
            || ! is_array($response[1] ?? null) || ($response[2] ?? null) !== $callId) {
            throw new MailImportFailure($stage, MailImportCode::MalformedPayload);
        }

        if ($response[0] === 'error') {
            $type = $response[1]['type'] ?? null;

            throw match ($type) {
                'cannotCalculateChanges' => new MailImportFailure($stage, MailImportCode::HistoryExpired),
                'stateMismatch', 'accountNotFound' => new MailImportFailure($stage, MailImportCode::StateMismatch),
                'anchorNotFound' => new MailImportFailure($stage, MailImportCode::StateMismatch, cursorRejected: true),
                'forbidden', 'accountNotSupportedByMethod' => new MailImportFailure($stage, MailImportCode::PermissionDenied),
                'notFound' => new MailImportFailure($stage, MailImportCode::MessageUnavailable),
                'serverFail', 'serverPartialFail' => new MailImportFailure($stage, MailImportCode::ProviderUnavailable, true),
                default => new MailImportFailure($stage, MailImportCode::MalformedPayload),
            };
        }

        if ($response[0] !== $method) {
            throw new MailImportFailure($stage, MailImportCode::MalformedPayload);
        }

        /** @var array<string, mixed> $result */
        $result = $response[1];

        if (array_key_exists('accountId', $arguments)
            && ($result['accountId'] ?? null) !== $account->provider_account_id) {
            throw new MailImportFailure($stage, MailImportCode::StateMismatch);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     */
    private function jsonRequest(
        MailAccount $account,
        string $method,
        string $url,
        ?array $body,
        MailImportStage $stage,
        bool $retryTransport = true,
    ): array {
        if (config('mail-mirror.jmap.enabled') !== true) {
            throw new MailImportFailure($stage, MailImportCode::ProviderUnavailable);
        }

        $this->assertFastmailApiUrl($url);
        [$credential, $stored] = $this->credential($account, $stage);
        $response = $this->sendWithRetries($credential, $method, $url, $body, $stage, $retryTransport);

        if ($response->status() === 401) {
            $this->revoke($account, $stored, $stage);
            throw new MailImportFailure($stage, MailImportCode::AuthenticationFailed, httpStatus: 401);
        }

        if (! $response->successful()) {
            throw $this->httpFailure($response, $stage);
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new MailImportFailure($stage, MailImportCode::MalformedPayload);
        }

        /** @var array<string, mixed> $payload */
        return $payload;
    }

    /**
     * Writes pass $retryTransport false so a provider write is sent at most once.
     *
     * @param  array<string, mixed>|null  $body
     */
    private function sendWithRetries(
        ApiTokenCredential $credential,
        string $method,
        string $url,
        ?array $body,
        MailImportStage $stage,
        bool $retryTransport = true,
    ): Response {
        $maximum = config('mail-mirror.jmap.request_max_attempts', 3);
        $maximum = ! $retryTransport ? 1 : (is_int($maximum) && $maximum >= 1 && $maximum <= 5 ? $maximum : 3);

        for ($attempt = 1; $attempt <= $maximum; $attempt++) {
            $this->budget?->claimHttpRequest();

            try {
                $pending = $this->prepareRequest($this->http->withToken($credential->token())->acceptJson());
                $response = $pending->send($method, $url, $body === null ? [] : ['json' => $body]);
                $this->budget?->recordDownloadedBytes(strlen($response->body()));
            } catch (Throwable $failure) {
                if (($budgetFailure = $this->budgetFailure($failure)) !== null) {
                    throw $budgetFailure;
                }

                if ($attempt === $maximum) {
                    throw new MailImportFailure($stage, MailImportCode::ProviderUnavailable, true, $attempt);
                }

                continue;
            }

            if (! in_array($response->status(), [408, 425, 429, 500, 502, 503, 504], true) || $attempt === $maximum) {
                return $response;
            }

            $retryAfter = $this->retryAfter($response);

            if ($retryAfter !== null) {
                Sleep::sleep($retryAfter);
            }
        }

        throw new MailImportFailure($stage, MailImportCode::ProviderUnavailable, true);
    }

    private function prepareRequest(PendingRequest $request): PendingRequest
    {
        if ($this->budget === null) {
            return $request->timeout($this->timeout());
        }

        $remainingBytes = $this->budget->remainingDownloadedBytes();
        $budget = $this->budget;

        return $request->withoutRedirecting()
            ->timeout(min($this->timeout(), $budget->remainingElapsedSeconds()))
            ->withOptions([
                'progress' => static function (int $downloadTotal, int $downloadedBytes) use ($budget, $remainingBytes): bool {
                    if ($downloadedBytes > $remainingBytes) {
                        $budget->recordDownloadedBytes($downloadedBytes);
                    }

                    return false;
                },
            ]);
    }

    private function budgetFailure(Throwable $failure): ?SyncBudgetExhausted
    {
        do {
            if ($failure instanceof SyncBudgetExhausted) {
                return $failure;
            }

            $failure = $failure->getPrevious();
        } while ($failure !== null);

        return null;
    }

    /** @param array{api_url: string, download_url: string, session_state: string} $session */
    private function download(MailAccount $account, array $session, string $blobId): string
    {
        $url = str_replace(
            ['{accountId}', '{blobId}', '{name}', '{type}'],
            [rawurlencode($account->provider_account_id), rawurlencode($blobId), 'message.eml', rawurlencode('message/rfc822')],
            $session['download_url'],
        );

        if (str_contains($url, '{')) {
            throw new MailImportFailure(MailImportStage::Retrieve, MailImportCode::MalformedPayload);
        }

        $this->assertFastmailDownloadUrl($url);
        [$credential, $stored] = $this->credential($account, MailImportStage::Retrieve);
        $response = $this->sendWithRetries($credential, 'GET', $url, null, MailImportStage::Retrieve);

        if ($response->status() === 401) {
            $this->revoke($account, $stored, MailImportStage::Retrieve);
            throw new MailImportFailure(MailImportStage::Retrieve, MailImportCode::AuthenticationFailed);
        }

        if (! $response->successful()) {
            throw $this->httpFailure($response, MailImportStage::Retrieve);
        }

        $bytes = $response->body();

        if (strlen($bytes) > $this->maxRawBytes()) {
            throw new MailImportFailure(MailImportStage::Retrieve, MailImportCode::MalformedPayload);
        }

        return $bytes;
    }

    /** @return array{ApiTokenCredential, MailAccountCredential} */
    private function credential(MailAccount $account, MailImportStage $stage): array
    {
        $stored = $account->credential()->first();

        if (! $stored instanceof MailAccountCredential) {
            throw new MailImportFailure($stage, MailImportCode::AuthenticationFailed);
        }

        try {
            $credential = $this->connections->credentials($account, $stored);
        } catch (Throwable) {
            throw new MailImportFailure($stage, MailImportCode::AuthenticationFailed);
        }

        if (! $credential instanceof ApiTokenCredential) {
            throw new MailImportFailure($stage, MailImportCode::PermissionDenied);
        }

        return [$credential, $stored];
    }

    private function revoke(MailAccount $account, MailAccountCredential $stored, MailImportStage $stage): void
    {
        try {
            $this->connections->revoke($account, $stored, $stored->version);
        } catch (Throwable) {
            throw new MailImportFailure($stage, MailImportCode::AuthenticationFailed);
        }
    }

    private function httpFailure(Response $response, MailImportStage $stage): MailImportFailure
    {
        return match ($response->status()) {
            401 => new MailImportFailure($stage, MailImportCode::AuthenticationFailed, httpStatus: $response->status()),
            403 => new MailImportFailure($stage, MailImportCode::PermissionDenied, httpStatus: $response->status()),
            404 => new MailImportFailure($stage, MailImportCode::MessageUnavailable, httpStatus: $response->status()),
            408, 425, 429 => new MailImportFailure($stage, MailImportCode::RateLimited, true, retryAfterSeconds: $this->retryAfter($response), httpStatus: $response->status()),
            500, 502, 503, 504 => new MailImportFailure($stage, MailImportCode::ProviderUnavailable, true, httpStatus: $response->status()),
            default => new MailImportFailure($stage, MailImportCode::UnexpectedFailure, httpStatus: $response->status()),
        };
    }

    private function retryAfter(Response $response): ?int
    {
        $value = $response->header('Retry-After');

        if (! ctype_digit($value)) {
            return null;
        }

        $seconds = (int) $value;

        return $seconds >= 1 && $seconds <= 300 ? $seconds : null;
    }

    /**
     * @param  array{api_url: string, download_url: string, session_state: string}  $session
     * @return array{mailboxes: array<string, array{id: string, name: string, role: string|null, sort_order: int, metadata: array<string, mixed>}>, state: string}
     */
    private function fetchMailboxes(MailAccount $account, array $session): array
    {
        $parsed = $this->parseMailboxes($this->call($account, $session, 'Mailbox/get', [
            'accountId' => $account->provider_account_id,
            'ids' => null,
        ], 'mailboxes', MailImportStage::Retrieve));
        $this->mailboxes[$account->id] = $parsed['mailboxes'];

        return $parsed;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array{mailboxes: array<string, array{id: string, name: string, role: string|null, sort_order: int, metadata: array<string, mixed>}>, state: string}
     */
    private function parseMailboxes(array $result): array
    {
        $state = $this->boundedString($result['state'] ?? null, 255, MailImportStage::Inventory);
        $list = $this->objectList($result, MailImportStage::Inventory);
        $mailboxes = [];

        if (count($list) > 10000) {
            throw new MailImportFailure(MailImportStage::Inventory, MailImportCode::MalformedPayload);
        }

        foreach ($list as $native) {
            $id = $this->boundedString($native['id'] ?? null, 255, MailImportStage::Inventory);
            $name = $this->boundedString($native['name'] ?? null, 255, MailImportStage::Inventory);
            $role = $this->optionalBoundedString($native['role'] ?? null, 255, MailImportStage::Inventory);
            $sortOrder = $this->nonNegativeInteger($native['sortOrder'] ?? 0, MailImportStage::Inventory);
            $mailboxes[$id] = [
                'id' => $id,
                'name' => $name,
                'role' => $role,
                'sort_order' => $sortOrder,
                'metadata' => [
                    'role' => $role,
                    'sort_order' => $sortOrder,
                    'parent_id' => $this->optionalBoundedString($native['parentId'] ?? null, 255, MailImportStage::Inventory),
                    'is_subscribed' => ($native['isSubscribed'] ?? false) === true,
                ],
            ];
        }

        return ['mailboxes' => $mailboxes, 'state' => $state];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return list<MailboxIdentity>
     */
    private function parseIdentities(array $result): array
    {
        $raw = $result['list'] ?? null;

        if (is_array($raw) && count($raw) > $this->resourceLimit()) {
            throw new MailImportFailure(MailImportStage::Inventory, MailImportCode::MalformedPayload);
        }

        return array_map(function (array $native): MailboxIdentity {
            try {
                return new MailboxIdentity(
                    $this->boundedString($native['id'] ?? null, 255, MailImportStage::Inventory),
                    $this->boundedString($native['email'] ?? null, 255, MailImportStage::Inventory),
                    $this->optionalBoundedContentString($native['name'] ?? null, 255, MailImportStage::Inventory),
                    [
                        'reply_to' => $this->addressList($native['replyTo'] ?? [], MailImportStage::Inventory),
                        'bcc' => $this->addressList($native['bcc'] ?? [], MailImportStage::Inventory),
                        'text_signature' => $this->optionalBoundedContentString($native['textSignature'] ?? null, 100000, MailImportStage::Inventory),
                        'html_signature' => $this->optionalBoundedContentString($native['htmlSignature'] ?? null, 100000, MailImportStage::Inventory),
                    ],
                );
            } catch (InvalidArgumentException) {
                throw new MailImportFailure(MailImportStage::Inventory, MailImportCode::MalformedPayload);
            }
        }, $this->objectList($result, MailImportStage::Inventory));
    }

    /**
     * @param  array<string, mixed>  $email
     * @return list<array{role: string, address: string, name?: string, provider_metadata: array<string, mixed>}>
     */
    private function participants(array $email): array
    {
        $participants = [];

        foreach (['sender' => 'sender', 'from' => 'from', 'replyTo' => 'reply_to', 'to' => 'to', 'cc' => 'cc', 'bcc' => 'bcc'] as $field => $role) {
            foreach ($this->addressList($email[$field] ?? [], MailImportStage::Retrieve) as $address) {
                $participants[] = array_filter([
                    'role' => $role,
                    'address' => $address['email'],
                    'name' => $address['name'],
                    'provider_metadata' => ['jmap_field' => $field],
                ], fn (mixed $value): bool => $value !== null);
            }
        }

        return $participants;
    }

    /** @return list<array{provider_id: string, filename?: string, media_type?: string, byte_size?: int, content_id?: string, is_inline?: bool, provider_metadata: array<string, mixed>}> */
    private function attachments(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > 1000) {
            throw new MailImportFailure(MailImportStage::Retrieve, MailImportCode::MalformedPayload);
        }

        $attachments = [];

        foreach ($value as $native) {
            if (! is_array($native)) {
                throw new MailImportFailure(MailImportStage::Retrieve, MailImportCode::MalformedPayload);
            }

            $partId = $this->boundedString($native['partId'] ?? null, 255, MailImportStage::Retrieve);
            $blobId = $this->boundedString($native['blobId'] ?? null, 255, MailImportStage::Retrieve);
            $disposition = $this->optionalBoundedString($native['disposition'] ?? null, 255, MailImportStage::Retrieve);
            $attachments[] = array_filter([
                'provider_id' => $partId,
                'filename' => $this->optionalBoundedContentString($native['name'] ?? null, 255, MailImportStage::Retrieve),
                'media_type' => $this->optionalBoundedString($native['type'] ?? null, 255, MailImportStage::Retrieve),
                'byte_size' => $this->nonNegativeInteger($native['size'] ?? null, MailImportStage::Retrieve),
                'content_id' => $this->optionalBoundedContentString($native['cid'] ?? null, 255, MailImportStage::Retrieve),
                'is_inline' => $disposition === 'inline',
                'provider_metadata' => ['blob_id' => $blobId, 'part_id' => $partId, 'disposition' => $disposition],
            ], fn (mixed $entry, string $key): bool => $key === 'provider_metadata' || $entry !== null, ARRAY_FILTER_USE_BOTH);
        }

        return $attachments;
    }

    /** @return list<array{name: string, value: string}> */
    private function rawHeaders(string $raw): array
    {
        $head = preg_split("/\r?\n\r?\n/", $raw, 2)[0] ?? '';

        if (strlen($head) > $this->maxRawBytes()) {
            throw new MailImportFailure(MailImportStage::Parse, MailImportCode::MalformedPayload);
        }

        $lines = preg_split('/\r?\n/', $head);

        if (! is_array($lines)) {
            throw new MailImportFailure(MailImportStage::Parse, MailImportCode::MalformedPayload);
        }

        $headers = [];

        foreach ($lines as $line) {
            if (($line[0] ?? '') === ' ' || ($line[0] ?? '') === "\t") {
                if ($headers === []) {
                    throw new MailImportFailure(MailImportStage::Parse, MailImportCode::MalformedPayload);
                }

                $headers[array_key_last($headers)]['value'] .= ' '.ltrim($line);

                continue;
            }

            $separator = strpos($line, ':');

            if ($separator === false || $separator === 0) {
                throw new MailImportFailure(MailImportStage::Parse, MailImportCode::MalformedPayload);
            }

            $name = substr($line, 0, $separator);
            $value = ltrim(substr($line, $separator + 1));
            $headers[] = ['name' => $name, 'value' => $value];

            if (count($headers) > 10000) {
                throw new MailImportFailure(MailImportStage::Parse, MailImportCode::MalformedPayload);
            }
        }

        /** @var list<array{name: string, value: string}> $headers */
        return $headers;
    }

    /** @return list<array{email: string, name: string|null}> */
    private function addressList(mixed $value, MailImportStage $stage): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > 10000) {
            throw new MailImportFailure($stage, MailImportCode::MalformedPayload);
        }

        return array_map(fn (mixed $address): array => is_array($address) ? [
            'email' => $this->boundedString($address['email'] ?? null, 255, $stage),
            'name' => $this->optionalBoundedContentString($address['name'] ?? null, 255, $stage),
        ] : throw new MailImportFailure($stage, MailImportCode::MalformedPayload), $value);
    }

    /** @return array<string, true> */
    private function truthMap(mixed $value, MailImportStage $stage): array
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value)) || count($value) > 10000) {
            throw new MailImportFailure($stage, MailImportCode::MalformedPayload);
        }

        $result = [];

        foreach ($value as $key => $present) {
            if (! is_string($key) || $key === '' || mb_strlen($key) > 255 || $present !== true) {
                throw new MailImportFailure($stage, MailImportCode::MalformedPayload);
            }

            $result[$key] = true;
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return list<array<string, mixed>>
     */
    private function objectList(array $result, MailImportStage $stage): array
    {
        $list = $result['list'] ?? null;

        if (! is_array($list) || ! array_is_list($list) || array_filter($list, fn (mixed $item): bool => ! is_array($item)) !== []) {
            throw new MailImportFailure($stage, MailImportCode::MalformedPayload);
        }

        /** @var list<array<string, mixed>> $list */
        return $list;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function singleObject(array $result, string $id, MailImportStage $stage): array
    {
        $list = $this->objectList($result, $stage);
        $notFound = $result['notFound'] ?? [];

        if ($list === [] && is_array($notFound) && in_array($id, $notFound, true)) {
            throw new MailImportFailure($stage, MailImportCode::MessageUnavailable);
        }

        if (count($list) !== 1 || ($list[0]['id'] ?? null) !== $id) {
            throw new MailImportFailure($stage, MailImportCode::MalformedPayload);
        }

        return $list[0];
    }

    /** @return list<string> */
    private function stringList(mixed $value, int $maximumLength, MailImportStage $stage): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > 10000) {
            throw new MailImportFailure($stage, MailImportCode::MalformedPayload);
        }

        return array_map(fn (mixed $item): string => $this->boundedString($item, $maximumLength, $stage), $value);
    }

    private function boundedString(mixed $value, int $maximum, MailImportStage $stage): string
    {
        if (! is_string($value) || $value === '' || mb_strlen($value) > $maximum) {
            throw new MailImportFailure($stage, MailImportCode::MalformedPayload);
        }

        return $value;
    }

    private function optionalBoundedString(mixed $value, int $maximum, MailImportStage $stage): ?string
    {
        if ($value === null) {
            return null;
        }

        return $this->boundedString($value, $maximum, $stage);
    }

    private function optionalBoundedContentString(mixed $value, int $maximum, MailImportStage $stage): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value) || mb_strlen($value) > $maximum) {
            throw new MailImportFailure($stage, MailImportCode::MalformedPayload);
        }

        return $value;
    }

    private function nonNegativeInteger(mixed $value, MailImportStage $stage): int
    {
        if (! is_int($value) || $value < 0) {
            throw new MailImportFailure($stage, MailImportCode::MalformedPayload);
        }

        return $value;
    }

    private function date(mixed $value, MailImportStage $stage): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value) || strlen($value) > 64) {
            throw new MailImportFailure($stage, MailImportCode::MalformedPayload);
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            throw new MailImportFailure($stage, MailImportCode::MalformedPayload);
        }
    }

    /**
     * @param  array{api_url: string, download_url: string, session_state: string}  $session
     * @param  array<string, mixed>  $metadata
     */
    private function profile(MailAccount $account, array $session, array $metadata): AccountProfile
    {
        $current = is_array($account->provider_metadata) ? $account->provider_metadata : [];

        if (! isset($this->profileMetadata[$account->id])) {
            $this->profileMetadata[$account->id] = array_filter([
                'session_state' => $session['session_state'],
                'email_state' => $current['email_state'] ?? null,
                'mailbox_state' => $current['mailbox_state'] ?? null,
                'identity_state' => $current['identity_state'] ?? null,
            ], fn (mixed $value): bool => is_string($value));
        }

        foreach ($metadata as $key => $value) {
            if (is_string($value)) {
                $this->profileMetadata[$account->id][$key] = $value;
            }
        }

        return new AccountProfile(
            $account->provider_account_id,
            providerMetadata: $this->profileMetadata[$account->id],
        );
    }

    /**
     * @return array{phase: string, account_id: string, session_state: string, email_state: string|null, anchor: string|null, progress: int}
     *
     * @throws InventoryRestartRequired When a bounded legacy (position-based) full-scan cursor is decoded.
     */
    private function decodeCursor(string $cursor): array
    {
        if (strlen($cursor) > 8192 || ! preg_match('/^[A-Za-z0-9_-]+$/', $cursor)) {
            throw new MailImportFailure(MailImportStage::Inventory, MailImportCode::StateMismatch);
        }

        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        $state = is_string($decoded) ? json_decode($decoded, true) : null;

        if (! is_array($state)) {
            throw new MailImportFailure(MailImportStage::Inventory, MailImportCode::StateMismatch);
        }

        $legacyAccountId = $state['account_id'] ?? null;
        $legacySessionState = $state['session_state'] ?? null;
        $legacyEmailState = $state['email_state'] ?? null;
        $legacyQueryState = $state['query_state'] ?? null;
        $legacyPosition = $state['position'] ?? null;

        // A cursor persisted by the previous position-based full-scan schema
        // would permanently strand the import: every retry re-fails on the same
        // cursor. Recognize its exact bounded shape and restart with a fresh
        // full cursor derived from the SAME cursor's preserved email_state (a
        // newer state must not be captured, so deletion evidence recorded
        // during the abandoned scan stays replayable). Anything else that does
        // not match the current schema — including tampered hybrids — stays a
        // terminal StateMismatch below.
        if (! array_key_exists('anchor', $state) && ! array_key_exists('progress', $state)
            && ($state['phase'] ?? null) === 'full'
            && is_string($legacyAccountId) && $legacyAccountId !== '' && mb_strlen($legacyAccountId) <= 255
            && is_string($legacySessionState) && $legacySessionState !== '' && mb_strlen($legacySessionState) <= 255
            && is_string($legacyEmailState) && $legacyEmailState !== '' && mb_strlen($legacyEmailState) <= 255
            && is_string($legacyQueryState) && $legacyQueryState !== '' && mb_strlen($legacyQueryState) <= 255
            && is_int($legacyPosition) && $legacyPosition >= 0) {
            throw new InventoryRestartRequired($this->encodeCursor([
                'phase' => 'full',
                'account_id' => $legacyAccountId,
                'session_state' => $legacySessionState,
                'email_state' => $legacyEmailState,
                'anchor' => null,
                'progress' => 0,
            ]));
        }

        if (! in_array($state['phase'] ?? null, ['resources', 'changes', 'full'], true)
            || ! is_string($state['account_id'] ?? null) || $state['account_id'] === ''
            || ! is_string($state['session_state'] ?? null) || $state['session_state'] === ''
            || (! is_string($state['email_state'] ?? null) && ($state['email_state'] ?? null) !== null)
            || (! is_string($state['anchor'] ?? null) && ($state['anchor'] ?? null) !== null)
            || ! is_int($state['progress'] ?? null) || $state['progress'] < 0
            || ($state['phase'] === 'resources' && ($state['email_state'] !== null || $state['anchor'] !== null || $state['progress'] !== 0))
            || ($state['phase'] === 'changes' && (($state['email_state'] ?? '') === '' || $state['anchor'] !== null || $state['progress'] !== 0))
            || ($state['phase'] === 'full' && (($state['email_state'] ?? '') === ''
                || ($state['progress'] === 0 && $state['anchor'] !== null)
                || ($state['progress'] > 0 && ($state['anchor'] ?? '') === '')))) {
            throw new MailImportFailure(MailImportStage::Inventory, MailImportCode::StateMismatch);
        }

        /** @var array{phase: string, account_id: string, session_state: string, email_state: string|null, anchor: string|null, progress: int} $state */
        return $state;
    }

    /** @param array{phase: string, account_id: string, session_state: string, email_state: string|null, anchor: string|null, progress: int} $state */
    private function encodeCursor(array $state): string
    {
        foreach ([$state['account_id'], $state['session_state'], $state['email_state'], $state['anchor']] as $value) {
            if ($value !== null && mb_strlen($value) > 255) {
                throw new MailImportFailure(MailImportStage::Inventory, MailImportCode::MalformedPayload);
            }
        }

        return rtrim(strtr(base64_encode(json_encode($state, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    /**
     * @param  array{api_url: string, download_url: string, session_state: string}  $session
     * @return array{phase: string, account_id: string, session_state: string, email_state: string, anchor: null, progress: int}
     */
    private function fullState(MailAccount $account, array $session, string $emailState): array
    {
        return [
            'phase' => 'full',
            'account_id' => $account->provider_account_id,
            'session_state' => $session['session_state'],
            'email_state' => $emailState,
            'anchor' => null,
            'progress' => 0,
        ];
    }

    /** @param array{api_url: string, download_url: string, session_state: string} $session */
    private function deltaCursor(MailAccount $account, array $session, string $emailState): string
    {
        return $this->encodeCursor([
            'phase' => 'changes',
            'account_id' => $account->provider_account_id,
            'session_state' => $session['session_state'],
            'email_state' => $emailState,
            'anchor' => null,
            'progress' => 0,
        ]);
    }

    /** @param array{api_url: string, download_url: string, session_state: string} $session */
    private function freshFullCursor(MailAccount $account, array $session): string
    {
        return $this->encodeCursor($this->fullState($account, $session, $this->currentEmailState($account, $session)));
    }

    /** @param array{api_url: string, download_url: string, session_state: string} $session */
    private function resourceRestartCursor(MailAccount $account, array $session): string
    {
        return $this->encodeCursor([
            'phase' => 'resources',
            'account_id' => $account->provider_account_id,
            'session_state' => $session['session_state'],
            'email_state' => null,
            'anchor' => null,
            'progress' => 0,
        ]);
    }

    /** @return resource */
    private function stream(string $contents): mixed
    {
        $stream = fopen('php://temp', 'w+b');

        if (! is_resource($stream) || fwrite($stream, $contents) !== strlen($contents)) {
            throw new MailImportFailure(MailImportStage::Storage, MailImportCode::UnexpectedFailure);
        }

        rewind($stream);

        return $stream;
    }

    private function withinBudget(?SyncWorkBudget $budget, Closure $operation): mixed
    {
        $previous = $this->budget;
        $this->budget = $budget;

        try {
            $budget?->assertElapsed();

            return $operation();
        } finally {
            $this->budget = $previous;
        }
    }

    private function assertAccount(MailAccount $account): void
    {
        if (! $account->exists || $account->driver !== MailDriver::Jmap) {
            throw new MailImportFailure(MailImportStage::Inventory, MailImportCode::StateMismatch);
        }

        $query = $account->newQuery()->whereKey($account->id)
            ->where('driver', MailDriver::Jmap->value)
            ->where('provider_account_id', $account->provider_account_id);

        foreach (['owner_type', 'owner_id'] as $owner) {
            $value = $account->getAttribute($owner);
            $value === null ? $query->whereNull($owner) : $query->where($owner, $value);
        }

        if (! $query->exists()) {
            throw new MailImportFailure(MailImportStage::Inventory, MailImportCode::StateMismatch);
        }
    }

    private function assertFastmailApiUrl(string $url): void
    {
        $parts = parse_url($url);
        $host = is_array($parts) && is_string($parts['host'] ?? null) ? strtolower($parts['host']) : '';

        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https'
            || ($host !== 'api.fastmail.com' && ! str_ends_with($host, '.api.fastmail.com'))
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || (isset($parts['port']) && $parts['port'] !== 443)) {
            throw new MailImportFailure(MailImportStage::Inventory, MailImportCode::StateMismatch);
        }
    }

    private function assertFastmailDownloadUrl(string $url): void
    {
        $parts = parse_url($url);
        $host = is_array($parts) && is_string($parts['host'] ?? null) ? strtolower($parts['host']) : '';

        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https'
            || ($host !== 'fastmailusercontent.com' && ! str_ends_with($host, '.fastmailusercontent.com'))
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || (isset($parts['port']) && $parts['port'] !== 443)) {
            throw new MailImportFailure(MailImportStage::Retrieve, MailImportCode::StateMismatch);
        }
    }

    private function pageSize(): int
    {
        $size = config('mail-mirror.jmap.page_size', 100);
        $size = is_int($size) && $size >= 1 && $size <= 500 ? $size : 100;

        return $this->listedPageLimit(min($size, $this->resourceLimit()));
    }

    private function listedPageLimit(int $maximum): int
    {
        $listedBudget = $this->budget?->remainingListedIds() ?? $maximum;

        return max(1, min($maximum, $listedBudget));
    }

    private function timeout(): int
    {
        $timeout = config('mail-mirror.jmap.timeout_seconds', 30);

        return is_int($timeout) && $timeout >= 1 && $timeout <= 120 ? $timeout : 30;
    }

    private function maxRawBytes(): int
    {
        $maximum = config('mail-mirror.jmap.max_raw_bytes', 52428800);

        return is_int($maximum) && $maximum >= 1048576 && $maximum <= 104857600 ? $maximum : 52428800;
    }

    private function resourceLimit(): int
    {
        $maximum = config('mail-mirror.inventory_page_max_messages', 500);

        return is_int($maximum) && $maximum >= 1 ? $maximum : 500;
    }
}
