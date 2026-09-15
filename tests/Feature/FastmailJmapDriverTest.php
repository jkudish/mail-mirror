<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Jkudish\MailMirror\Credentials\ApiTokenCredential;
use Jkudish\MailMirror\Credentials\MailAccountConnection;
use Jkudish\MailMirror\Credentials\OAuthTokenSetCredential;
use Jkudish\MailMirror\Enums\ConnectionStatus;
use Jkudish\MailMirror\Enums\MailDriver;
use Jkudish\MailMirror\Enums\MailImportCode;
use Jkudish\MailMirror\Exceptions\MailImportFailure;
use Jkudish\MailMirror\Import\MailImportEngine;
use Jkudish\MailMirror\Jmap\FastmailJmapMailboxReader;
use Jkudish\MailMirror\Models\MailAccount;
use Jkudish\MailMirror\Models\MailAttachment;
use Jkudish\MailMirror\Models\MailDeltaPendingMessage;
use Jkudish\MailMirror\Models\MailIdentity;
use Jkudish\MailMirror\Models\MailInventoryItem;
use Jkudish\MailMirror\Models\MailMessage;
use Jkudish\MailMirror\Models\MailMessageContainerMembership;
use Jkudish\MailMirror\Models\MailProviderDeletionEvidence;
use Jkudish\MailMirror\Models\MailRawObject;
use Jkudish\MailMirror\Models\MailSyncCheckpoint;
use Jkudish\MailMirror\Models\MailThread;
use Jkudish\MailMirror\Read\MailDriverRegistry;
use Jkudish\MailMirror\Read\MessageReference;

final class JmapRequestState
{
    public bool $reject = false;
}

final class JmapInventoryState
{
    public bool $changes = false;

    public bool $excludeChangedFromFull = false;

    public ?string $emailState = null;

    public ?int $mutateAtPosition = null;

    public ?int $mutateQueryStateAtPosition = null;

    public ?int $prematureEmptyAtPosition = null;

    /** @var list<string>|null */
    public ?array $fullIds = null;

    /** @var list<string> */
    public array $updatedIds = ['jmap-email-a'];

    /** @var list<string> */
    public array $destroyedIds = ['jmap-email-gone'];

    /** @var list<array<string, mixed>>|null */
    public ?array $identityList = null;

    public ?string $changesOldState = null;

    public bool $stalledChanges = false;

    public int $queryPositionOffset = 0;

    public int $anchoredQueryPositionOffset = 0;

    public bool $removeAnchorBeforeNextPage = false;

    public bool $anchorNotFoundOnce = false;

    public ?int $anchoredPositionOverride = null;

    public bool $prependBeforeAnchoredPage = false;

    public int $numericQueryRequests = 0;

    /** @var list<string> */
    public array $anchoredQueryRequests = [];

    public string $sessionState = 'jmap-session-state-3207';

    public ?string $nextResponseSessionState = null;
}

/** @return array<string, mixed> */
function jmapFixture(): array
{
    $fixture = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/jmap-driver.json'), true, flags: JSON_THROW_ON_ERROR);
    assert(is_array($fixture));

    /** @var array<string, mixed> $fixture */
    return $fixture;
}

function jmapAccount(string $owner = 'owner-one'): MailAccount
{
    $account = MailAccount::query()->create([
        'owner_type' => 'synthetic-workspace',
        'owner_id' => $owner,
        'driver' => MailDriver::Jmap,
        'provider_account_id' => 'jmap-account-synthetic-3207',
    ]);
    app(MailAccountConnection::class)->store($account, new ApiTokenCredential('synthetic-jmap-token-3207'));

    return $account;
}

/**
 * @param  array<string, mixed>  $result
 * @return array<string, mixed>
 */
function jmapResponse(string $method, array $result, string $callId, string $sessionState = 'jmap-session-state-3207'): array
{
    return ['methodResponses' => [[$method, $result, $callId]], 'sessionState' => $sessionState];
}

/**
 * @param  array<string, mixed>  $fixture
 * @return array<string, mixed>
 */
function jmapFixturePart(array $fixture, string $key): array
{
    $part = $fixture[$key] ?? null;
    assert(is_array($part));

    /** @var array<string, mixed> $part */
    return $part;
}

/** @param array<string, int> $calls */
function jmapCallCount(array $calls, string $key): int
{
    return $calls[$key] ?? 0;
}

/**
 * @param  array<string, mixed>  $fixture
 * @param  array<string, int>  $calls
 */
function fakeJmap(
    array $fixture,
    array &$calls,
    bool $changes = false,
    ?JmapInventoryState $inventoryState = null,
): void {
    $raw = (string) file_get_contents(__DIR__.'/../Fixtures/synthetic-message.eml');
    $state = $inventoryState ?? new JmapInventoryState;

    if ($inventoryState === null) {
        $state->changes = $changes;
    }

    Http::fake(function (Request $request) use ($fixture, &$calls, $state, $raw) {
        if ($request->method() === 'GET' && $request->url() === 'https://api.fastmail.com/jmap/session') {
            $calls['Session/get'] = ($calls['Session/get'] ?? 0) + 1;

            $session = jmapFixturePart($fixture, 'session');
            $session['state'] = $state->sessionState;

            return Http::response($session);
        }

        if ($request->method() === 'GET' && str_contains($request->url(), '/jmap/download/')) {
            $calls['Blob/download'] = ($calls['Blob/download'] ?? 0) + 1;

            return Http::response($raw, 200, ['Content-Type' => 'message/rfc822']);
        }

        $data = $request->data();
        $methodCalls = $data['methodCalls'] ?? null;
        assert(is_array($methodCalls));
        $nativeCall = $methodCalls[0] ?? null;
        assert(is_array($nativeCall));
        $method = $nativeCall[0] ?? null;
        $arguments = $nativeCall[1] ?? null;
        $callId = $nativeCall[2] ?? null;
        assert(is_string($method) && is_array($arguments) && is_string($callId));
        $calls[$method] = ($calls[$method] ?? 0) + 1;
        $responseSessionState = $state->nextResponseSessionState ?? $state->sessionState;

        if ($state->nextResponseSessionState !== null) {
            $state->sessionState = $state->nextResponseSessionState;
            $state->nextResponseSessionState = null;
        }

        if ($method === 'Mailbox/get') {
            return Http::response(jmapResponse($method, jmapFixturePart($fixture, 'mailboxes'), $callId, $responseSessionState));
        }

        if ($method === 'Identity/get') {
            $identities = jmapFixturePart($fixture, 'identities');

            if ($state->identityList !== null) {
                $identities['list'] = $state->identityList;
            }

            return Http::response(jmapResponse($method, $identities, $callId, $responseSessionState));
        }

        if ($method === 'Email/changes') {
            $sinceState = $arguments['sinceState'] ?? null;
            assert(is_string($sinceState));
            $calls['Email/changes:since:'.$sinceState] = 1;
            $result = $state->changes
                ? ['accountId' => 'jmap-account-synthetic-3207', 'oldState' => $state->changesOldState ?? $sinceState, 'newState' => $state->stalledChanges ? $sinceState : 'jmap-email-state-2', 'hasMoreChanges' => $state->stalledChanges, 'created' => [], 'updated' => $state->updatedIds, 'destroyed' => $state->destroyedIds]
                : ['accountId' => 'jmap-account-synthetic-3207', 'oldState' => $arguments['sinceState'], 'newState' => 'jmap-email-state-1', 'hasMoreChanges' => false, 'created' => [], 'updated' => [], 'destroyed' => []];

            return Http::response(jmapResponse($method, $result, $callId, $responseSessionState));
        }

        if ($method === 'Email/query') {
            $anchor = $arguments['anchor'] ?? null;
            assert(($arguments['calculateTotal'] ?? null) === true);
            assert($anchor === null
                ? (($arguments['position'] ?? null) === 0 && ! array_key_exists('anchorOffset', $arguments))
                : (is_string($anchor) && ! array_key_exists('position', $arguments) && ($arguments['anchorOffset'] ?? null) === 1));

            if (is_string($anchor)) {
                $state->anchoredQueryRequests[] = $anchor;
            } else {
                $state->numericQueryRequests++;
            }

            $calls['Email/query:calculate-total'] = ($calls['Email/query:calculate-total'] ?? 0) + 1;
            $exclude = $state->excludeChangedFromFull;
            $fullIds = $state->fullIds ?? ($exclude
                ? ['jmap-email-draft']
                : ['jmap-email-a', 'jmap-email-draft']);

            if ($anchor !== null && $state->prependBeforeAnchoredPage) {
                array_unshift($fullIds, 'jmap-email-rfc-null');
            }

            $anchorPosition = $anchor === null ? false : array_search($anchor, $fullIds, true);
            $anchorMissing = $anchorPosition === false || $state->removeAnchorBeforeNextPage
                || ($state->anchorNotFoundOnce && count($state->anchoredQueryRequests) === 1);

            if ($anchor !== null && $anchorMissing) {
                return Http::response(jmapResponse('error', ['type' => 'anchorNotFound'], $callId, $responseSessionState));
            }

            $position = $anchorPosition === false ? 0 : $anchorPosition + 1;

            if ($state->mutateAtPosition === $position) {
                $state->emailState = 'jmap-email-state-during-full';
            }

            $ids = $state->prematureEmptyAtPosition === $position
                ? []
                : array_slice($fullIds, $position, 1);
            $queryState = 'jmap-query-state-1';

            if ($state->mutateQueryStateAtPosition === $position) {
                $queryState = 'jmap-query-state-mutated';
                $state->mutateQueryStateAtPosition = null;
            }

            $responsePosition = $position + $state->queryPositionOffset
                + ($anchor === null ? 0 : $state->anchoredQueryPositionOffset);

            if ($anchor !== null && $state->anchoredPositionOverride !== null
                && count($state->anchoredQueryRequests) === 1) {
                $responsePosition = $state->anchoredPositionOverride;
            }

            return Http::response(jmapResponse($method, [
                'accountId' => 'jmap-account-synthetic-3207',
                'queryState' => $queryState,
                'canCalculateChanges' => true,
                'position' => $responsePosition,
                'ids' => $ids,
                'total' => count($fullIds),
            ], $callId, $responseSessionState));
        }

        if ($method === 'Email/get') {
            $ids = $arguments['ids'] ?? [];
            assert(is_array($ids) && array_filter($ids, fn (mixed $id): bool => ! is_string($id)) === []);
            $properties = $arguments['properties'] ?? [];
            assert(is_array($properties));
            $exclude = $state->excludeChangedFromFull;
            if ($exclude && $properties === ['id', 'threadId'] && $ids === ['jmap-email-a']) {
                $calls['delta-candidate-get'] = ($calls['delta-candidate-get'] ?? 0) + 1;
            }
            $emails = jmapFixturePart($fixture, 'emails');
            $list = [];

            foreach ($ids as $id) {
                assert(is_string($id));
                $email = $emails[$id] ?? null;

                if (! is_array($email)) {
                    continue;
                }

                if ($properties === ['id', 'threadId']) {
                    $email = ['id' => $email['id'], 'threadId' => $email['threadId']];
                } elseif ($properties === ['id']) {
                    $email = ['id' => $email['id']];
                }

                $list[] = $email;
            }

            /** @var list<string> $ids */
            /** @var list<array<string, mixed>> $list */
            $foundIds = array_column($list, 'id');
            assert(array_filter($foundIds, fn (mixed $id): bool => ! is_string($id)) === []);
            /** @var list<string> $foundIds */

            return Http::response(jmapResponse($method, [
                'accountId' => 'jmap-account-synthetic-3207',
                'state' => $state->emailState
                    ?? ($state->changes ? 'jmap-email-state-2' : 'jmap-email-state-1'),
                'list' => $list,
                'notFound' => array_values(array_diff($ids, $foundIds)),
            ], $callId, $responseSessionState));
        }

        if ($method === 'Thread/get') {
            $threads = jmapFixturePart($fixture, 'threads');
            $ids = $arguments['ids'] ?? [];
            assert(is_array($ids) && array_filter($ids, fn (mixed $id): bool => ! is_string($id)) === []);
            /** @var list<string> $ids */
            $list = array_values(array_intersect_key($threads, array_flip($ids)));

            return Http::response(jmapResponse($method, [
                'accountId' => 'jmap-account-synthetic-3207',
                'state' => 'jmap-thread-state-1',
                'list' => $list,
                'notFound' => [],
            ], $callId, $responseSessionState));
        }

        return Http::response(['synthetic' => 'unexpected'], 500);
    });
}

beforeEach(function (): void {
    config()->set('mail-mirror.jmap', [
        'enabled' => true,
        'page_size' => 1,
        'timeout_seconds' => 5,
        'request_max_attempts' => 3,
        'max_raw_bytes' => 52428800,
    ]);
    Http::preventStrayRequests();
});

it('registers the production JMAP reader and cannot contact Fastmail unless explicitly enabled', function (): void {
    expect(app(MailDriverRegistry::class)->reader(MailDriver::Jmap))->toBeInstanceOf(FastmailJmapMailboxReader::class);
    config()->set('mail-mirror.jmap.enabled', false);
    $account = jmapAccount();

    try {
        app(FastmailJmapMailboxReader::class)->inventoryPage($account, null);
        throw new RuntimeException('The disabled JMAP reader performed an inventory.');
    } catch (MailImportFailure $failure) {
        expect($failure->safeCode)->toBe(MailImportCode::ProviderUnavailable);
    }

    Http::assertNothingSent();
});

it('accepts Fastmail regional API and content hosts', function (): void {
    $fixture = jmapFixture();
    $session = jmapFixturePart($fixture, 'session');
    $session['apiUrl'] = 'https://phl.api.fastmail.com/jmap/api/';
    $session['downloadUrl'] = 'https://phl-www.fastmailusercontent.com/jmap/download/{accountId}/{blobId}/{name}?type={type}';
    $fixture['session'] = $session;
    $account = jmapAccount();
    $calls = [];
    fakeJmap($fixture, $calls);
    $reader = app(FastmailJmapMailboxReader::class);

    $reader->inventoryPage($account, null);
    $message = $reader->retrieve($account, new MessageReference(
        $account->id,
        MailDriver::Jmap,
        'jmap-email-a',
        'jmap-thread-a',
    ));

    if (is_resource($message->rawSource?->stream)) {
        fclose($message->rawSource->stream);
    }

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://phl.api.fastmail.com/'));
    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://phl-www.fastmailusercontent.com/'));
});

it('rejects Fastmail lookalike endpoint hosts', function (string $field, string $url): void {
    $hostile = jmapFixture();
    $hostileSession = jmapFixturePart($hostile, 'session');
    $hostileSession[$field] = $url;
    $hostile['session'] = $hostileSession;
    $hostileAccount = jmapAccount();
    $hostileCalls = [];
    fakeJmap($hostile, $hostileCalls);

    expect(fn () => app(FastmailJmapMailboxReader::class)->inventoryPage($hostileAccount, null))
        ->toThrow(MailImportFailure::class);
})->with([
    'api' => ['apiUrl', 'https://phl.api.fastmail.com.hostile.test/jmap/api/'],
    'download' => ['downloadUrl', 'https://phl-www.fastmailusercontent.com.hostile.test/jmap/download/{accountId}/{blobId}/{name}'],
]);

it('keeps the direct-only live lane unreachable without its explicit opt-in', function (): void {
    $docs = (string) file_get_contents(__DIR__.'/../../docs/fastmail-jmap-live-development.md');
    putenv('MAIL_MIRROR_JMAP_ENABLED=1');
    $packageConfig = require __DIR__.'/../../config/mail-mirror.php';
    assert(is_array($packageConfig));
    $jmapConfig = $packageConfig['jmap'] ?? null;
    assert(is_array($jmapConfig));
    putenv('MAIL_MIRROR_JMAP_ENABLED');
    $command = sprintf('env -u MAIL_MIRROR_JMAP_LIVE_OPT_IN %s %s 2>&1',
        escapeshellarg(PHP_BINARY),
        escapeshellarg(__DIR__.'/../../scripts/fastmail-jmap-live-development-check.php'),
    );
    exec($command, $output, $exitCode);

    expect($jmapConfig['enabled'] ?? null)->toBeTrue()
        ->and($docs)->toContain('APP_ENV=local \\')
        ->and($docs)->toContain('MAIL_MIRROR_JMAP_ENABLED=1 \\')
        ->and($docs)->toContain('MAIL_MIRROR_JMAP_LIVE_'.'OPT_IN='."'I_UNDERSTAND_THIS_CONTACTS_FASTMAIL'".' \\')
        ->and($exitCode)->toBe(2)
        ->and(implode("\n", $output))->toContain('explicit live-provider opt-in is required');
});

it('accepts RFC 8621 nullable arrays, empty keyword maps, and empty content strings', function (): void {
    $fixture = jmapFixture();
    $account = jmapAccount();
    $calls = [];
    fakeJmap($fixture, $calls);
    $reader = app(FastmailJmapMailboxReader::class);

    $resources = $reader->inventoryPage($account, null);
    $identity = collect($resources->identities)
        ->firstWhere('providerIdentityId', 'jmap-identity-rfc-empty');
    $message = $reader->retrieve($account, new MessageReference(
        $account->id,
        MailDriver::Jmap,
        'jmap-email-rfc-null',
        'jmap-thread-rfc-null',
    ));

    try {
        expect($identity)->not->toBeNull()
            ->and($identity?->displayName)->toBe('')
            ->and($identity?->providerMetadata)->toMatchArray([
                'reply_to' => [],
                'bcc' => [],
                'text_signature' => '',
                'html_signature' => '',
            ])
            ->and($message->subject)->toBe('')
            ->and($message->internetMessageId)->toBeNull()
            ->and($message->participants)->toBe([])
            ->and($message->providerMetadata)->toMatchArray([
                'keywords' => [],
                'mailbox_state' => [
                    'unread' => true,
                    'flagged' => false,
                    'draft' => false,
                    'sent' => false,
                    'spam' => false,
                    'trash' => false,
                ],
                'preview' => '',
            ]);
    } finally {
        if (is_resource($message->rawSource?->stream)) {
            fclose($message->rawSource->stream);
        }
    }
});

it('imports complete paginated JMAP state and resumes after a crash', function (): void {
    $fixture = jmapFixture();
    $account = jmapAccount();
    $calls = [];
    fakeJmap($fixture, $calls);

    expect(app(MailImportEngine::class)->sync($account, 1))->toBeNull();
    $durable = MailSyncCheckpoint::query()->forAccount($account)->firstOrFail();
    expect($durable->provider_cursor)->not->toBeNull()
        ->and($durable->processed_count)->toBe(0)
        ->and(MailIdentity::query()->forAccount($account)->count())->toBe(2);

    $report = app(MailImportEngine::class)->sync($account);
    $draft = MailMessage::query()->forAccount($account)->where('provider_message_id', 'jmap-email-draft')->firstOrFail();
    $message = MailMessage::query()->forAccount($account)->where('provider_message_id', 'jmap-email-a')->firstOrFail();

    expect($report?->inventory_count)->toBe(2)
        ->and($report?->mirrored_count)->toBe(2)
        ->and(MailMessage::query()->forAccount($account)->count())->toBe(2)
        ->and(MailThread::query()->forAccount($account)->count())->toBe(1)
        ->and(MailRawObject::query()->forAccount($account)->count())->toBe(2)
        ->and(MailAttachment::query()->forAccount($account)->where('provider_attachment_id', 'jmap-part-2')->value('provider_metadata'))
        ->toMatchArray(['blob_id' => 'jmap-attachment-blob-a'])
        ->and(MailMessageContainerMembership::query()->forAccount($account)->where('mail_message_id', $message->id)->value('provider_membership_id'))->toBeNull()
        ->and($message->provider_metadata)->toMatchArray([
            'keywords' => ['$seen' => true, '$flagged' => true],
            'mailbox_state' => [
                'unread' => false,
                'flagged' => true,
                'draft' => false,
                'sent' => false,
                'spam' => false,
                'trash' => false,
            ],
            'draft' => false,
            'email_state' => 'jmap-email-state-1',
        ])
        ->and($message->thread?->provider_metadata)->toMatchArray(['thread_state' => 'jmap-thread-state-1'])
        ->and($draft->provider_metadata)->toMatchArray([
            'keywords' => ['$draft' => true],
            'mailbox_state' => [
                'unread' => true,
                'flagged' => false,
                'draft' => true,
                'sent' => false,
                'spam' => false,
                'trash' => false,
            ],
            'draft' => true,
        ])
        ->and($account->refresh()->provider_metadata)->toMatchArray([
            'session_state' => 'jmap-session-state-3207',
            'email_state' => 'jmap-email-state-1',
            'mailbox_state' => 'jmap-mailbox-state-1',
            'identity_state' => 'jmap-identity-state-1',
        ])
        ->and($calls['Session/get'])->toBeGreaterThanOrEqual(3)
        ->and($calls['Email/query:calculate-total'])->toBe(3)
        ->and($calls['Blob/download'])->toBeGreaterThanOrEqual(2);
});

it('uses opaque Email changes for deletion evidence then performs an authoritative full inventory', function (): void {
    $fixture = jmapFixture();
    $account = jmapAccount();
    $account->forceFill(['provider_metadata' => ['email_state' => 'jmap-email-state-old']])->save();
    $calls = [];
    fakeJmap($fixture, $calls, true);

    $report = app(MailImportEngine::class)->sync($account);

    expect($calls['Email/changes'])->toBe(1)
        ->and($report?->inventory_count)->toBe(2)
        ->and(MailProviderDeletionEvidence::query()->forAccount($account)->where('provider_message_id', 'jmap-email-gone')->value('proof_code'))
        ->toBe('jmap_email_destroyed')
        ->and($account->refresh()->provider_metadata['email_state'] ?? null)->toBe('jmap-email-state-2');
});

it('applies JMAP Email changes without Email query or raw download for existing messages', function (): void {
    $fixture = jmapFixture();
    $account = jmapAccount();
    $account->forceFill(['provider_metadata' => ['email_state' => 'jmap-email-state-1']])->save();
    MailMessage::query()->create([
        'mail_account_id' => $account->id,
        'provider_message_id' => 'jmap-email-a',
    ]);
    $calls = [];
    fakeJmap($fixture, $calls, true);

    $result = app(MailImportEngine::class)->syncChanges($account);

    expect($result->caughtUp)->toBeTrue()
        ->and(jmapCallCount($calls, 'Email/changes'))->toBe(1)
        ->and(jmapCallCount($calls, 'Email/query'))->toBe(0)
        ->and(jmapCallCount($calls, 'Blob/download'))->toBe(0)
        ->and(MailProviderDeletionEvidence::query()->forAccount($account)->where('provider_message_id', 'jmap-email-gone')->exists())->toBeTrue();
});

it('durably defers a JMAP changed message returned in notFound by state retrieval', function (): void {
    $fixture = jmapFixture();
    $account = jmapAccount();
    $account->forceFill(['provider_metadata' => ['email_state' => 'jmap-email-state-1']])->save();
    $calls = [];
    $state = new JmapInventoryState;
    $state->changes = true;
    $state->updatedIds = ['jmap-raced-message'];
    $state->destroyedIds = [];
    fakeJmap($fixture, $calls, inventoryState: $state);

    $result = app(MailImportEngine::class)->syncChanges($account);

    expect($result->caughtUp)->toBeFalse()
        ->and(jmapCallCount($calls, 'Email/changes'))->toBe(1)
        ->and(MailDeltaPendingMessage::query()->forAccount($account)->where('provider_message_id', 'jmap-raced-message')->exists())->toBeTrue();
});

it('uses Email changes only for evidence and excludes a changed message absent from full inventory', function (): void {
    $fixture = jmapFixture();
    $account = jmapAccount();
    $calls = [];
    $state = new JmapInventoryState;
    fakeJmap($fixture, $calls, inventoryState: $state);
    app(MailImportEngine::class)->sync($account);

    $calls = [];
    $state->changes = true;
    $state->excludeChangedFromFull = true;
    $report = app(MailImportEngine::class)->sync($account);

    expect(jmapCallCount($calls, 'Email/changes:since:jmap-email-state-1'))->toBe(1)
        ->and(jmapCallCount($calls, 'delta-candidate-get'))->toBe(0)
        ->and($report?->inventory_count)->toBe(1)
        ->and($report?->mirrored_count)->toBe(1)
        ->and($report?->unexpected_active_count)->toBe(1)
        ->and($report?->summary['unexpected_active'])->toBe([
            'sample' => ['jmap-email-a'],
            'truncated' => false,
        ])
        ->and(MailInventoryItem::query()->forAccount($account)
            ->where('provider_message_id', 'jmap-email-a')
            ->value('scan_id'))->not->toBe($report?->scan_id);
});

it('keeps the pre-full Email state baseline so changes during pagination replay next sync', function (): void {
    $fixture = jmapFixture();
    $account = jmapAccount();
    $calls = [];
    $state = new JmapInventoryState;
    $state->emailState = 'jmap-email-state-before-full';
    $state->mutateAtPosition = 2;
    fakeJmap($fixture, $calls, inventoryState: $state);

    app(MailImportEngine::class)->sync($account);

    expect($account->refresh()->provider_metadata['email_state'] ?? null)
        ->toBe('jmap-email-state-before-full');

    $calls = [];
    $state->changes = true;
    $state->mutateAtPosition = null;
    expect(app(MailImportEngine::class)->sync($account, 2))->toBeNull()
        ->and(jmapCallCount($calls, 'Email/changes:since:jmap-email-state-before-full'))->toBe(1);
});

it('carries deletion evidence through query-state drift and clears it on reappearance', function (): void {
    $fixture = jmapFixture();
    $account = jmapAccount();
    $calls = [];
    $state = new JmapInventoryState;
    fakeJmap($fixture, $calls, inventoryState: $state);
    $engine = app(MailImportEngine::class);
    $engine->sync($account);

    $state->changes = true;
    $state->updatedIds = [];
    $state->destroyedIds = ['jmap-email-a'];
    $state->fullIds = ['jmap-email-draft', 'jmap-email-rfc-null'];
    $state->mutateQueryStateAtPosition = 1;
    $durableScanIds = [];
    $report = $engine->sync($account, afterDurablePage: function (MailSyncCheckpoint $checkpoint) use (&$durableScanIds): void {
        $durableScanIds[] = $checkpoint->scan_id;
    });
    $evidenceScanId = MailProviderDeletionEvidence::query()->forAccount($account)
        ->where('provider_message_id', 'jmap-email-a')->value('scan_id');

    expect($report?->provider_deleted_count)->toBe(1)
        ->and($report?->unexpected_active_count)->toBe(0)
        ->and(array_values(array_unique($durableScanIds)))->toHaveCount(1)
        ->and($durableScanIds[0])->toBe($report?->scan_id)
        ->and(MailSyncCheckpoint::query()->forAccount($account)->value('scan_id'))->toBe($report?->scan_id);
    expect($evidenceScanId)->toBe($report?->scan_id);

    $state->changes = false;
    $state->fullIds = ['jmap-email-a', 'jmap-email-draft', 'jmap-email-rfc-null'];
    $reappeared = $engine->sync($account);

    expect($reappeared?->provider_deleted_count)->toBe(0)
        ->and($reappeared?->unexpected_active_count)->toBe(0)
        ->and(MailProviderDeletionEvidence::query()->forAccount($account)
            ->where('provider_message_id', 'jmap-email-a')->exists())->toBeFalse();
});

it('rejects malformed Email changes and query progression', function (string $malformation): void {
    $fixture = jmapFixture();
    $account = jmapAccount();
    $calls = [];
    $state = new JmapInventoryState;

    if ($malformation === 'old-state') {
        $account->forceFill(['provider_metadata' => ['email_state' => 'jmap-email-state-old']])->save();
        $state->changes = true;
        $state->changesOldState = 'jmap-email-state-wrong';
    } elseif ($malformation === 'stalled-changes') {
        $account->forceFill(['provider_metadata' => ['email_state' => 'jmap-email-state-old']])->save();
        $state->changes = true;
        $state->stalledChanges = true;
    } elseif ($malformation === 'premature-empty-query') {
        $state->prematureEmptyAtPosition = 0;
    } elseif ($malformation === 'anchor-not-found') {
        $state->removeAnchorBeforeNextPage = true;
    } elseif ($malformation === 'anchored-query-position') {
        $state->anchoredQueryPositionOffset = 1;
    } else {
        $state->queryPositionOffset = 1;
    }

    fakeJmap($fixture, $calls, inventoryState: $state);

    try {
        app(MailImportEngine::class)->sync($account);
        throw new RuntimeException('Malformed JMAP progression was accepted.');
    } catch (MailImportFailure $failure) {
        expect($failure->safeCode)->toBe(MailImportCode::StateMismatch);
    }
})->with(['old-state', 'stalled-changes', 'query-position', 'premature-empty-query', 'anchor-not-found', 'anchored-query-position']);

it('rejects an oversized Identity list before parsing nested identity fields', function (): void {
    $fixture = jmapFixture();
    $account = jmapAccount();
    $calls = [];
    $state = new JmapInventoryState;
    $state->identityList = [
        ['id' => 'bounded-identity', 'email' => 'bounded@invented.test'],
        ['id' => ['malformed-before-bound'], 'email' => ['malformed-before-bound']],
    ];
    config()->set('mail-mirror.inventory_page_max_messages', 1);
    fakeJmap($fixture, $calls, inventoryState: $state);

    try {
        app(FastmailJmapMailboxReader::class)->inventoryPage($account, null);
        throw new RuntimeException('An oversized JMAP Identity list was accepted.');
    } catch (MailImportFailure $failure) {
        expect($failure->safeCode)->toBe(MailImportCode::MalformedPayload);
    }

    expect($calls['Identity/get'])->toBe(1)
        ->and($calls['Email/query'] ?? 0)->toBe(0);
});

it('refreshes changed Session state in the same reader and restarts without stale inventory effects', function (): void {
    $fixture = jmapFixture();
    $account = jmapAccount();
    $calls = [];
    $state = new JmapInventoryState;
    fakeJmap($fixture, $calls, inventoryState: $state);
    $engine = app(MailImportEngine::class);

    expect($engine->sync($account, 1))->toBeNull();
    $firstCheckpoint = MailSyncCheckpoint::query()->forAccount($account)->firstOrFail();
    $firstScan = $firstCheckpoint->scan_id;

    $state->nextResponseSessionState = 'jmap-session-state-refreshed';
    expect($engine->sync($account, 1))->toBeNull();
    $restarted = MailSyncCheckpoint::query()->forAccount($account)->firstOrFail();

    expect($restarted->scan_id)->not->toBe($firstScan)
        ->and($restarted->provider_cursor)->not->toBeNull()
        ->and(MailInventoryItem::query()->forAccount($account)->count())->toBe(0)
        ->and(MailMessage::query()->forAccount($account)->count())->toBe(0)
        ->and($account->refresh()->provider_metadata['session_state'] ?? null)->toBe('jmap-session-state-refreshed')
        ->and($calls['Session/get'] ?? 0)->toBeGreaterThanOrEqual(4);
});

it('preserves a wrong local credential type without destructive revocation', function (): void {
    $account = MailAccount::query()->create([
        'owner_type' => 'synthetic-workspace',
        'owner_id' => 'wrong-credential-owner',
        'driver' => MailDriver::Jmap,
        'provider_account_id' => 'jmap-account-synthetic-3207',
    ]);
    $stored = app(MailAccountConnection::class)->store(
        $account,
        new OAuthTokenSetCredential('synthetic-oauth-token-3207'),
    );
    $before = $stored->only(['encrypted_payload', 'status', 'version']);

    try {
        app(FastmailJmapMailboxReader::class)->inventoryPage($account, null);
        throw new RuntimeException('A wrong local credential type was accepted.');
    } catch (MailImportFailure $failure) {
        expect($failure->safeCode)->toBe(MailImportCode::PermissionDenied);
    }

    expect($stored->refresh()->only(['encrypted_payload', 'status', 'version']))->toBe($before);
    Http::assertNothingSent();
});

it('uses stable anchors across query-state drift and insertion before the anchor', function (): void {
    $fixture = jmapFixture();
    $account = jmapAccount();
    $calls = [];
    $state = new JmapInventoryState;
    $state->mutateQueryStateAtPosition = 2;
    $state->prependBeforeAnchoredPage = true;
    fakeJmap($fixture, $calls, inventoryState: $state);
    $reader = app(FastmailJmapMailboxReader::class);

    $resources = $reader->inventoryPage($account, null);
    $first = $reader->inventoryPage($account, $resources->nextCursor);
    $second = $reader->inventoryPage($account, $first->nextCursor);
    $third = $reader->inventoryPage($account, $second->nextCursor);

    expect(collect($first->messages)->pluck('providerMessageId')->all())->toBe(['jmap-email-a'])
        ->and(collect($second->messages)->pluck('providerMessageId')->all())->toBe(['jmap-email-draft'])
        // A non-empty anchored page never completes the scan; only a final
        // empty anchored query may mark completion.
        ->and($second->complete)->toBeFalse()
        ->and(collect($third->messages)->pluck('providerMessageId')->all())->toBe([])
        ->and($third->complete)->toBeTrue()
        ->and($third->nextCursor)->toBeNull();

    expect($state->numericQueryRequests)->toBe(1)
        ->and($state->anchoredQueryRequests)->toBe(['jmap-email-a', 'jmap-email-draft']);
});

it('restarts a persisted legacy full cursor with a fresh scan while preserving its email state', function (): void {
    $fixture = jmapFixture();
    $account = jmapAccount();
    $legacyScanId = '00000000-0000-4000-8000-000000003207';
    // A newer state on the account must not be captured; the abandoned scan's
    // own baseline email_state has to survive so deletion evidence stays replayable.
    $account->forceFill(['provider_metadata' => ['email_state' => 'jmap-email-state-newer']])->save();
    $legacyCursor = rtrim(strtr(base64_encode(json_encode([
        'phase' => 'full',
        'account_id' => 'jmap-account-synthetic-3207',
        'session_state' => 'jmap-session-state-3207',
        'email_state' => 'jmap-email-state-legacy',
        'query_state' => 'jmap-query-state-legacy',
        'position' => 1,
    ], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    MailSyncCheckpoint::query()->create([
        'mail_account_id' => $account->id,
        'scan_id' => $legacyScanId,
        'version' => 0,
        'processed_count' => 0,
        'provider_cursor' => $legacyCursor,
        'scan_started_at' => now(),
    ]);
    $calls = [];
    fakeJmap($fixture, $calls);

    $report = app(MailImportEngine::class)->sync($account);

    expect($report?->inventory_count)->toBe(2)
        ->and(MailMessage::query()->forAccount($account)->count())->toBe(2)
        ->and($account->refresh()->provider_metadata['email_state'] ?? null)->toBe('jmap-email-state-legacy')
        ->and(MailSyncCheckpoint::query()->forAccount($account)->value('scan_id'))->not->toBe($legacyScanId)
        ->and(MailSyncCheckpoint::query()->forAccount($account)->value('provider_cursor'))->not->toBe($legacyCursor);
});

it('recovers when an anchored page reports anchorNotFound instead of failing terminally', function (): void {
    $fixture = jmapFixture();
    $account = jmapAccount();
    $calls = [];
    $state = new JmapInventoryState;
    $state->anchorNotFoundOnce = true;
    fakeJmap($fixture, $calls, inventoryState: $state);

    $report = app(MailImportEngine::class)->sync($account);

    expect($report?->inventory_count)->toBe(2)
        ->and(MailMessage::query()->forAccount($account)->count())->toBe(2)
        ->and($state->anchoredQueryRequests)->toBe(['jmap-email-a', 'jmap-email-a', 'jmap-email-draft'])
        ->and($account->refresh()->provider_metadata['email_state'] ?? null)->toBe('jmap-email-state-1');
});

it('refuses to complete the scan from a plausible-but-wrong anchored position', function (): void {
    $fixture = jmapFixture();
    $account = jmapAccount();
    $calls = [];
    $state = new JmapInventoryState;
    // The provider reports position 2 (total 3) while actually serving the
    // middle page: endPosition === total would previously complete the scan and
    // silently skip jmap-email-rfc-null.
    $state->anchoredPositionOverride = 2;
    $state->fullIds = ['jmap-email-a', 'jmap-email-draft', 'jmap-email-rfc-null'];
    fakeJmap($fixture, $calls, inventoryState: $state);

    $report = app(MailImportEngine::class)->sync($account);

    expect($report?->inventory_count)->toBe(3)
        ->and(MailMessage::query()->forAccount($account)->count())->toBe(3)
        ->and(MailMessage::query()->forAccount($account)->where('provider_message_id', 'jmap-email-rfc-null')->exists())->toBeTrue()
        ->and($calls['Email/query:calculate-total'])->toBe(4);
});

it('bounds Retry-After retries and revokes a rejected API token without exposing it', function (): void {
    $fixture = jmapFixture();
    $account = jmapAccount();
    $attempts = 0;
    $state = new JmapRequestState;
    Sleep::fake();

    Http::fake(function (Request $request) use ($fixture, &$attempts, $state) {
        if ($request->url() === 'https://api.fastmail.com/jmap/session') {
            if ($state->reject) {
                return Http::response(['hostile' => 'synthetic-jmap-token-3207'], 401);
            }

            $attempts++;

            return $attempts < 3
                ? Http::response(['hostile' => 'synthetic-token-must-not-escape'], 429, ['Retry-After' => '1'])
                : Http::response(jmapFixturePart($fixture, 'session'));
        }

        $methodCalls = $request->data()['methodCalls'] ?? null;
        assert(is_array($methodCalls));
        $call = $methodCalls[0] ?? null;
        assert(is_array($call));
        $method = $call[0] ?? null;
        $callId = $call[2] ?? null;
        assert(is_string($method) && is_string($callId));

        return match ($method) {
            'Mailbox/get' => Http::response(jmapResponse($method, jmapFixturePart($fixture, 'mailboxes'), $callId)),
            'Identity/get' => Http::response(jmapResponse($method, jmapFixturePart($fixture, 'identities'), $callId)),
            'Email/get' => Http::response(jmapResponse($method, [
                'accountId' => 'jmap-account-synthetic-3207',
                'state' => 'jmap-email-state-1',
                'list' => [],
                'notFound' => [],
            ], $callId)),
            default => Http::response([], 500),
        };
    });

    app(FastmailJmapMailboxReader::class)->inventoryPage($account, null);
    expect($attempts)->toBeGreaterThanOrEqual(3);
    Sleep::assertSlept(fn (DateInterval $duration): bool => $duration->s === 1, 2);

    $second = jmapAccount('owner-two');
    $state->reject = true;

    try {
        app(FastmailJmapMailboxReader::class)->inventoryPage($second, null);
        throw new RuntimeException('A revoked token was accepted.');
    } catch (MailImportFailure $failure) {
        expect($failure->safeCode)->toBe(MailImportCode::AuthenticationFailed)
            ->and((string) $failure)->not->toContain('synthetic-jmap-token-3207');
    }

    expect($second->credential()->firstOrFail()->status)->toBe(ConnectionStatus::Revoked);
});

it('fails closed for cross-account, cross-owner, cross-driver, and malformed resources with no effects', function (): void {
    $first = jmapAccount('owner-first');
    $second = jmapAccount('owner-second');
    $reader = app(FastmailJmapMailboxReader::class);
    $reference = new MessageReference($first->id, MailDriver::Jmap, 'jmap-email-a', 'jmap-thread-one');

    expect(fn () => $reader->retrieve($second, $reference))->toThrow(MailImportFailure::class)
        ->and(fn () => $reader->retrieve($first, new MessageReference($first->id, MailDriver::Gmail, 'jmap-email-a')))
        ->toThrow(MailImportFailure::class);
    Http::assertNothingSent();

    $forged = $first->replicate();
    $forged->setRawAttributes($first->getAttributes(), true);
    $forged->exists = true;
    $forged->owner_id = 'forged-owner';
    expect(fn () => $reader->inventoryPage($forged, null))->toThrow(MailImportFailure::class);
    Http::assertNothingSent();

    config()->set('mail-mirror.jmap.enabled', true);
    Http::fake(['*' => Http::response(['capabilities' => ['hostile-secret' => true]])]);
    expect(fn () => $reader->inventoryPage($first, null))->toThrow(MailImportFailure::class);

    Http::fakeSequence()
        ->push(jmapFixturePart(jmapFixture(), 'session'))
        ->push(jmapResponse('Mailbox/get', [
            'accountId' => 'different-jmap-account',
            'state' => 'hostile-state',
            'list' => [],
            'notFound' => [],
        ], 'mailboxes'));
    $freshReader = app()->make(FastmailJmapMailboxReader::class);
    expect(fn () => $freshReader->inventoryPage($first, null))->toThrow(MailImportFailure::class);

    expect(MailMessage::query()->count())->toBe(0)
        ->and(MailRawObject::query()->count())->toBe(0)
        ->and(MailSyncCheckpoint::query()->count())->toBe(0);
});
