<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Gmail;

use DateTimeImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use InvalidArgumentException;
use Jkudish\MailMirror\Contracts\DeltaMailboxReader;
use Jkudish\MailMirror\Credentials\MailAccountConnection;
use Jkudish\MailMirror\Credentials\OAuthTokenSetCredential;
use Jkudish\MailMirror\Enums\MailDriver;
use Jkudish\MailMirror\Enums\MailImportCode;
use Jkudish\MailMirror\Enums\MailImportStage;
use Jkudish\MailMirror\Exceptions\DeltaRepairRequired;
use Jkudish\MailMirror\Exceptions\GmailAuthorizationException;
use Jkudish\MailMirror\Exceptions\InventoryRestartRequired;
use Jkudish\MailMirror\Exceptions\MailImportFailure;
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
use Throwable;
use ZBateson\MailMimeParser\Header\AddressHeader;
use ZBateson\MailMimeParser\MailMimeParser;

final class GmailMailboxReader implements DeltaMailboxReader
{
    private const API = 'https://gmail.googleapis.com/gmail/v1';

    /** @var array<int, array<string, array{id: string, name: string, kind: string, metadata: array<string, mixed>}>> */
    private array $labels = [];

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

    public function inventoryPage(MailAccount $account, ?string $cursor): InventoryPage
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

    public function changesPage(MailAccount $account, ?string $cursor): MailboxChangesPage
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

        foreach ($finalStates as $providerMessageId => $deletion) {
            if ($deletion === null) {
                $messages[] = $this->changedMessageState($account, $providerMessageId);
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
        );
    }

    public function retrieve(MailAccount $account, MessageReference $message): RetrievedMessage
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
            $profile = $this->oauth->profile($credential);
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

    /**
     * @param  array<string, int|string|null>  $query
     * @return array<string, mixed>
     */
    private function request(MailAccount $account, string $method, string $url, array $query = []): array
    {
        if (config('mail-mirror.gmail.enabled') !== true) {
            throw new MailImportFailure(MailImportStage::Inventory, MailImportCode::ProviderUnavailable);
        }

        [$credential, $stored] = $this->credential($account);
        $response = $this->send($credential, $method, $url, $query);

        if ($response->status() === 401) {
            $credential = $this->refresh($account, $stored, $credential);
            $response = $this->send($credential, $method, $url, $query);

            if ($response->status() === 401) {
                $stored->refresh();
                $this->revoke($account, $stored);
                throw new MailImportFailure($this->stageFor($url), MailImportCode::AuthenticationFailed);
            }
        }

        if (! $response->successful()) {
            throw $this->failureFor($response, $url);
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new MailImportFailure($this->stageFor($url), MailImportCode::MalformedPayload);
        }

        /** @var array<string, mixed> $payload */
        return $payload;
    }

    /** @param array<string, int|string|null> $query */
    private function send(OAuthTokenSetCredential $credential, string $method, string $url, array $query): Response
    {
        try {
            return $this->http->withToken($credential->accessToken())->acceptJson()
                ->timeout($this->timeout())->send($method, $url, ['query' => $query]);
        } catch (Throwable) {
            throw new MailImportFailure($this->stageFor($url), MailImportCode::ProviderUnavailable, true);
        }
    }

    /** @return array{OAuthTokenSetCredential, MailAccountCredential} */
    private function credential(MailAccount $account): array
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

        if ($credential->expiresAt() !== null && $credential->expiresAt() <= new DateTimeImmutable('+30 seconds')) {
            $credential = $this->refresh($account, $stored, $credential);
            $stored->refresh();
        }

        return [$credential, $stored];
    }

    private function refresh(MailAccount $account, MailAccountCredential $stored, OAuthTokenSetCredential $credential): OAuthTokenSetCredential
    {
        $attemptedVersion = $stored->version;

        try {
            $replacement = $this->oauth->refresh($account, $stored, $credential);

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
            400 => new MailImportFailure($stage, MailImportCode::StateMismatch),
            401 => new MailImportFailure($stage, MailImportCode::AuthenticationFailed),
            404 => str_contains($url, '/history')
                ? new MailImportFailure($stage, MailImportCode::HistoryExpired)
                : new MailImportFailure($stage, MailImportCode::MessageUnavailable),
            408, 425, 429 => new MailImportFailure(
                $stage,
                MailImportCode::RateLimited,
                true,
                retryAfterSeconds: $this->retryAfter($response),
            ),
            500, 502, 503, 504 => new MailImportFailure($stage, MailImportCode::ProviderUnavailable, true),
            403 => new MailImportFailure($stage, MailImportCode::PermissionDenied),
            default => new MailImportFailure($stage, MailImportCode::UnexpectedFailure),
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

        return max(1, min($size, $resourceBudget));
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
