<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Gmail;

use Closure;
use DateTimeImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Date;
use InvalidArgumentException;
use Jkudish\MailMirror\Contracts\BudgetedDeltaMailboxReader;
use Jkudish\MailMirror\Contracts\MailboxMutationDriver;
use Jkudish\MailMirror\Contracts\SubmissionDriver;
use Jkudish\MailMirror\Credentials\MailAccountConnection;
use Jkudish\MailMirror\Credentials\OAuthTokenSetCredential;
use Jkudish\MailMirror\Enums\MailboxAction;
use Jkudish\MailMirror\Enums\MailDriver;
use Jkudish\MailMirror\Enums\MailImportCode;
use Jkudish\MailMirror\Enums\MailImportStage;
use Jkudish\MailMirror\Enums\MailWriteCode;
use Jkudish\MailMirror\Enums\SubmissionOutcome;
use Jkudish\MailMirror\Exceptions\DeltaRepairRequired;
use Jkudish\MailMirror\Exceptions\GmailAuthorizationException;
use Jkudish\MailMirror\Exceptions\InventoryRestartRequired;
use Jkudish\MailMirror\Exceptions\MailImportFailure;
use Jkudish\MailMirror\Exceptions\MailWriteFailure;
use Jkudish\MailMirror\Exceptions\SyncBudgetExhausted;
use Jkudish\MailMirror\Models\MailAccount;
use Jkudish\MailMirror\Models\MailAccountCredential;
use Jkudish\MailMirror\Models\MailIdentity;
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
use Jkudish\MailMirror\Write\SubmissionResult;
use Throwable;
use ZBateson\MailMimeParser\Header\AddressHeader;
use ZBateson\MailMimeParser\MailMimeParser;

final class GmailMailboxReader implements BudgetedDeltaMailboxReader, MailboxMutationDriver, SubmissionDriver
{
    private const API = 'https://gmail.googleapis.com/gmail/v1';

    /** @var array<int, array<string, array{id: string, name: string, kind: string, metadata: array<string, mixed>}>> */
    private array $labels = [];

    private ?SyncWorkBudget $budget = null;

    public function __construct(
        private readonly Factory $http,
        private readonly MailAccountConnection $connections,
        private readonly GmailOAuth $oauth,
        private readonly MailMimeParser $mimeParser,
    ) {}

    public function driver(): MailDriver
    {
        return MailDriver::Gmail;
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
        $profile = null;
        $identities = [];
        $identitiesComplete = false;
        $state = $cursor === null ? null : $this->decodeCursor($cursor);

        if ($state === null) {
            $profile = $this->profile($account);
            $this->labels[$account->id] = $this->fetchLabels($account);
            $identities = $this->fetchIdentities($account);
            $identitiesComplete = true;
            $previousHistoryId = $account->provider_metadata['history_id'] ?? null;
            $currentHistoryId = $profile->providerMetadata['history_id'] ?? null;
            $state = is_string($previousHistoryId) && $previousHistoryId !== $currentHistoryId
                ? ['phase' => 'history', 'history_id' => $previousHistoryId, 'page_token' => null]
                : ['phase' => 'full', 'history_id' => is_string($currentHistoryId) ? $currentHistoryId : null, 'page_token' => null];
        } elseif (! isset($this->labels[$account->id])) {
            $this->labels[$account->id] = $this->fetchLabels($account);
        }

        if ($identitiesComplete && count($identities) >= $this->resourceLimit()) {
            return new InventoryPage(
                [],
                $this->encodeCursor($state),
                false,
                accountProfile: $profile,
                identities: $identities,
                identitiesComplete: true,
            );
        }

        if ($state['phase'] === 'history') {
            return $this->historyPage($account, $state, $profile, $identities, $identitiesComplete);
        }

        return $this->fullPage($account, $state, $profile, $identities, $identitiesComplete);
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
        $profile = $this->profile($account);
        $currentHistoryId = $profile->providerMetadata['history_id'] ?? null;

        if (! is_string($currentHistoryId) || $currentHistoryId === '') {
            throw new MailImportFailure(MailImportStage::Inventory, MailImportCode::MalformedPayload);
        }

        $state = $cursor === null
            ? ['phase' => 'history', 'history_id' => $account->provider_metadata['history_id'] ?? null, 'page_token' => null]
            : $this->decodeCursor($cursor);
        $recoveryCursor = $this->encodeCursor(['phase' => 'history', 'history_id' => $currentHistoryId, 'page_token' => null]);

        if ($state['phase'] !== 'history' || ! is_string($state['history_id']) || $state['history_id'] === '') {
            throw new DeltaRepairRequired($recoveryCursor);
        }

        $this->labels[$account->id] = $this->fetchLabels($account);
        $containers = array_values(array_map(
            fn (array $label): MailboxContainerState => new MailboxContainerState(
                $account->id,
                $label['id'],
                $label['name'],
                $label['kind'],
                $label['metadata'],
            ),
            $this->labels[$account->id],
        ));
        $resourceBudget = $this->resourceLimit() - count($containers);

        if ($resourceBudget < 1) {
            throw new DeltaRepairRequired($recoveryCursor);
        }

        $query = ['startHistoryId' => $state['history_id'], 'maxResults' => $this->pageSize($resourceBudget)];

        if ($state['page_token'] !== null) {
            $query['pageToken'] = $state['page_token'];
        }

        try {
            $payload = $this->request($account, 'GET', self::API.'/users/me/history', $query);
        } catch (MailImportFailure $failure) {
            if (in_array($failure->safeCode, [MailImportCode::HistoryExpired, MailImportCode::StateMismatch], true)) {
                throw new DeltaRepairRequired($recoveryCursor);
            }

            throw $failure;
        }

        /** @var array<string, ProviderDeletionEvidence|null> $finalStates */
        $finalStates = [];
        $history = $payload['history'] ?? [];

        if (! is_array($history)) {
            throw new MailImportFailure(MailImportStage::Inventory, MailImportCode::MalformedPayload);
        }

        foreach ($history as $record) {
            if (! is_array($record)) {
                throw new MailImportFailure(MailImportStage::Inventory, MailImportCode::MalformedPayload);
            }

            $recordId = $record['id'] ?? null;

            if (! is_string($recordId) || $recordId === '' || mb_strlen($recordId) > 255) {
                throw new MailImportFailure(MailImportStage::Inventory, MailImportCode::MalformedPayload);
            }

            foreach (['messagesAdded', 'labelsAdded', 'labelsRemoved'] as $key) {
                foreach ($this->historyMessages($record[$key] ?? []) as $native) {
                    $finalStates[$this->historyMessageId($native)] = null;
                }
            }

            foreach ($this->historyMessages($record['messagesDeleted'] ?? []) as $native) {
                $id = $this->historyMessageId($native);
                $finalStates[$id] = new ProviderDeletionEvidence(
                    $account->id,
                    $id,
                    'gmail_history_deleted',
                    'gmail-history-'.hash('sha256', $recordId.':'.$id),
                    ['history_id' => $recordId],
                );
            }
        }

        if (count($finalStates) > $resourceBudget) {
            throw new DeltaRepairRequired($recoveryCursor);
        }

        $messages = [];
        $unavailableMessages = [];

        foreach ($finalStates as $providerMessageId => $deletion) {
            if ($deletion === null) {
                try {
                    $messages[] = $this->changedMessageState($account, $providerMessageId);
                } catch (MailImportFailure $failure) {
                    if ($failure->safeCode !== MailImportCode::MessageUnavailable) {
                        throw $failure;
                    }

                    $unavailableMessages[] = new MessageReference(
                        $account->id,
                        MailDriver::Gmail,
                        $providerMessageId,
                    );
                }
            }
        }

        $nextPageToken = $payload['nextPageToken'] ?? null;
        $resultHistoryId = $payload['historyId'] ?? $currentHistoryId;

        if (($nextPageToken !== null && ! is_string($nextPageToken))
            || ! is_string($resultHistoryId) || $resultHistoryId === '') {
            throw new MailImportFailure(MailImportStage::Inventory, MailImportCode::MalformedPayload);
        }

        return new MailboxChangesPage(
            $messages,
            array_values(array_filter($finalStates)),
            $containers,
            true,
            $this->encodeCursor([
                'phase' => 'history',
                'history_id' => $nextPageToken === null ? $resultHistoryId : $state['history_id'],
                'page_token' => $nextPageToken,
            ]),
            $nextPageToken === null,
            new AccountProfile($profile->providerAccountId, $profile->emailAddress, array_replace(
                $profile->providerMetadata,
                ['history_id' => $nextPageToken === null ? $resultHistoryId : $state['history_id']],
            )),
            $unavailableMessages,
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

        if ($message->mailAccountId !== $account->id || $message->driver !== MailDriver::Gmail) {
            throw new MailImportFailure(MailImportStage::Retrieve, MailImportCode::StateMismatch);
        }

        $id = rawurlencode($message->providerMessageId);
        $metadata = $this->request($account, 'GET', self::API.'/users/me/messages/'.$id, ['format' => 'full']);
        $rawPayload = $this->request($account, 'GET', self::API.'/users/me/messages/'.$id, ['format' => 'raw']);
        $raw = $rawPayload['raw'] ?? null;

        if (! is_string($raw)) {
            throw new MailImportFailure(MailImportStage::Retrieve, MailImportCode::MalformedPayload);
        }

        $rawBytes = $this->base64UrlDecode($raw, MailImportStage::Retrieve, $this->maxRawBytes());

        if (strlen($rawBytes) > $this->maxRawBytes()) {
            throw new MailImportFailure(MailImportStage::Retrieve, MailImportCode::MalformedPayload);
        }

        $parseStream = $this->stream($rawBytes);

        try {
            $parsed = $this->mimeParser->parse($parseStream, false);
            $participants = $this->participants($parsed);
        } catch (Throwable) {
            throw new MailImportFailure(MailImportStage::Parse, MailImportCode::MalformedPayload);
        } finally {
            fclose($parseStream);
        }

        $headers = $this->headers($metadata);
        $labelIds = $metadata['labelIds'] ?? null;
        $threadId = $metadata['threadId'] ?? null;
        $historyId = $metadata['historyId'] ?? null;
        $internalDate = $metadata['internalDate'] ?? null;
        $sizeEstimate = $metadata['sizeEstimate'] ?? null;

        if (! is_array($labelIds) || array_filter($labelIds, fn (mixed $id): bool => ! is_string($id)) !== []
            || ! is_string($threadId) || ! is_string($historyId) || ! is_string($internalDate)
            || ! ctype_digit($internalDate) || ! is_int($sizeEstimate)) {
            throw new MailImportFailure(MailImportStage::Retrieve, MailImportCode::MalformedPayload);
        }

        /** @var list<string> $labelIds */
        $labelIds = array_values($labelIds);

        $subject = $this->headerValue($headers, 'subject');
        $internetMessageId = $this->headerValue($headers, 'message-id');
        $sentAt = $this->dateHeader($this->headerValue($headers, 'date'));
        $receivedAt = (new DateTimeImmutable)->setTimestamp((int) floor(((int) $internalDate) / 1000));
        $attachments = $this->attachments($metadata['payload'] ?? null);
        $containers = [];

        foreach ($labelIds as $labelId) {
            $label = $this->labels[$account->id][$labelId] ?? [
                'id' => $labelId,
                'name' => $labelId,
                'kind' => str_starts_with($labelId, 'CATEGORY_') || in_array($labelId, ['INBOX', 'SPAM', 'TRASH', 'SENT', 'DRAFT', 'IMPORTANT', 'STARRED', 'UNREAD'], true)
                    ? 'system' : 'label',
                'metadata' => [],
            ];
            $containers[] = [
                'provider_id' => $label['id'],
                'name' => $label['name'],
                'kind' => $label['kind'],
                'membership_id' => $message->providerMessageId.':'.$labelId,
                'provider_metadata' => $label['metadata'],
                'membership_metadata' => ['label_id' => $labelId],
            ];
        }

        return new RetrievedMessage(
            reference: $message,
            subject: $subject,
            internetMessageId: $internetMessageId,
            headers: $headers,
            participants: $participants,
            attachments: $attachments,
            containers: $containers,
            providerMetadata: [
                'history_id' => $historyId,
                'internal_date' => $internalDate,
                'size_estimate' => $sizeEstimate,
                'label_ids' => $labelIds,
                'mailbox_state' => $this->mailboxState($labelIds),
                'draft' => in_array('DRAFT', $labelIds, true),
            ],
            rawSource: new RawMessageSource($this->stream($rawBytes), $message->providerMessageId, providerMetadata: [
                'history_id' => $historyId,
            ]),
            providerThreadMetadata: ['history_id' => $historyId],
            sentAt: $sentAt,
            receivedAt: $receivedAt,
        );
    }

    /**
     * @param  array{phase: string, history_id: string|null, page_token: string|null}  $state
     * @param  list<MailboxIdentity>  $identities
     */
    private function historyPage(MailAccount $account, array $state, ?AccountProfile $profile, array $identities, bool $identitiesComplete): InventoryPage
    {
        $resourceBudget = $this->resourceLimit() - count($identities);
        $query = ['startHistoryId' => $state['history_id'], 'maxResults' => $this->pageSize($resourceBudget)];

        if ($state['page_token'] !== null) {
            $query['pageToken'] = $state['page_token'];
        }

        try {
            $payload = $this->request($account, 'GET', self::API.'/users/me/history', $query);
        } catch (MailImportFailure $failure) {
            if ($state['page_token'] !== null && $failure->safeCode === MailImportCode::StateMismatch) {
                throw new InventoryRestartRequired($this->fullRestartCursor());
            }

            if ($failure->safeCode !== MailImportCode::HistoryExpired) {
                throw $failure;
            }

            return new InventoryPage([], $this->encodeCursor([
                'phase' => 'full', 'history_id' => null, 'page_token' => null,
            ]), false, accountProfile: $profile, identities: $identities, identitiesComplete: $identitiesComplete);
        }

        /** @var array<string, ProviderDeletionEvidence|null> $finalStates */
        $finalStates = [];
        $history = $payload['history'] ?? [];

        if (! is_array($history)) {
            throw new MailImportFailure(MailImportStage::Inventory, MailImportCode::MalformedPayload);
        }

        $next = $payload['nextPageToken'] ?? null;

        if ($next !== null && ! is_string($next)) {
            throw new MailImportFailure(MailImportStage::Inventory, MailImportCode::MalformedPayload);
        }

        foreach ($history as $record) {
            if (! is_array($record)) {
                throw new MailImportFailure(MailImportStage::Inventory, MailImportCode::MalformedPayload);
            }

            $recordId = $record['id'] ?? null;

            if (! is_string($recordId) || trim($recordId) === '' || mb_strlen($recordId) > 255) {
                throw new MailImportFailure(MailImportStage::Inventory, MailImportCode::MalformedPayload);
            }

            foreach (['messagesAdded', 'labelsAdded', 'labelsRemoved'] as $key) {
                foreach ($this->historyMessages($record[$key] ?? []) as $native) {
                    $finalStates[$this->historyMessageId($native)] = null;
                }
            }

            foreach ($this->historyMessages($record['messagesDeleted'] ?? []) as $native) {
                $id = $this->historyMessageId($native);

                $finalStates[$id] = new ProviderDeletionEvidence(
                    $account->id,
                    $id,
                    'gmail_history_deleted',
                    'gmail-history-'.hash('sha256', $recordId.':'.$id),
                    ['history_id' => $recordId],
                );
            }

            if (count($finalStates) > $resourceBudget) {
                throw new InventoryRestartRequired($this->fullRestartCursor());
            }
        }

        $deletions = array_values(array_filter(
            $finalStates,
            fn (?ProviderDeletionEvidence $state): bool => $state !== null,
        ));
        $resolutions = array_map(
            fn (string $id): ProviderDeletionResolution => new ProviderDeletionResolution($account->id, $id),
            array_keys(array_filter(
                $finalStates,
                fn (?ProviderDeletionEvidence $state): bool => $state === null,
            )),
        );

        $nextState = [
            'phase' => $next === null ? 'full' : 'history',
            'history_id' => $state['history_id'],
            'page_token' => is_string($next) ? $next : null,
        ];

        return new InventoryPage(
            [],
            $this->encodeCursor($nextState),
            false,
            $deletions,
            $profile,
            $identities,
            $identitiesComplete,
            $resolutions,
        );
    }

    /**
     * @param  array{phase: string, history_id: string|null, page_token: string|null}  $state
     * @param  list<MailboxIdentity>  $identities
     */
    private function fullPage(MailAccount $account, array $state, ?AccountProfile $profile, array $identities, bool $identitiesComplete): InventoryPage
    {
        $resourceBudget = $this->resourceLimit() - count($identities);
        $query = ['includeSpamTrash' => 'true', 'maxResults' => $this->pageSize($resourceBudget)];

        if ($state['page_token'] !== null) {
            $query['pageToken'] = $state['page_token'];
        }

        try {
            $payload = $this->request($account, 'GET', self::API.'/users/me/messages', $query);
        } catch (MailImportFailure $failure) {
            if ($state['page_token'] !== null && $failure->safeCode === MailImportCode::StateMismatch) {
                throw new InventoryRestartRequired($this->fullRestartCursor());
            }

            throw $failure;
        }
        $nativeMessages = $payload['messages'] ?? [];
        $next = $payload['nextPageToken'] ?? null;

        if (! is_array($nativeMessages) || count($nativeMessages) > $resourceBudget
            || ($next !== null && ! is_string($next))) {
            throw new MailImportFailure(MailImportStage::Inventory, MailImportCode::MalformedPayload);
        }

        $messages = array_values(array_map(fn (mixed $native): MessageReference => $this->reference($account, $native, [
            'inventory' => 'full', 'history_id' => $state['history_id'],
        ]), $nativeMessages));

        return new InventoryPage(
            $messages,
            $next === null ? null : $this->encodeCursor([
                'phase' => 'full', 'history_id' => $state['history_id'], 'page_token' => $next,
            ]),
            $next === null,
            accountProfile: $profile,
            identities: $identities,
            identitiesComplete: $identitiesComplete,
        );
    }

    private function profile(MailAccount $account): AccountProfile
    {
        [$credential, $stored] = $this->credential($account);

        try {
            $profile = $this->oauth->profile($credential, $this->budget);
        } catch (GmailAuthorizationException $failure) {
            if (! $failure->accessRejected) {
                throw new MailImportFailure(
                    MailImportStage::Inventory,
                    $failure->permissionDenied ? MailImportCode::PermissionDenied : MailImportCode::ProviderUnavailable,
                    ! $failure->permissionDenied,
                );
            }

            $credential = $this->refresh($account, $stored, $credential);
            $stored->refresh();

            try {
                $profile = $this->oauth->profile($credential);
            } catch (GmailAuthorizationException $retryFailure) {
                if ($retryFailure->accessRejected) {
                    $this->revoke($account, $stored);
                }

                throw new MailImportFailure(
                    MailImportStage::Inventory,
                    $retryFailure->accessRejected
                        ? MailImportCode::AuthenticationFailed
                        : ($retryFailure->permissionDenied ? MailImportCode::PermissionDenied : MailImportCode::ProviderUnavailable),
                    ! $retryFailure->accessRejected && ! $retryFailure->permissionDenied,
                );
            }
        }

        if (strcasecmp($profile->providerAccountId, $account->provider_account_id) !== 0) {
            throw new MailImportFailure(MailImportStage::Inventory, MailImportCode::StateMismatch);
        }

        return new AccountProfile($account->provider_account_id, $profile->emailAddress, $profile->providerMetadata);
    }

    /** @return array<string, array{id: string, name: string, kind: string, metadata: array<string, mixed>}> */
    private function fetchLabels(MailAccount $account): array
    {
        $payload = $this->request($account, 'GET', self::API.'/users/me/labels');
        $nativeLabels = $payload['labels'] ?? null;

        if (! is_array($nativeLabels)) {
            throw new MailImportFailure(MailImportStage::Inventory, MailImportCode::MalformedPayload);
        }

        if (count($nativeLabels) > 10000) {
            throw new MailImportFailure(MailImportStage::Inventory, MailImportCode::MalformedPayload);
        }

        $labels = [];

        foreach ($nativeLabels as $label) {
            $id = is_array($label) ? ($label['id'] ?? null) : null;
            $name = is_array($label) ? ($label['name'] ?? null) : null;
            $type = is_array($label) ? ($label['type'] ?? null) : null;

            if (! is_string($id) || trim($id) === '' || mb_strlen($id) > 255
                || ! is_string($name) || mb_strlen($name) > 255
                || ! is_string($type) || mb_strlen($type) > 255) {
                throw new MailImportFailure(MailImportStage::Inventory, MailImportCode::MalformedPayload);
            }

            $labels[$id] = [
                'id' => $id,
                'name' => $name,
                'kind' => strtolower($type),
                'metadata' => array_filter([
                    'message_list_visibility' => $label['messageListVisibility'] ?? null,
                    'label_list_visibility' => $label['labelListVisibility'] ?? null,
                ], fn (mixed $value): bool => is_string($value)),
            ];
        }

        return $labels;
    }

    /** @return list<MailboxIdentity> */
    private function fetchIdentities(MailAccount $account): array
    {
        $payload = $this->request($account, 'GET', self::API.'/users/me/settings/sendAs');
        $nativeIdentities = $payload['sendAs'] ?? null;

        if (! is_array($nativeIdentities)) {
            throw new MailImportFailure(MailImportStage::Inventory, MailImportCode::MalformedPayload);
        }

        if (count($nativeIdentities) > $this->resourceLimit()) {
            throw new MailImportFailure(MailImportStage::Inventory, MailImportCode::MalformedPayload);
        }

        return array_values(array_map(function (mixed $identity): MailboxIdentity {
            $identity = is_array($identity) ? $identity : [];
            $email = $identity['sendAsEmail'] ?? null;
            $name = $identity['displayName'] ?? null;
            $replyTo = $identity['replyToAddress'] ?? null;
            $signature = $identity['signature'] ?? null;
            $verificationStatus = $identity['verificationStatus'] ?? null;

            if (! is_string($email) || trim($email) === '' || mb_strlen($email) > 255
                || ($name !== null && (! is_string($name) || mb_strlen($name) > 255))
                || ($replyTo !== null && (! is_string($replyTo) || mb_strlen($replyTo) > 255))
                || ($signature !== null && (! is_string($signature) || strlen($signature) > 100000))
                || ($verificationStatus !== null && (! is_string($verificationStatus) || mb_strlen($verificationStatus) > 255))) {
                throw new MailImportFailure(MailImportStage::Inventory, MailImportCode::MalformedPayload);
            }

            try {
                return new MailboxIdentity($email, $email, $name, [
                    'reply_to_address' => $replyTo,
                    'signature' => $signature,
                    'is_primary' => ($identity['isPrimary'] ?? false) === true,
                    'is_default' => ($identity['isDefault'] ?? false) === true,
                    'verification_status' => $verificationStatus,
                ]);
            } catch (InvalidArgumentException) {
                throw new MailImportFailure(MailImportStage::Inventory, MailImportCode::MalformedPayload);
            }
        }, $nativeIdentities));
    }

    public function messageState(MailAccount $account, string $providerMessageId, MailboxChange $change): MessageState
    {
        $this->assertAccount($account);

        // Gmail system label IDs are upper case; user label IDs (Label_N) are not.
        if ($change->containerId !== null && preg_match('/\A[A-Z0-9_]+\z/', $change->containerId) === 1) {
            throw new MailWriteFailure(MailWriteCode::UnsupportedContainer);
        }

        $native = $this->request(
            $account,
            'GET',
            self::API.'/users/me/messages/'.rawurlencode($providerMessageId),
            ['format' => 'minimal'],
        );
        $historyId = $native['historyId'] ?? null;
        $labelIds = $native['labelIds'] ?? [];

        if (($native['id'] ?? null) !== $providerMessageId || ! is_string($historyId) || $historyId === ''
            || ! is_array($labelIds) || ! array_is_list($labelIds) || count($labelIds) > 10000
            || array_filter($labelIds, fn (mixed $labelId): bool => ! is_string($labelId)) !== []) {
            throw new MailImportFailure(MailImportStage::Retrieve, MailImportCode::MalformedPayload);
        }

        /** @var list<string> $labelIds */
        $has = fn (string $label): bool => in_array($label, $labelIds, true);

        return new MessageState(
            $account->id,
            MailDriver::Gmail,
            $providerMessageId,
            match ($change->action) {
                MailboxAction::MarkRead => ! $has('UNREAD'),
                MailboxAction::MarkUnread => $has('UNREAD'),
                MailboxAction::Star => $has('STARRED'),
                MailboxAction::Unstar => ! $has('STARRED'),
                MailboxAction::Archive => ! $has('INBOX'),
                MailboxAction::Unarchive => $has('INBOX'),
                MailboxAction::AddContainer => $has((string) $change->containerId),
                MailboxAction::RemoveContainer => ! $has((string) $change->containerId),
                MailboxAction::Trash => $has('TRASH'),
                // untrash restores the prior labels, so any state outside Trash is restored.
                MailboxAction::Untrash => ! $has('TRASH'),
                MailboxAction::Spam => $has('SPAM') && ! $has('INBOX'),
                MailboxAction::NotSpam => ! $has('SPAM') && $has('INBOX'),
            },
            ['label_ids' => $labelIds, 'history_id' => $historyId],
        );
    }

    public function prepareWrite(MailAccount $account, int $validForSeconds): void
    {
        $this->assertAccount($account);
        $this->credential($account, max(30, $validForSeconds));
    }

    /**
     * Sends one users.messages.modify, trash, or untrash request for the
     * observed message only; never a threads endpoint.
     */
    public function applyChange(MailAccount $account, MessageState $observed, MailboxChange $change): void
    {
        $this->assertAccount($account);
        $labelIds = $observed->providerEvidence['label_ids'] ?? null;

        if ($observed->mailAccountId !== $account->id || $observed->driver !== MailDriver::Gmail
            || $observed->desiredStateHolds || ! is_array($labelIds)) {
            throw new MailWriteFailure(MailWriteCode::UnsupportedState);
        }

        $has = fn (string $label): bool => in_array($label, $labelIds, true);
        [$endpoint, $modify] = match ($change->action) {
            MailboxAction::MarkRead => ['modify', ['removeLabelIds' => ['UNREAD']]],
            MailboxAction::MarkUnread => ['modify', ['addLabelIds' => ['UNREAD']]],
            MailboxAction::Star => ['modify', ['addLabelIds' => ['STARRED']]],
            MailboxAction::Unstar => ['modify', ['removeLabelIds' => ['STARRED']]],
            MailboxAction::Archive => ['modify', ['removeLabelIds' => ['INBOX']]],
            MailboxAction::Unarchive => ['modify', ['addLabelIds' => ['INBOX']]],
            MailboxAction::AddContainer => ['modify', ['addLabelIds' => [(string) $change->containerId]]],
            MailboxAction::RemoveContainer => ['modify', ['removeLabelIds' => [(string) $change->containerId]]],
            MailboxAction::Trash => ['trash', null],
            MailboxAction::Untrash => ['untrash', null],
            MailboxAction::Spam => ['modify', ['addLabelIds' => ['SPAM'], 'removeLabelIds' => ['INBOX']]],
            MailboxAction::NotSpam => ['modify', ['addLabelIds' => ['INBOX'], 'removeLabelIds' => ['SPAM']]],
        };

        // Trash and Spam leave only through untrash and not-spam, never by adding INBOX.
        if (($change->action === MailboxAction::Unarchive && ($has('TRASH') || $has('SPAM')))
            || ($change->action === MailboxAction::NotSpam && ! $has('SPAM'))
            || ($change->action === MailboxAction::Spam && $has('TRASH'))) {
            throw new MailWriteFailure(MailWriteCode::UnsupportedState);
        }

        // prepareWrite() already refreshed the credential. Load it without refreshing,
        // so nothing before the write request can wait on the credential row lock.
        $this->credential($account, null);

        try {
            $native = $this->request(
                $account,
                'POST',
                self::API.'/users/me/messages/'.rawurlencode($observed->providerMessageId).'/'.$endpoint,
                resendAfterReauthorization: false,
                refreshCredential: false,
                json: $modify,
            );
        } catch (MailImportFailure $failure) {
            throw MailWriteFailure::fromProvider($failure, true);
        }

        if (($native['id'] ?? null) !== $observed->providerMessageId) {
            throw new MailWriteFailure(MailWriteCode::ProviderFailed, true, MailImportCode::MalformedPayload);
        }
    }

    public function draft(MailAccount $account, string $draftId): ?DraftRevision
    {
        $this->assertAccount($account);

        try {
            $native = $this->request($account, 'GET', self::API.'/users/me/drafts/'.rawurlencode($draftId), ['format' => 'raw']);
        } catch (MailImportFailure $failure) {
            if ($failure->httpStatus === 404) {
                return null;
            }

            throw $failure;
        }

        $message = $native['message'] ?? null;
        $raw = is_array($message) ? ($message['raw'] ?? null) : null;
        $messageId = is_array($message) ? ($message['id'] ?? null) : null;
        $threadId = is_array($message) ? ($message['threadId'] ?? null) : null;
        $labelIds = is_array($message) ? ($message['labelIds'] ?? []) : null;

        if (($native['id'] ?? null) !== $draftId || ! is_string($raw) || ! is_string($messageId) || $messageId === ''
            || ! is_string($threadId) || $threadId === '' || ! is_array($labelIds) || ! array_is_list($labelIds)
            || array_filter($labelIds, fn (mixed $labelId): bool => ! is_string($labelId)) !== []) {
            throw new MailImportFailure(MailImportStage::Retrieve, MailImportCode::MalformedPayload);
        }

        $bytes = $this->base64UrlDecode($raw, MailImportStage::Retrieve, $this->maxRawBytes());

        try {
            return new DraftRevision(
                $account->id,
                MailDriver::Gmail,
                $draftId,
                $messageId,
                DraftContent::messageIdOf($bytes),
                $threadId,
                hash('sha256', $bytes),
                ['label_ids' => $labelIds],
                DraftContent::fromAddressOf($bytes),
            );
        } catch (InvalidArgumentException) {
            throw new MailImportFailure(MailImportStage::Retrieve, MailImportCode::MalformedPayload);
        }
    }

    /** Pages through drafts.list, which reports each draft's current message ID. */
    public function draftForMessage(MailAccount $account, string $providerMessageId): ?DraftRevision
    {
        $this->assertAccount($account);
        $pageToken = null;

        for ($page = 0; $page < 20; $page++) {
            $native = $this->request($account, 'GET', self::API.'/users/me/drafts', array_filter([
                'maxResults' => 500,
                'pageToken' => $pageToken,
            ], fn (mixed $value): bool => $value !== null));

            foreach ($this->draftIds($native) as $draftId => $messageId) {
                if ($messageId === $providerMessageId) {
                    return $this->draft($account, $draftId);
                }
            }

            $pageToken = $native['nextPageToken'] ?? null;

            if (! is_string($pageToken) || $pageToken === '') {
                return null;
            }
        }

        throw new MailImportFailure(MailImportStage::Retrieve, MailImportCode::StateMismatch);
    }

    public function draftsWithMessageId(MailAccount $account, string $messageId): array
    {
        $this->assertAccount($account);
        $native = $this->request($account, 'GET', self::API.'/users/me/drafts', [
            'q' => 'rfc822msgid:'.$messageId,
            'maxResults' => 10,
        ]);
        $drafts = [];

        foreach (array_keys($this->draftIds($native)) as $draftId) {
            $draft = $this->draft($account, $draftId);

            if ($draft !== null && $this->sameContentIdentity($draft->messageId, null, $messageId, null)) {
                $drafts[] = $draft;
            }
        }

        return $drafts;
    }

    public function holdsContent(DraftRevision $draft, DraftContent $content): bool
    {
        return $this->sameContentIdentity($draft->messageId, $draft->rawSha256, $content->messageId, $content->sha256);
    }

    /**
     * Gmail content identity, in one place. ASSUMPTION, unverified against live
     * Gmail (#3131): drafts.get format=raw returns exactly the bytes written,
     * including the client Message-ID. If Gmail rewrites either, every Gmail
     * create and replace reports unconfirmed or revision_conflict, and draft
     * lookups by Message-ID find nothing; change only this method. Submission
     * reconciliation reports not_submitted only from the draft itself, never
     * from a missing Message-ID match, so a rewrite there yields unknown.
     */
    private function sameContentIdentity(?string $observedMessageId, ?string $observedSha256, string $messageId, ?string $sha256): bool
    {
        return $observedMessageId === $messageId && ($sha256 === null || $observedSha256 === $sha256);
    }

    public function stageDraft(MailAccount $account, DraftContent $content): ?string
    {
        return null;
    }

    public function createDraft(MailAccount $account, DraftContent $content, ?string $stagedBlobId, ?string $providerThreadId): string
    {
        $this->assertAccount($account);
        $this->credential($account, null);
        $native = $this->sendDraftWrite($account, 'POST', self::API.'/users/me/drafts', [
            'message' => array_filter([
                'raw' => $this->base64UrlEncode($content->bytes),
                'threadId' => $providerThreadId,
            ], fn (mixed $value): bool => $value !== null),
        ]);
        $draftId = $native['id'] ?? null;

        return is_string($draftId) && $draftId !== ''
            ? $draftId
            : throw new MailWriteFailure(MailWriteCode::ProviderFailed, true, MailImportCode::MalformedPayload);
    }

    /**
     * Gmail has no update precondition, so this update is unconditional. The
     * service compared the revision under its lock, and its confirming read
     * reports a racing external edit as revision_conflict.
     */
    public function replaceDraft(MailAccount $account, DraftRevision $observed, DraftContent $content, ?string $stagedBlobId, ?DraftRevision $imported): string
    {
        $this->assertAccount($account);

        // drafts.update replaces in place, so no interrupted replace leaves a second draft.
        if ($imported !== null) {
            throw new MailWriteFailure(MailWriteCode::MessageIdConflict);
        }

        $this->assertDraft($account, $observed);
        $this->credential($account, null);
        $url = self::API.'/users/me/drafts/'.rawurlencode($observed->draftId);
        $native = $this->sendDraftWrite($account, 'PUT', $url, [
            'id' => $observed->draftId,
            'message' => ['raw' => $this->base64UrlEncode($content->bytes), 'threadId' => $observed->threadId],
        ]);

        return ($native['id'] ?? null) === $observed->draftId
            ? $observed->draftId
            : throw new MailWriteFailure(MailWriteCode::ProviderFailed, true, MailImportCode::MalformedPayload);
    }

    public function deleteDraft(MailAccount $account, DraftRevision $observed): void
    {
        $this->assertAccount($account);
        $this->assertDraft($account, $observed);
        $this->credential($account, null);
        $this->sendDraftWrite($account, 'DELETE', self::API.'/users/me/drafts/'.rawurlencode($observed->draftId), null);
    }

    /** drafts.send sends the draft's stored bytes and deletes the draft on success. */
    public function submitDraft(MailAccount $account, DraftRevision $observed, MailIdentity $identity): string
    {
        $this->assertAccount($account);
        $this->assertDraft($account, $observed);

        if ($identity->mail_account_id !== $account->id) {
            throw new MailWriteFailure(MailWriteCode::IdentityMismatch);
        }

        $this->credential($account, null);
        $native = $this->sendDraftWrite($account, 'POST', self::API.'/users/me/drafts/send', ['id' => $observed->draftId]);
        $messageId = $native['id'] ?? null;

        return is_string($messageId) && $messageId !== '' && mb_strlen($messageId) <= 255
            ? $messageId
            : throw new MailWriteFailure(MailWriteCode::ProviderFailed, true, MailImportCode::MalformedPayload);
    }

    public function sentMessage(MailAccount $account, string $providerMessageId): ?SubmissionResult
    {
        $this->assertAccount($account);

        try {
            $native = $this->request($account, 'GET', self::API.'/users/me/messages/'.rawurlencode($providerMessageId), ['format' => 'minimal']);
        } catch (MailImportFailure $failure) {
            if ($failure->httpStatus === 404) {
                return null;
            }

            throw $failure;
        }

        $threadId = $native['threadId'] ?? null;
        $labelIds = $native['labelIds'] ?? null;

        if (($native['id'] ?? null) !== $providerMessageId || ! is_string($threadId) || $threadId === ''
            || ! is_array($labelIds) || array_filter($labelIds, fn (mixed $labelId): bool => ! is_string($labelId)) !== []) {
            throw new MailImportFailure(MailImportStage::Retrieve, MailImportCode::MalformedPayload);
        }

        if (! in_array('SENT', $labelIds, true) || in_array('DRAFT', $labelIds, true)) {
            return null;
        }

        return new SubmissionResult($account->id, MailDriver::Gmail, SubmissionOutcome::Submitted, $providerMessageId, $threadId, 'provider_id', [
            'label_ids' => array_values($labelIds),
        ]);
    }

    /**
     * Gmail keeps no link from a sent message to the draft it came from, so the
     * only evidence is a SENT message whose Message-ID header matches.
     */
    public function findSubmission(MailAccount $account, string $draftId, string $messageId): ?SubmissionResult
    {
        $this->assertAccount($account);
        $native = $this->request($account, 'GET', self::API.'/users/me/messages', [
            'q' => 'rfc822msgid:'.$messageId,
            'labelIds' => 'SENT',
            'maxResults' => 10,
        ]);
        $messages = $native['messages'] ?? [];

        if (! is_array($messages) || ! array_is_list($messages)) {
            throw new MailImportFailure(MailImportStage::Retrieve, MailImportCode::MalformedPayload);
        }

        foreach ($messages as $message) {
            $id = is_array($message) ? ($message['id'] ?? null) : null;

            if (! is_string($id) || $id === '') {
                throw new MailImportFailure(MailImportStage::Retrieve, MailImportCode::MalformedPayload);
            }

            $metadata = $this->request($account, 'GET', self::API.'/users/me/messages/'.rawurlencode($id), [
                'format' => 'metadata',
                'metadataHeaders' => 'Message-ID',
            ]);
            $threadId = $metadata['threadId'] ?? null;
            $labelIds = $metadata['labelIds'] ?? [];
            $header = $this->headerValue($this->headers($metadata), 'Message-ID');

            if (is_string($threadId) && $threadId !== '' && is_array($labelIds) && in_array('SENT', $labelIds, true)
                && $header !== null && $this->sameContentIdentity(DraftContent::parseMessageId($header), null, $messageId, null)) {
                return new SubmissionResult($account->id, MailDriver::Gmail, SubmissionOutcome::Submitted, $id, $threadId, 'message_id', [
                    'label_ids' => array_values(array_filter($labelIds, 'is_string')),
                ]);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $json
     * @return array<string, mixed>
     */
    private function sendDraftWrite(MailAccount $account, string $method, string $url, ?array $json): array
    {
        try {
            return $this->request(
                $account,
                $method,
                $url,
                resendAfterReauthorization: false,
                refreshCredential: false,
                json: $json,
            );
        } catch (MailImportFailure $failure) {
            throw MailWriteFailure::fromProvider($failure, true);
        }
    }

    private function assertDraft(MailAccount $account, DraftRevision $observed): void
    {
        if ($observed->mailAccountId !== $account->id || $observed->driver !== MailDriver::Gmail) {
            throw new MailWriteFailure(MailWriteCode::UnsupportedState);
        }
    }

    /**
     * @param  array<string, mixed>  $native  a drafts.list page
     * @return array<string, string> message IDs keyed by draft ID
     */
    private function draftIds(array $native): array
    {
        $drafts = $native['drafts'] ?? [];

        if (! is_array($drafts) || ! array_is_list($drafts) || count($drafts) > 500) {
            throw new MailImportFailure(MailImportStage::Retrieve, MailImportCode::MalformedPayload);
        }

        $ids = [];

        foreach ($drafts as $draft) {
            $draftId = is_array($draft) ? ($draft['id'] ?? null) : null;
            $message = is_array($draft) ? ($draft['message'] ?? null) : null;
            $messageId = is_array($message) ? ($message['id'] ?? null) : null;

            if (! is_string($draftId) || $draftId === '' || mb_strlen($draftId) > 255 || ! is_string($messageId)) {
                throw new MailImportFailure(MailImportStage::Retrieve, MailImportCode::MalformedPayload);
            }

            $ids[$draftId] = $messageId;
        }

        return $ids;
    }

    private function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * Writes pass $resendAfterReauthorization false: a rejected token is
     * refreshed for the next call, but the write itself is never re-sent.
     *
     * @param  array<string, int|string|null>  $query
     * @param  array<string, mixed>|null  $json
     * @return array<string, mixed>
     */
    private function request(
        MailAccount $account,
        string $method,
        string $url,
        array $query = [],
        bool $resendAfterReauthorization = true,
        bool $refreshCredential = true,
        ?array $json = null,
    ): array {
        if (config('mail-mirror.gmail.enabled') !== true) {
            throw new MailImportFailure(MailImportStage::Inventory, MailImportCode::ProviderUnavailable);
        }

        [$credential, $stored] = $this->credential($account, $refreshCredential ? 30 : null);
        $response = $this->send($credential, $method, $url, $query, $json);

        if ($response->status() === 401 && ! $resendAfterReauthorization) {
            try {
                $this->refresh($account, $stored, $credential);
            } catch (MailImportFailure) {
                // The provider rejected the request either way; the next call reports the credential state.
            }

            throw new MailImportFailure($this->stageFor($url), MailImportCode::AuthenticationFailed, true, httpStatus: 401);
        }

        if ($response->status() === 401) {
            $credential = $this->refresh($account, $stored, $credential);
            $response = $this->send($credential, $method, $url, $query, $json);

            if ($response->status() === 401) {
                $stored->refresh();
                $this->revoke($account, $stored);
                throw new MailImportFailure($this->stageFor($url), MailImportCode::AuthenticationFailed);
            }
        }

        if (! $response->successful()) {
            throw $this->failureFor($response, $url);
        }

        // drafts.delete answers 204 with no body.
        if ($method === 'DELETE' && $response->body() === '') {
            return [];
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new MailImportFailure($this->stageFor($url), MailImportCode::MalformedPayload);
        }

        /** @var array<string, mixed> $payload */
        return $payload;
    }

    /**
     * @param  array<string, int|string|null>  $query
     * @param  array<string, mixed>|null  $json
     */
    private function send(OAuthTokenSetCredential $credential, string $method, string $url, array $query, ?array $json = null): Response
    {
        $this->budget?->claimHttpRequest();

        try {
            $response = $this->prepareRequest(
                $this->http->withToken($credential->accessToken())->acceptJson(),
            )->send($method, $url, ['query' => $query] + ($json === null ? [] : ['json' => $json]));
            $this->budget?->recordDownloadedBytes(strlen($response->body()));

            return $response;
        } catch (Throwable $failure) {
            if (($budgetFailure = $this->budgetFailure($failure)) !== null) {
                throw $budgetFailure;
            }

            throw new MailImportFailure($this->stageFor($url), MailImportCode::ProviderUnavailable, true);
        }
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

    /**
     * Refresh when the token expires within $refreshWithinSeconds. With null the
     * credential is never refreshed, and an expired one fails instead.
     *
     * @return array{OAuthTokenSetCredential, MailAccountCredential}
     */
    private function credential(MailAccount $account, ?int $refreshWithinSeconds = 30): array
    {
        $stored = $account->credential()->first();

        if (! $stored instanceof MailAccountCredential) {
            throw new MailImportFailure(MailImportStage::Inventory, MailImportCode::AuthenticationFailed);
        }

        try {
            $credential = $this->connections->credentials($account, $stored);
        } catch (Throwable) {
            throw new MailImportFailure(MailImportStage::Inventory, MailImportCode::AuthenticationFailed);
        }

        if (! $credential instanceof OAuthTokenSetCredential || $credential->scopes() !== [GmailOAuth::SCOPE]) {
            $this->revoke($account, $stored);
            throw new MailImportFailure(MailImportStage::Inventory, MailImportCode::PermissionDenied);
        }

        $expiresAt = $credential->expiresAt();

        if ($expiresAt !== null && $refreshWithinSeconds === null && $expiresAt <= Date::now()->toDateTimeImmutable()) {
            throw new MailImportFailure(MailImportStage::Inventory, MailImportCode::AuthenticationFailed, true);
        }

        if ($expiresAt !== null && $refreshWithinSeconds !== null
            && $expiresAt <= Date::now()->addSeconds($refreshWithinSeconds)->toDateTimeImmutable()) {
            $credential = $this->refresh($account, $stored, $credential);
            $stored->refresh();
        }

        return [$credential, $stored];
    }

    private function refresh(MailAccount $account, MailAccountCredential $stored, OAuthTokenSetCredential $credential): OAuthTokenSetCredential
    {
        $attemptedVersion = $stored->version;

        try {
            $replacement = $this->oauth->refresh($account, $stored, $credential, $this->budget);

            return $replacement;
        } catch (GmailAuthorizationException $failure) {
            if ($failure->grantInvalid) {
                $stored->refresh();

                if ($stored->version !== $attemptedVersion) {
                    try {
                        $winner = $this->connections->credentials($account, $stored);
                    } catch (Throwable) {
                        throw new MailImportFailure(MailImportStage::Inventory, MailImportCode::ProviderUnavailable, true);
                    }

                    if ($winner instanceof OAuthTokenSetCredential
                        && $winner->scopes() === [GmailOAuth::SCOPE]
                        && ($winner->expiresAt() === null || $winner->expiresAt() > new DateTimeImmutable)) {
                        return $winner;
                    }

                    throw new MailImportFailure(MailImportStage::Inventory, MailImportCode::ProviderUnavailable, true);
                }

                $this->revoke($account, $stored);
            }

            throw new MailImportFailure(
                MailImportStage::Inventory,
                $failure->grantInvalid ? MailImportCode::AuthenticationFailed : MailImportCode::ProviderUnavailable,
                ! $failure->grantInvalid,
            );
        }
    }

    private function revoke(MailAccount $account, MailAccountCredential $stored): void
    {
        try {
            $this->connections->revoke($account, $stored, $stored->version);
        } catch (Throwable) {
            throw new MailImportFailure(MailImportStage::Inventory, MailImportCode::AuthenticationFailed);
        }
    }

    private function failureFor(Response $response, string $url): MailImportFailure
    {
        $stage = $this->stageFor($url);

        return match ($response->status()) {
            400 => new MailImportFailure($stage, MailImportCode::StateMismatch, httpStatus: $response->status()),
            401 => new MailImportFailure($stage, MailImportCode::AuthenticationFailed, httpStatus: $response->status()),
            404 => str_contains($url, '/history')
                ? new MailImportFailure($stage, MailImportCode::HistoryExpired, httpStatus: $response->status())
                : new MailImportFailure($stage, MailImportCode::MessageUnavailable, httpStatus: $response->status()),
            408, 425, 429 => new MailImportFailure(
                $stage,
                MailImportCode::RateLimited,
                true,
                retryAfterSeconds: $this->retryAfter($response),
                httpStatus: $response->status(),
            ),
            500, 502, 503, 504 => new MailImportFailure($stage, MailImportCode::ProviderUnavailable, true, httpStatus: $response->status()),
            403 => new MailImportFailure($stage, MailImportCode::PermissionDenied, httpStatus: $response->status()),
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

    private function stageFor(string $url): MailImportStage
    {
        return str_contains($url, '/messages/') ? MailImportStage::Retrieve : MailImportStage::Inventory;
    }

    /**
     * @param  array<string, mixed>  $message
     * @return list<array{name: string, value: string}>
     */
    private function headers(array $message): array
    {
        $payload = $message['payload'] ?? null;
        $nativeHeaders = is_array($payload) ? ($payload['headers'] ?? null) : null;

        if (! is_array($nativeHeaders)) {
            throw new MailImportFailure(MailImportStage::Retrieve, MailImportCode::MalformedPayload);
        }

        if (count($nativeHeaders) > 10000) {
            throw new MailImportFailure(MailImportStage::Retrieve, MailImportCode::MalformedPayload);
        }

        $totalBytes = 0;

        return array_values(array_map(function (mixed $header) use (&$totalBytes): array {
            $name = is_array($header) ? ($header['name'] ?? null) : null;
            $value = is_array($header) ? ($header['value'] ?? null) : null;

            if (! is_string($name) || trim($name) === '' || ! is_string($value)) {
                throw new MailImportFailure(MailImportStage::Retrieve, MailImportCode::MalformedPayload);
            }

            $totalBytes += strlen($name) + strlen($value);

            if ($totalBytes > $this->maxRawBytes()) {
                throw new MailImportFailure(MailImportStage::Retrieve, MailImportCode::MalformedPayload);
            }

            return ['name' => $name, 'value' => $value];
        }, $nativeHeaders));
    }

    /** @return list<array{role: string, address: string, name?: string}> */
    private function participants(object $parsed): array
    {
        $participants = [];
        $roles = ['from' => 'from', 'sender' => 'sender', 'reply-to' => 'reply_to', 'to' => 'to', 'cc' => 'cc', 'bcc' => 'bcc'];

        foreach ($roles as $headerName => $role) {
            if (! method_exists($parsed, 'getHeader')) {
                throw new InvalidArgumentException;
            }

            $header = $parsed->getHeader($headerName);

            if ($header === null) {
                continue;
            }

            if (! $header instanceof AddressHeader) {
                throw new InvalidArgumentException;
            }

            foreach ($header->getAddresses() as $address) {
                $entry = ['role' => $role, 'address' => $address->getEmail()];

                if ($address->getName() !== '') {
                    $entry['name'] = $address->getName();
                }

                $participants[] = $entry;
            }
        }

        return $participants;
    }

    /** @return list<array{provider_id: string, filename?: string, media_type?: string, byte_size?: int, content_id?: string, is_inline?: bool, provider_metadata: array<string, mixed>}> */
    private function attachments(mixed $root): array
    {
        if (! is_array($root)) {
            throw new MailImportFailure(MailImportStage::Retrieve, MailImportCode::MalformedPayload);
        }

        $attachments = [];
        $partCount = 0;
        $walk = function (array $part, int $depth = 0) use (&$walk, &$attachments, &$partCount): void {
            $partCount++;

            if ($depth > 100 || $partCount > 10000) {
                throw new MailImportFailure(MailImportStage::Retrieve, MailImportCode::MalformedPayload);
            }

            $body = $part['body'] ?? [];
            $attachmentId = is_array($body) ? ($body['attachmentId'] ?? null) : null;

            if (is_string($attachmentId)) {
                $headers = is_array($part['headers'] ?? null) ? $part['headers'] : [];
                $contentId = null;

                foreach ($headers as $header) {
                    $headerName = is_array($header) ? ($header['name'] ?? null) : null;

                    if (is_string($headerName) && strcasecmp($headerName, 'Content-ID') === 0
                        && is_string($header['value'] ?? null)) {
                        $contentId = trim($header['value'], '<>');
                    }
                }

                $attachments[] = array_filter([
                    'provider_id' => $attachmentId,
                    'filename' => is_string($part['filename'] ?? null) ? $part['filename'] : null,
                    'media_type' => is_string($part['mimeType'] ?? null) ? $part['mimeType'] : null,
                    'byte_size' => is_int($body['size'] ?? null) ? $body['size'] : null,
                    'content_id' => $contentId,
                    'is_inline' => $contentId !== null,
                    'provider_metadata' => ['part_id' => is_string($part['partId'] ?? null) ? $part['partId'] : null],
                ], fn (mixed $value, string $key): bool => $key === 'provider_metadata' || $value !== null, ARRAY_FILTER_USE_BOTH);

                if (count($attachments) > 1000) {
                    throw new MailImportFailure(MailImportStage::Retrieve, MailImportCode::MalformedPayload);
                }
            }

            foreach (is_array($part['parts'] ?? null) ? $part['parts'] : [] as $child) {
                if (! is_array($child)) {
                    throw new MailImportFailure(MailImportStage::Retrieve, MailImportCode::MalformedPayload);
                }

                $walk($child, $depth + 1);
            }
        };
        $walk($root);

        return $attachments;
    }

    /** @return iterable<array<string, mixed>> */
    private function historyMessages(mixed $events): iterable
    {
        if (! is_array($events)) {
            throw new MailImportFailure(MailImportStage::Inventory, MailImportCode::MalformedPayload);
        }

        foreach ($events as $event) {
            $message = is_array($event) ? ($event['message'] ?? null) : null;

            if (! is_array($message)) {
                throw new MailImportFailure(MailImportStage::Inventory, MailImportCode::MalformedPayload);
            }

            /** @var array<string, mixed> $message */
            yield $message;
        }
    }

    /** @param array<string, mixed> $message */
    private function historyMessageId(array $message): string
    {
        $id = $message['id'] ?? null;

        if (! is_string($id) || trim($id) === '' || mb_strlen($id) > 255) {
            throw new MailImportFailure(MailImportStage::Inventory, MailImportCode::MalformedPayload);
        }

        return $id;
    }

    /** @param array<string, mixed> $metadata */
    private function reference(MailAccount $account, mixed $native, array $metadata): MessageReference
    {
        $id = is_array($native) ? ($native['id'] ?? null) : null;
        $threadId = is_array($native) ? ($native['threadId'] ?? null) : null;

        if (! is_string($id) || ! is_string($threadId)) {
            throw new MailImportFailure(MailImportStage::Inventory, MailImportCode::MalformedPayload);
        }

        return new MessageReference($account->id, MailDriver::Gmail, $id, $threadId, $metadata);
    }

    /** @param list<array{name: string, value: string}> $headers */
    private function headerValue(array $headers, string $name): ?string
    {
        foreach ($headers as $header) {
            if (strcasecmp($header['name'], $name) === 0) {
                return $header['value'];
            }
        }

        return null;
    }

    private function dateHeader(?string $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  list<string>  $labelIds
     * @return array{unread: bool, flagged: bool, draft: bool, sent: bool, spam: bool, trash: bool}
     */
    private function mailboxState(array $labelIds): array
    {
        return [
            'unread' => in_array('UNREAD', $labelIds, true),
            'flagged' => in_array('STARRED', $labelIds, true),
            'draft' => in_array('DRAFT', $labelIds, true),
            'sent' => in_array('SENT', $labelIds, true),
            'spam' => in_array('SPAM', $labelIds, true),
            'trash' => in_array('TRASH', $labelIds, true),
        ];
    }

    private function changedMessageState(MailAccount $account, string $providerMessageId): ChangedMessageState
    {
        $native = $this->request(
            $account,
            'GET',
            self::API.'/users/me/messages/'.rawurlencode($providerMessageId),
            ['format' => 'minimal'],
        );
        $id = $native['id'] ?? null;
        $threadId = $native['threadId'] ?? null;
        $historyId = $native['historyId'] ?? null;
        $labelIds = $native['labelIds'] ?? null;

        if ($id !== $providerMessageId || ! is_string($threadId) || ! is_string($historyId)
            || ! is_array($labelIds)
            || array_filter($labelIds, fn (mixed $labelId): bool => ! is_string($labelId)) !== []) {
            throw new MailImportFailure(MailImportStage::Retrieve, MailImportCode::MalformedPayload);
        }

        /** @var list<string> $labelIds */
        $labelIds = array_values($labelIds);
        $containers = [];

        foreach ($labelIds as $labelId) {
            $label = $this->labels[$account->id][$labelId] ?? [
                'id' => $labelId,
                'name' => $labelId,
                'kind' => 'label',
                'metadata' => [],
            ];
            $containers[] = [
                'provider_id' => $label['id'],
                'name' => $label['name'],
                'kind' => $label['kind'],
                'membership_id' => $providerMessageId.':'.$labelId,
                'provider_metadata' => $label['metadata'],
                'membership_metadata' => ['label_id' => $labelId],
            ];
        }

        return new ChangedMessageState(
            new MessageReference($account->id, MailDriver::Gmail, $providerMessageId, $threadId),
            [
                'history_id' => $historyId,
                'label_ids' => $labelIds,
                'mailbox_state' => $this->mailboxState($labelIds),
                'draft' => in_array('DRAFT', $labelIds, true),
            ],
            $containers,
        );
    }

    /** @return array{phase: string, history_id: string|null, page_token: string|null} */
    private function decodeCursor(string $cursor): array
    {
        if (strlen($cursor) > 8192) {
            throw new MailImportFailure(MailImportStage::Inventory, MailImportCode::StateMismatch);
        }

        $decoded = $this->base64UrlDecode($cursor, MailImportStage::Inventory, 6144);
        $state = json_decode($decoded, true);

        if (! is_array($state)
            || ! in_array($state['phase'] ?? null, ['history', 'full'], true)
            || (! is_string($state['history_id'] ?? null) && ($state['history_id'] ?? null) !== null)
            || (! is_string($state['page_token'] ?? null) && ($state['page_token'] ?? null) !== null)) {
            throw new MailImportFailure(MailImportStage::Inventory, MailImportCode::StateMismatch);
        }

        $historyId = $state['history_id'];
        $pageToken = $state['page_token'];

        /** @var 'full'|'history' $phase */
        $phase = $state['phase'];
        assert(is_string($historyId) || $historyId === null);
        assert(is_string($pageToken) || $pageToken === null);

        return ['phase' => $phase, 'history_id' => $historyId, 'page_token' => $pageToken];
    }

    /** @param array{phase: string, history_id: string|null, page_token: string|null} $state */
    private function encodeCursor(array $state): string
    {
        if (($state['history_id'] !== null && mb_strlen($state['history_id']) > 255)
            || ($state['page_token'] !== null && mb_strlen($state['page_token']) > 4096)) {
            throw new MailImportFailure(MailImportStage::Inventory, MailImportCode::MalformedPayload);
        }

        return rtrim(strtr(base64_encode(json_encode($state, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    private function fullRestartCursor(): string
    {
        return $this->encodeCursor(['phase' => 'full', 'history_id' => null, 'page_token' => null]);
    }

    private function base64UrlDecode(string $encoded, MailImportStage $stage, ?int $maximumDecodedBytes = null): string
    {
        if (! preg_match('/^[A-Za-z0-9_-]+={0,2}$/', $encoded)) {
            throw new MailImportFailure($stage, MailImportCode::MalformedPayload);
        }

        $unpadded = rtrim($encoded, '=');
        $padding = strlen($encoded) - strlen($unpadded);
        $remainder = strlen($unpadded) % 4;

        if ($remainder === 1 || ($padding > 0 && $padding !== 4 - $remainder)) {
            throw new MailImportFailure($stage, MailImportCode::MalformedPayload);
        }

        if ($maximumDecodedBytes !== null && intdiv(strlen($unpadded) * 3, 4) > $maximumDecodedBytes) {
            throw new MailImportFailure($stage, MailImportCode::MalformedPayload);
        }

        $decoded = base64_decode(strtr($unpadded, '-_', '+/'), true);

        if (! is_string($decoded)
            || rtrim(strtr(base64_encode($decoded), '+/', '-_'), '=') !== $unpadded) {
            throw new MailImportFailure($stage, MailImportCode::MalformedPayload);
        }

        return $decoded;
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
        if ($account->driver !== MailDriver::Gmail || ! $account->exists) {
            throw new MailImportFailure(MailImportStage::Inventory, MailImportCode::StateMismatch);
        }
    }

    private function pageSize(int $resourceBudget): int
    {
        $size = config('mail-mirror.gmail.page_size', 100);
        $size = is_int($size) && $size >= 1 && $size <= 500 ? $size : 100;
        $listedBudget = $this->budget?->remainingListedIds() ?? $size;

        return max(1, min($size, $resourceBudget, $listedBudget));
    }

    private function timeout(): int
    {
        $timeout = config('mail-mirror.gmail.timeout_seconds', 30);

        return is_int($timeout) && $timeout >= 1 && $timeout <= 120 ? $timeout : 30;
    }

    private function maxRawBytes(): int
    {
        $maximum = config('mail-mirror.gmail.max_raw_bytes', 52428800);

        return is_int($maximum) && $maximum >= 1048576 && $maximum <= 104857600 ? $maximum : 52428800;
    }

    private function resourceLimit(): int
    {
        $maximum = config('mail-mirror.inventory_page_max_messages', 500);

        return is_int($maximum) && $maximum >= 1 ? $maximum : 500;
    }
}
