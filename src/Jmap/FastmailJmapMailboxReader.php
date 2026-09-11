<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Jmap;

use DateTimeImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use Jkudish\MailMirror\Contracts\MailboxReader;
use Jkudish\MailMirror\Credentials\ApiTokenCredential;
use Jkudish\MailMirror\Credentials\MailAccountConnection;
use Jkudish\MailMirror\Enums\MailDriver;
use Jkudish\MailMirror\Enums\MailImportCode;
use Jkudish\MailMirror\Enums\MailImportStage;
use Jkudish\MailMirror\Exceptions\InventoryRestartRequired;
use Jkudish\MailMirror\Exceptions\MailImportFailure;
use Jkudish\MailMirror\Models\MailAccount;
use Jkudish\MailMirror\Models\MailAccountCredential;
use Jkudish\MailMirror\Read\AccountProfile;
use Jkudish\MailMirror\Read\InventoryPage;
use Jkudish\MailMirror\Read\MailboxIdentity;
use Jkudish\MailMirror\Read\MessageReference;
use Jkudish\MailMirror\Read\ProviderDeletionEvidence;
use Jkudish\MailMirror\Read\ProviderDeletionResolution;
use Jkudish\MailMirror\Read\RawMessageSource;
use Jkudish\MailMirror\Read\RetrievedMessage;
use Throwable;

final class FastmailJmapMailboxReader implements MailboxReader
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

    public function __construct(
        private readonly Factory $http,
        private readonly MailAccountConnection $connections,
    ) {}

    public function driver(): MailDriver
    {
        return MailDriver::Jmap;
    }

    public function inventoryPage(MailAccount $account, ?string $cursor): InventoryPage
    {
        $this->assertAccount($account);
        $session = $this->session($account, true);
        $state = $cursor === null ? null : $this->decodeCursor($cursor);

        if ($state === null || $state['phase'] === 'resources') {
            return $this->resourcePage($account, $session);
        }

        if ($state['account_id'] !== $account->provider_account_id
            || $state['session_state'] !== $session['session_state']) {
            throw new InventoryRestartRequired($this->resourceRestartCursor($account, $session));
        }

        if ($state['phase'] === 'changes') {
            return $this->changesPage($account, $session, $state);
        }

        return $this->fullPage($account, $session, $state);
    }

    public function retrieve(MailAccount $account, MessageReference $message): RetrievedMessage
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
            'query_state' => null,
            'position' => 0,
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
     * @param  array{phase: string, account_id: string, session_state: string, email_state: string|null, query_state: string|null, position: int}  $cursor
     */
    private function changesPage(MailAccount $account, array $session, array $cursor): InventoryPage
    {
        try {
            $result = $this->call($account, $session, 'Email/changes', [
                'accountId' => $account->provider_account_id,
                'sinceState' => $cursor['email_state'],
                'maxChanges' => $this->resourceLimit(),
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
            'query_state' => null,
            'position' => 0,
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
     * @param  array{phase: string, account_id: string, session_state: string, email_state: string|null, query_state: string|null, position: int}  $cursor
     */
    private function fullPage(MailAccount $account, array $session, array $cursor): InventoryPage
    {
        $result = $this->call($account, $session, 'Email/query', [
            'accountId' => $account->provider_account_id,
            'position' => $cursor['position'],
            'limit' => $this->pageSize(),
            'calculateTotal' => true,
        ], 'query', MailImportStage::Inventory);
        $ids = $this->stringList($result['ids'] ?? [], 255, MailImportStage::Inventory);
        $queryState = $this->boundedString($result['queryState'] ?? null, 255, MailImportStage::Inventory);
        $total = $this->nonNegativeInteger($result['total'] ?? null, MailImportStage::Inventory);
        $responsePosition = $this->nonNegativeInteger($result['position'] ?? null, MailImportStage::Inventory);

        if ($responsePosition !== $cursor['position']) {
            throw new MailImportFailure(MailImportStage::Inventory, MailImportCode::StateMismatch);
        }

        if ($cursor['query_state'] !== null && $cursor['query_state'] !== $queryState) {
            throw new InventoryRestartRequired($this->freshFullCursor($account, $session));
        }

        if (count($ids) > $this->pageSize()) {
            throw new MailImportFailure(MailImportStage::Inventory, MailImportCode::MalformedPayload);
        }

        $position = $cursor['position'] + count($ids);

        if ($position > $total || ($ids === [] && $position < $total)) {
            throw new MailImportFailure(MailImportStage::Inventory, MailImportCode::StateMismatch);
        }

        $references = $this->referencesForIds($account, $session, $ids, 'full');
        $complete = $position === $total;
        $emailState = $this->boundedString($cursor['email_state'], 255, MailImportStage::Inventory);
        $profile = $this->profile($account, $session, ['email_state' => $emailState]);

        return new InventoryPage(
            $references,
            $complete ? null : $this->encodeCursor([
                'phase' => 'full',
                'account_id' => $account->provider_account_id,
                'session_state' => $session['session_state'],
                'email_state' => $emailState,
                'query_state' => $queryState,
                'position' => $position,
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
    private function call(MailAccount $account, array $session, string $method, array $arguments, string $callId, MailImportStage $stage): array
    {
        $payload = $this->jsonRequest($account, 'POST', $session['api_url'], [
            'using' => [self::CORE, self::MAIL, self::SUBMISSION],
            'methodCalls' => [[$method, $arguments, $callId]],
        ], $stage);
        $responseSessionState = $this->boundedString($payload['sessionState'] ?? null, 255, $stage);

        if ($responseSessionState !== $session['session_state']) {
            $freshSession = $this->session($account, true);

            if ($stage === MailImportStage::Inventory) {
                throw new InventoryRestartRequired($this->resourceRestartCursor($account, $freshSession));
            }

            throw new MailImportFailure($stage, MailImportCode::StateMismatch, true);
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
    private function jsonRequest(MailAccount $account, string $method, string $url, ?array $body, MailImportStage $stage): array
    {
        if (config('mail-mirror.jmap.enabled') !== true) {
            throw new MailImportFailure($stage, MailImportCode::ProviderUnavailable);
        }

        $this->assertFastmailApiUrl($url);
        [$credential, $stored] = $this->credential($account, $stage);
        $response = $this->sendWithRetries($credential, $method, $url, $body, $stage);

        if ($response->status() === 401) {
            $this->revoke($account, $stored, $stage);
            throw new MailImportFailure($stage, MailImportCode::AuthenticationFailed);
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

    /** @param array<string, mixed>|null $body */
    private function sendWithRetries(ApiTokenCredential $credential, string $method, string $url, ?array $body, MailImportStage $stage): Response
    {
        $maximum = config('mail-mirror.jmap.request_max_attempts', 3);
        $maximum = is_int($maximum) && $maximum >= 1 && $maximum <= 5 ? $maximum : 3;

        for ($attempt = 1; $attempt <= $maximum; $attempt++) {
            try {
                $pending = $this->http->withToken($credential->token())->acceptJson()->timeout($this->timeout());
                $response = $pending->send($method, $url, $body === null ? [] : ['json' => $body]);
            } catch (Throwable) {
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
            401 => new MailImportFailure($stage, MailImportCode::AuthenticationFailed),
            403 => new MailImportFailure($stage, MailImportCode::PermissionDenied),
            404 => new MailImportFailure($stage, MailImportCode::MessageUnavailable),
            408, 425, 429 => new MailImportFailure($stage, MailImportCode::RateLimited, true, retryAfterSeconds: $this->retryAfter($response)),
            500, 502, 503, 504 => new MailImportFailure($stage, MailImportCode::ProviderUnavailable, true),
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

    /** @return array{phase: string, account_id: string, session_state: string, email_state: string|null, query_state: string|null, position: int} */
    private function decodeCursor(string $cursor): array
    {
        if (strlen($cursor) > 8192 || ! preg_match('/^[A-Za-z0-9_-]+$/', $cursor)) {
            throw new MailImportFailure(MailImportStage::Inventory, MailImportCode::StateMismatch);
        }

        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        $state = is_string($decoded) ? json_decode($decoded, true) : null;

        if (! is_array($state) || ! in_array($state['phase'] ?? null, ['resources', 'changes', 'full'], true)
            || ! is_string($state['account_id'] ?? null) || $state['account_id'] === ''
            || ! is_string($state['session_state'] ?? null) || $state['session_state'] === ''
            || (! is_string($state['email_state'] ?? null) && ($state['email_state'] ?? null) !== null)
            || (! is_string($state['query_state'] ?? null) && ($state['query_state'] ?? null) !== null)
            || ! is_int($state['position'] ?? null) || $state['position'] < 0
            || ($state['phase'] === 'resources' && ($state['email_state'] !== null || $state['query_state'] !== null || $state['position'] !== 0))
            || ($state['phase'] === 'changes' && (($state['email_state'] ?? '') === '' || $state['query_state'] !== null || $state['position'] !== 0))
            || ($state['phase'] === 'full' && (($state['email_state'] ?? '') === ''
                || ($state['position'] === 0 && $state['query_state'] !== null)
                || ($state['position'] > 0 && ($state['query_state'] ?? '') === '')))) {
            throw new MailImportFailure(MailImportStage::Inventory, MailImportCode::StateMismatch);
        }

        /** @var array{phase: string, account_id: string, session_state: string, email_state: string|null, query_state: string|null, position: int} $state */
        return $state;
    }

    /** @param array{phase: string, account_id: string, session_state: string, email_state: string|null, query_state: string|null, position: int} $state */
    private function encodeCursor(array $state): string
    {
        foreach ([$state['account_id'], $state['session_state'], $state['email_state'], $state['query_state']] as $value) {
            if ($value !== null && mb_strlen($value) > 255) {
                throw new MailImportFailure(MailImportStage::Inventory, MailImportCode::MalformedPayload);
            }
        }

        return rtrim(strtr(base64_encode(json_encode($state, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    /**
     * @param  array{api_url: string, download_url: string, session_state: string}  $session
     * @return array{phase: string, account_id: string, session_state: string, email_state: string, query_state: null, position: int}
     */
    private function fullState(MailAccount $account, array $session, string $emailState): array
    {
        return [
            'phase' => 'full',
            'account_id' => $account->provider_account_id,
            'session_state' => $session['session_state'],
            'email_state' => $emailState,
            'query_state' => null,
            'position' => 0,
        ];
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
            'query_state' => null,
            'position' => 0,
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

        return min($size, $this->resourceLimit());
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
