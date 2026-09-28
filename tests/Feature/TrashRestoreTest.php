<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Jkudish\MailMirror\Contracts\MailboxReader;
use Jkudish\MailMirror\Contracts\TrashRestoreDriver;
use Jkudish\MailMirror\Credentials\ApiTokenCredential;
use Jkudish\MailMirror\Credentials\MailAccountConnection;
use Jkudish\MailMirror\Credentials\OAuthTokenSetCredential;
use Jkudish\MailMirror\Enums\MailDriver;
use Jkudish\MailMirror\Enums\MailImportCode;
use Jkudish\MailMirror\Enums\MailWriteCode;
use Jkudish\MailMirror\Enums\MailWriteOutcome;
use Jkudish\MailMirror\Exceptions\AccountResourceMismatch;
use Jkudish\MailMirror\Exceptions\MailWriteFailure;
use Jkudish\MailMirror\Exceptions\SyncBudgetExhausted;
use Jkudish\MailMirror\Gmail\GmailOAuth;
use Jkudish\MailMirror\Models\MailAccount;
use Jkudish\MailMirror\Read\InventoryPage;
use Jkudish\MailMirror\Read\MailDriverRegistry;
use Jkudish\MailMirror\Read\MessageReference;
use Jkudish\MailMirror\Read\RetrievedMessage;
use Jkudish\MailMirror\Write\MailWriteService;
use Jkudish\MailMirror\Write\MailWriteTarget;
use Jkudish\MailMirror\Write\TrashState;

/**
 * Synthetic Gmail message state keyed by access token, so two accounts can
 * share one provider message ID without sharing state.
 */
final class TrashRestoreGmailProvider
{
    /** @var array<string, array<string, array{labels: list<string>, prior: list<string>}>> */
    public array $messages = [];

    /** @var list<string> */
    public array $writes = [];

    public int $history = 7000;

    public ?int $untrashStatus = null;

    public bool $untrashIgnored = false;

    public bool $rejectWriteToken = false;

    /** @var (Closure(): void)|null */
    public ?Closure $onWrite = null;

    /** @return Closure(Request): mixed */
    public function handler(): Closure
    {
        return function (Request $request) {
            if ($request->url() === 'https://oauth2.googleapis.com/token') {
                return Http::response(['access_token' => 'synthetic-restore-refreshed', 'expires_in' => 3600, 'scope' => GmailOAuth::SCOPE]);
            }

            $authorization = $request->header('Authorization')[0] ?? null;
            assert(is_string($authorization));
            $token = substr($authorization, 7);
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if (preg_match('#^/gmail/v1/users/me/messages/([^/]+)(/untrash)?$#', $path, $matches) !== 1) {
                return Http::response(['error' => 'synthetic unexpected path'], 418);
            }

            $id = rawurldecode($matches[1]);

            if (($matches[2] ?? '') === '/untrash') {
                expect($request->method())->toBe('POST');
                $this->writes[] = $token.':'.$id;

                if ($this->onWrite !== null) {
                    ($this->onWrite)();
                }

                if ($this->rejectWriteToken) {
                    return Http::response(['error' => 'synthetic unauthenticated'], 401);
                }

                if ($this->untrashStatus !== null) {
                    return Http::response(['error' => 'synthetic provider failure'], $this->untrashStatus);
                }

                if (! isset($this->messages[$token][$id])) {
                    return Http::response(['error' => 'synthetic not found'], 404);
                }

                if (! $this->untrashIgnored) {
                    $this->messages[$token][$id]['labels'] = $this->messages[$token][$id]['prior'];
                    $this->history++;
                }

                return Http::response(['id' => $id, 'labelIds' => $this->messages[$token][$id]['labels']]);
            }

            expect($request->method())->toBe('GET')->and($request->data())->toBe(['format' => 'minimal']);

            if (! isset($this->messages[$token][$id])) {
                return Http::response(['error' => 'synthetic not found'], 404);
            }

            return Http::response([
                'id' => $id,
                'threadId' => 'synthetic-thread-'.$id,
                'historyId' => (string) $this->history,
                'labelIds' => $this->messages[$token][$id]['labels'],
            ]);
        };
    }
}

/** Synthetic Fastmail JMAP account state keyed by provider account ID. */
final class TrashRestoreJmapProvider
{
    /** @var array<string, array<string, array<string, true>>> */
    public array $emails = [];

    /** @var list<array{id: string, name: string, role: string|null}> */
    public array $mailboxes = [
        ['id' => 'mb-inbox', 'name' => 'Inbox', 'role' => 'inbox'],
        ['id' => 'mb-trash', 'name' => 'Trash', 'role' => 'trash'],
        ['id' => 'mb-archive', 'name' => 'Archive', 'role' => 'archive'],
        ['id' => 'mb-projects', 'name' => 'Projects', 'role' => null],
    ];

    public int $state = 40;

    /** @var list<array<string, mixed>> */
    public array $writes = [];

    /** @var list<string> */
    public array $methods = [];

    public ?int $setStatus = null;

    /** @var array<string, mixed>|null */
    public ?array $setError = null;

    /** @var array<string, mixed>|null */
    public ?array $notUpdated = null;

    public bool $setIgnored = false;

    /** When set, the provider moves the email here instead of applying the patch. */
    public ?string $setMovesTo = null;

    /** @return Closure(Request): mixed */
    public function handler(): Closure
    {
        return function (Request $request) {
            if ($request->url() === 'https://api.fastmail.com/jmap/session') {
                return Http::response([
                    'capabilities' => [
                        'urn:ietf:params:jmap:core' => [],
                        'urn:ietf:params:jmap:mail' => [],
                        'urn:ietf:params:jmap:submission' => [],
                    ],
                    'accounts' => array_fill_keys(array_keys($this->emails), ['accountCapabilities' => [
                        'urn:ietf:params:jmap:mail' => [],
                        'urn:ietf:params:jmap:submission' => [],
                    ]]),
                    'apiUrl' => 'https://api.fastmail.com/jmap/api/',
                    'downloadUrl' => 'https://www.fastmailusercontent.com/jmap/download/{accountId}/{blobId}/{name}?type={type}',
                    'state' => 'synthetic-restore-session',
                ]);
            }

            expect($request->url())->toBe('https://api.fastmail.com/jmap/api/');
            $calls = $request->data()['methodCalls'] ?? null;
            $call = is_array($calls) ? ($calls[0] ?? null) : null;
            assert(is_array($call) && is_string($call[0]) && is_array($call[1]) && is_string($call[2]));
            [$method, $arguments, $callId] = $call;
            /** @var array<string, mixed> $arguments */
            $accountId = $arguments['accountId'] ?? null;
            $ids = $arguments['ids'] ?? [];
            assert(is_string($accountId) && is_array($ids));
            $id = $ids[0] ?? null;
            $this->methods[] = $method;

            $reply = fn (string $name, array $result): mixed => Http::response([
                'methodResponses' => [[$name, ['accountId' => $accountId] + $result, $callId]],
                'sessionState' => 'synthetic-restore-session',
            ]);

            return match ($method) {
                'Mailbox/get' => $reply('Mailbox/get', ['state' => 'synthetic-mailbox-state', 'list' => $this->mailboxes, 'notFound' => []]),
                'Email/get' => is_string($id) && isset($this->emails[$accountId][$id])
                    ? $reply('Email/get', [
                        'state' => 'synthetic-email-state-'.$this->state,
                        'list' => [['id' => $id, 'mailboxIds' => $this->emails[$accountId][$id]]],
                        'notFound' => [],
                    ])
                    : $reply('Email/get', ['state' => 'synthetic-email-state-'.$this->state, 'list' => [], 'notFound' => [$id]]),
                'Email/set' => $this->set($accountId, $arguments, $reply),
                default => Http::response(['error' => 'synthetic unexpected method'], 418),
            };
        };
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  Closure(string, array<string, mixed>): mixed  $reply
     */
    private function set(string $accountId, array $arguments, Closure $reply): mixed
    {
        $this->writes[] = $arguments;

        if ($this->setStatus !== null) {
            return Http::response(['error' => 'synthetic provider failure'], $this->setStatus);
        }

        if ($this->setError !== null) {
            return Http::response([
                'methodResponses' => [['error', $this->setError, 'restore']],
                'sessionState' => 'synthetic-restore-session',
            ]);
        }

        if ($this->notUpdated !== null) {
            return $reply('Email/set', ['oldState' => 'synthetic-email-state-'.$this->state, 'newState' => 'synthetic-email-state-'.$this->state, 'updated' => null, 'notUpdated' => $this->notUpdated]);
        }

        $oldState = 'synthetic-email-state-'.$this->state;
        /** @var array<string, array<string, true|null>> $update */
        $update = $arguments['update'];

        foreach ($update as $id => $patch) {
            if ($this->setMovesTo !== null) {
                $this->emails[$accountId][$id] = [$this->setMovesTo => true];
                $this->state++;
            } elseif (! $this->setIgnored) {
                foreach ($patch as $path => $value) {
                    $mailbox = substr($path, strlen('mailboxIds/'));

                    if ($value === null) {
                        unset($this->emails[$accountId][$id][$mailbox]);
                    } else {
                        $this->emails[$accountId][$id][$mailbox] = true;
                    }
                }

                $this->state++;
            }
        }

        return $reply('Email/set', [
            'oldState' => $oldState,
            'newState' => 'synthetic-email-state-'.$this->state,
            'updated' => array_fill_keys(array_keys($update), null),
            'notUpdated' => null,
        ]);
    }
}

/** A Gmail-keyed driver whose confirming read throws or answers for another message. */
final class TrashRestoreConfirmationDriver implements MailboxReader, TrashRestoreDriver
{
    public int $reads = 0;

    public int $writes = 0;

    public function __construct(private readonly Closure $confirmation) {}

    public function driver(): MailDriver
    {
        return MailDriver::Gmail;
    }

    public function inventoryPage(MailAccount $account, ?string $cursor): InventoryPage
    {
        throw new LogicException('Not used by write tests.');
    }

    public function retrieve(MailAccount $account, MessageReference $message): RetrievedMessage
    {
        throw new LogicException('Not used by write tests.');
    }

    public function trashState(MailAccount $account, string $providerMessageId): TrashState
    {
        if (++$this->reads === 1) {
            return new TrashState($account->id, MailDriver::Gmail, $providerMessageId, true, false, ['label_ids' => ['TRASH']]);
        }

        $state = ($this->confirmation)($account, $providerMessageId);
        assert($state instanceof TrashState);

        return $state;
    }

    public function restoreFromTrash(MailAccount $account, TrashState $observed): void
    {
        $this->writes++;
    }
}

function restoreGmailAccount(string $owner, string $providerAccountId, string $token): MailAccount
{
    $account = MailAccount::query()->create([
        'owner_type' => 'synthetic-workspace',
        'owner_id' => $owner,
        'driver' => MailDriver::Gmail,
        'provider_account_id' => $providerAccountId,
    ]);
    app(MailAccountConnection::class)->store($account, new OAuthTokenSetCredential(
        $token,
        'synthetic-restore-refresh',
        new DateTimeImmutable('+1 hour'),
        [GmailOAuth::SCOPE],
    ));

    return $account;
}

function restoreJmapAccount(string $owner, string $providerAccountId): MailAccount
{
    $account = MailAccount::query()->create([
        'owner_type' => 'synthetic-workspace',
        'owner_id' => $owner,
        'driver' => MailDriver::Jmap,
        'provider_account_id' => $providerAccountId,
    ]);
    app(MailAccountConnection::class)->store($account, new ApiTokenCredential('synthetic-restore-jmap-token-'.$owner));

    return $account;
}

function restoreTarget(MailAccount $account, string $providerMessageId): MailWriteTarget
{
    return new MailWriteTarget($account->id, $account->owner_type, $account->owner_id, $providerMessageId);
}

/** Mirrors MailWriteService's account-namespaced intent key so tests can assert cache state. */
function restoreIntentKey(MailAccount $account, string $providerMessageId): string
{
    return sprintf('mail-mirror:account:%d:message:%s:intent:restore-from-trash', $account->id, hash('sha256', $providerMessageId));
}

function restoreFailure(MailWriteTarget $target): MailWriteFailure
{
    try {
        app(MailWriteService::class)->restoreFromTrash($target);
    } catch (MailWriteFailure $failure) {
        return $failure;
    }

    throw new RuntimeException('The restore reported success.');
}

beforeEach(function (): void {
    putenv('MAIL_MIRROR_GMAIL_CLIENT_SECRET=synthetic-restore-client-secret');
    config()->set('mail-mirror.gmail', [
        'enabled' => true,
        'client_id' => 'synthetic-restore-client.apps.example.test',
        'redirect_uri' => 'https://consumer.example.test/oauth/gmail/callback',
        'page_size' => 10,
        'timeout_seconds' => 5,
        'max_raw_bytes' => 52428800,
    ]);
    config()->set('mail-mirror.jmap', [
        'enabled' => true,
        'page_size' => 10,
        'timeout_seconds' => 5,
        'request_max_attempts' => 3,
        'max_raw_bytes' => 52428800,
    ]);
    Http::preventStrayRequests();
});

afterEach(function (): void {
    putenv('MAIL_MIRROR_GMAIL_CLIENT_SECRET');
});

it('registers both production readers as Trash-restore drivers', function (MailDriver $driver): void {
    expect(app(MailDriverRegistry::class)->reader($driver))->toBeInstanceOf(TrashRestoreDriver::class);
})->with([MailDriver::Gmail, MailDriver::Jmap]);

it('untrashes one Gmail message and reports success only from the confirming re-read', function (): void {
    $gmail = new TrashRestoreGmailProvider;
    $gmail->messages['synthetic-restore-access-a'] = [
        'gm-1' => ['labels' => ['TRASH', 'UNREAD'], 'prior' => ['INBOX', 'UNREAD', 'Label_7']],
        'gm-2' => ['labels' => ['TRASH'], 'prior' => ['INBOX']],
    ];
    Http::fake($gmail->handler());
    $account = restoreGmailAccount('owner-a', 'restore-a@invented.test', 'synthetic-restore-access-a');

    $result = app(MailWriteService::class)->restoreFromTrash(restoreTarget($account, 'gm-1'));

    expect($result->outcome)->toBe(MailWriteOutcome::Applied)
        ->and($result->mailAccountId)->toBe($account->id)
        ->and($result->driver)->toBe(MailDriver::Gmail)
        ->and($result->providerMessageId)->toBe('gm-1')
        ->and($result->providerEvidence)->toBe(['label_ids' => ['INBOX', 'UNREAD', 'Label_7'], 'history_id' => '7001'])
        ->and($gmail->writes)->toBe(['synthetic-restore-access-a:gm-1'])
        ->and($gmail->messages['synthetic-restore-access-a']['gm-2']['labels'])->toBe(['TRASH']);
    Http::assertSentInOrder([
        fn (Request $request): bool => $request->method() === 'GET' && str_contains($request->url(), '/messages/gm-1?'),
        fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/messages/gm-1/untrash'),
        fn (Request $request): bool => $request->method() === 'GET' && str_contains($request->url(), '/messages/gm-1?'),
    ]);
});

it('moves one JMAP email from the Trash role to the Inbox role and confirms it by re-read', function (): void {
    $jmap = new TrashRestoreJmapProvider;
    $jmap->emails['jmap-restore-a'] = ['jm-1' => ['mb-trash' => true], 'jm-2' => ['mb-trash' => true]];
    Http::fake($jmap->handler());
    $account = restoreJmapAccount('owner-a', 'jmap-restore-a');

    $result = app(MailWriteService::class)->restoreFromTrash(restoreTarget($account, 'jm-1'));

    expect($result->outcome)->toBe(MailWriteOutcome::Applied)
        ->and($result->driver)->toBe(MailDriver::Jmap)
        ->and($result->providerEvidence)->toBe([
            'mailbox_ids' => ['mb-inbox'],
            'trash_mailbox_id' => 'mb-trash',
            'inbox_mailbox_id' => 'mb-inbox',
            'email_state' => 'synthetic-email-state-41',
        ])
        ->and($jmap->writes)->toBe([[
            'accountId' => 'jmap-restore-a',
            'ifInState' => 'synthetic-email-state-40',
            'update' => ['jm-1' => ['mailboxIds/mb-trash' => null, 'mailboxIds/mb-inbox' => true]],
        ]])
        ->and($jmap->emails['jmap-restore-a']['jm-2'])->toBe(['mb-trash' => true])
        ->and($jmap->methods)->toBe(['Mailbox/get', 'Email/get', 'Email/set', 'Mailbox/get', 'Email/get']);
});

it('returns an idempotent retry as already applied without a second provider write', function (): void {
    $gmail = new TrashRestoreGmailProvider;
    $gmail->messages['synthetic-restore-access-a'] = ['gm-1' => ['labels' => ['TRASH'], 'prior' => ['INBOX']]];
    $jmap = new TrashRestoreJmapProvider;
    $jmap->emails['jmap-restore-a'] = ['jm-1' => ['mb-trash' => true]];
    $gmailHandler = $gmail->handler();
    $jmapHandler = $jmap->handler();
    Http::fake(fn (Request $request) => str_contains($request->url(), 'fastmail') ? $jmapHandler($request) : $gmailHandler($request));
    $gmailAccount = restoreGmailAccount('owner-a', 'restore-a@invented.test', 'synthetic-restore-access-a');
    $jmapAccount = restoreJmapAccount('owner-a', 'jmap-restore-a');
    $service = app(MailWriteService::class);

    expect($service->restoreFromTrash(restoreTarget($gmailAccount, 'gm-1'))->outcome)->toBe(MailWriteOutcome::Applied)
        ->and($service->restoreFromTrash(restoreTarget($jmapAccount, 'jm-1'))->outcome)->toBe(MailWriteOutcome::Applied);

    $gmailRetry = $service->restoreFromTrash(restoreTarget($gmailAccount, 'gm-1'));
    $jmapRetry = $service->restoreFromTrash(restoreTarget($jmapAccount, 'jm-1'));

    expect($gmailRetry->outcome)->toBe(MailWriteOutcome::AlreadyApplied)
        ->and($gmailRetry->providerEvidence['label_ids'])->toBe(['INBOX'])
        ->and($jmapRetry->outcome)->toBe(MailWriteOutcome::AlreadyApplied)
        ->and($jmapRetry->providerEvidence['mailbox_ids'])->toBe(['mb-inbox'])
        ->and($gmail->writes)->toHaveCount(1)
        ->and($jmap->writes)->toHaveCount(1);
});

it('treats a message trashed again after its restore as a new restore', function (): void {
    $gmail = new TrashRestoreGmailProvider;
    $gmail->messages['synthetic-restore-access-a'] = ['gm-1' => ['labels' => ['TRASH'], 'prior' => ['INBOX']]];
    Http::fake($gmail->handler());
    $account = restoreGmailAccount('owner-a', 'restore-a@invented.test', 'synthetic-restore-access-a');
    $service = app(MailWriteService::class);

    $service->restoreFromTrash(restoreTarget($account, 'gm-1'));
    $gmail->messages['synthetic-restore-access-a']['gm-1']['labels'] = ['TRASH'];

    expect($service->restoreFromTrash(restoreTarget($account, 'gm-1'))->outcome)->toBe(MailWriteOutcome::Applied)
        ->and($gmail->writes)->toHaveCount(2);
});

it('rejects owner and account mismatches before any HTTP call', function (int $accountOffset, ?string $ownerType, ?string $ownerId): void {
    Http::fake();
    $account = restoreGmailAccount('owner-a', 'restore-a@invented.test', 'synthetic-restore-access-a');
    $ownerless = MailAccount::query()->create([
        'driver' => MailDriver::Jmap,
        'provider_account_id' => 'jmap-ownerless',
    ]);
    expect($ownerless->id)->toBe($account->id + 1);

    expect(fn () => app(MailWriteService::class)->restoreFromTrash(
        new MailWriteTarget($account->id + $accountOffset, $ownerType, $ownerId, 'gm-1'),
    ))->toThrow(AccountResourceMismatch::class);
    Http::assertNothingSent();
})->with([
    'another owner ID' => [0, 'synthetic-workspace', 'owner-b'],
    'another owner type' => [0, 'synthetic-team', 'owner-a'],
    'an ownerless tuple for an owned account' => [0, null, null],
    'an owned tuple for an ownerless account' => [1, 'synthetic-workspace', 'owner-a'],
    'an unknown account' => [50, 'synthetic-workspace', 'owner-a'],
]);

it('restores only the addressed account when two accounts share a provider message ID', function (): void {
    $gmail = new TrashRestoreGmailProvider;
    $gmail->messages = [
        'synthetic-restore-access-a' => ['shared-id' => ['labels' => ['TRASH'], 'prior' => ['INBOX']]],
        'synthetic-restore-access-b' => ['shared-id' => ['labels' => ['TRASH'], 'prior' => ['Label_9']]],
    ];
    $jmap = new TrashRestoreJmapProvider;
    $jmap->emails = [
        'jmap-restore-a' => ['shared-id' => ['mb-trash' => true]],
        'jmap-restore-b' => ['shared-id' => ['mb-trash' => true]],
    ];
    $gmailHandler = $gmail->handler();
    $jmapHandler = $jmap->handler();
    Http::fake(fn (Request $request) => str_contains($request->url(), 'fastmail') ? $jmapHandler($request) : $gmailHandler($request));
    $gmailA = restoreGmailAccount('owner-a', 'restore-a@invented.test', 'synthetic-restore-access-a');
    $gmailB = restoreGmailAccount('owner-b', 'restore-b@invented.test', 'synthetic-restore-access-b');
    $jmapA = restoreJmapAccount('owner-a', 'jmap-restore-a');
    $jmapB = restoreJmapAccount('owner-b', 'jmap-restore-b');
    $service = app(MailWriteService::class);

    $service->restoreFromTrash(restoreTarget($gmailA, 'shared-id'));
    $service->restoreFromTrash(restoreTarget($jmapA, 'shared-id'));

    expect($gmail->messages['synthetic-restore-access-b']['shared-id']['labels'])->toBe(['TRASH'])
        ->and($jmap->emails['jmap-restore-b']['shared-id'])->toBe(['mb-trash' => true])
        ->and(fn () => $service->restoreFromTrash(new MailWriteTarget($gmailB->id, 'synthetic-workspace', 'owner-a', 'shared-id')))
        ->toThrow(AccountResourceMismatch::class);

    // Account A's recorded intent must not make account B's untouched message look restored.
    $gmail->messages['synthetic-restore-access-b']['shared-id']['labels'] = ['Label_9'];
    $jmap->emails['jmap-restore-b']['shared-id'] = ['mb-archive' => true];

    expect(restoreFailure(restoreTarget($gmailB, 'shared-id'))->safeCode)->toBe(MailWriteCode::NotInTrash)
        ->and(restoreFailure(restoreTarget($jmapB, 'shared-id'))->safeCode)->toBe(MailWriteCode::NotInTrash)
        ->and($gmail->writes)->toBe(['synthetic-restore-access-a:shared-id'])
        ->and($jmap->writes)->toHaveCount(1)
        ->and($jmap->writes[0]['accountId'])->toBe('jmap-restore-a');
});

it('fails explicitly without a write for a missing message or one that was never in Trash', function (): void {
    $gmail = new TrashRestoreGmailProvider;
    $gmail->messages['synthetic-restore-access-a'] = ['gm-inbox' => ['labels' => ['INBOX'], 'prior' => ['INBOX']]];
    $jmap = new TrashRestoreJmapProvider;
    $jmap->emails['jmap-restore-a'] = ['jm-archive' => ['mb-archive' => true]];
    $gmailHandler = $gmail->handler();
    $jmapHandler = $jmap->handler();
    Http::fake(fn (Request $request) => str_contains($request->url(), 'fastmail') ? $jmapHandler($request) : $gmailHandler($request));
    $gmailAccount = restoreGmailAccount('owner-a', 'restore-a@invented.test', 'synthetic-restore-access-a');
    $jmapAccount = restoreJmapAccount('owner-a', 'jmap-restore-a');

    $failures = [
        restoreFailure(restoreTarget($gmailAccount, 'gm-missing')),
        restoreFailure(restoreTarget($jmapAccount, 'jm-missing')),
        restoreFailure(restoreTarget($gmailAccount, 'gm-inbox')),
        restoreFailure(restoreTarget($jmapAccount, 'jm-archive')),
    ];

    expect(array_map(fn (MailWriteFailure $failure): MailWriteCode => $failure->safeCode, $failures))->toBe([
        MailWriteCode::MessageNotFound,
        MailWriteCode::MessageNotFound,
        MailWriteCode::NotInTrash,
        MailWriteCode::NotInTrash,
    ])
        ->and(array_map(fn (MailWriteFailure $failure): bool => $failure->writeSent, $failures))->toBe([false, false, false, false])
        ->and($gmail->writes)->toBe([])
        ->and($jmap->writes)->toBe([]);
});

it('refuses ambiguous JMAP mailbox roles without writing', function (array $mailboxes): void {
    $jmap = new TrashRestoreJmapProvider;
    /** @var list<array{id: string, name: string, role: string|null}> $mailboxes */
    $jmap->mailboxes = $mailboxes;
    $jmap->emails['jmap-restore-a'] = ['jm-1' => ['mb-trash' => true]];
    Http::fake($jmap->handler());
    $account = restoreJmapAccount('owner-a', 'jmap-restore-a');

    expect(restoreFailure(restoreTarget($account, 'jm-1'))->safeCode)->toBe(MailWriteCode::AmbiguousMailboxRole)
        ->and(cache()->has(restoreIntentKey($account, 'jm-1')))->toBeFalse()
        ->and($jmap->writes)->toBe([])
        ->and($jmap->emails['jmap-restore-a']['jm-1'])->toBe(['mb-trash' => true]);
})->with([
    'two Trash roles' => [[
        ['id' => 'mb-inbox', 'name' => 'Inbox', 'role' => 'inbox'],
        ['id' => 'mb-trash', 'name' => 'Trash', 'role' => 'trash'],
        ['id' => 'mb-trash-2', 'name' => 'Deleted', 'role' => 'trash'],
    ]],
    'no Inbox role' => [[
        ['id' => 'mb-trash', 'name' => 'Trash', 'role' => 'trash'],
        ['id' => 'mb-archive', 'name' => 'Archive', 'role' => 'archive'],
    ]],
    'no Trash role' => [[
        ['id' => 'mb-inbox', 'name' => 'Inbox', 'role' => 'inbox'],
    ]],
]);

it('refuses a JMAP email that is in Trash and another mailbox without writing', function (): void {
    $jmap = new TrashRestoreJmapProvider;
    $jmap->emails['jmap-restore-a'] = ['jm-1' => ['mb-trash' => true, 'mb-projects' => true]];
    Http::fake($jmap->handler());
    $account = restoreJmapAccount('owner-a', 'jmap-restore-a');
    // An intent left by an earlier restore cycle must not survive a refusal.
    cache()->put(restoreIntentKey($account, 'jm-1'), true, 3600);

    $failure = restoreFailure(restoreTarget($account, 'jm-1'));

    expect($failure->safeCode)->toBe(MailWriteCode::UnsupportedState)
        ->and($failure->writeSent)->toBeFalse()
        ->and(cache()->has(restoreIntentKey($account, 'jm-1')))->toBeFalse()
        ->and($jmap->writes)->toBe([]);

    // Removed from Projects and Trash by someone else, it now looks restored, but this package did not restore it.
    $jmap->emails['jmap-restore-a']['jm-1'] = ['mb-inbox' => true];

    expect(restoreFailure(restoreTarget($account, 'jm-1'))->safeCode)->toBe(MailWriteCode::NotInTrash);
});

it('sends a failed provider write exactly once with transport retries off', function (): void {
    $gmail = new TrashRestoreGmailProvider;
    $gmail->messages['synthetic-restore-access-a'] = ['gm-1' => ['labels' => ['TRASH'], 'prior' => ['INBOX']]];
    $gmail->untrashStatus = 503;
    $jmap = new TrashRestoreJmapProvider;
    $jmap->emails['jmap-restore-a'] = ['jm-1' => ['mb-trash' => true]];
    $jmap->setStatus = 503;
    $gmailHandler = $gmail->handler();
    $jmapHandler = $jmap->handler();
    Http::fake(fn (Request $request) => str_contains($request->url(), 'fastmail') ? $jmapHandler($request) : $gmailHandler($request));
    $gmailAccount = restoreGmailAccount('owner-a', 'restore-a@invented.test', 'synthetic-restore-access-a');
    $jmapAccount = restoreJmapAccount('owner-a', 'jmap-restore-a');

    $gmailFailure = restoreFailure(restoreTarget($gmailAccount, 'gm-1'));
    $jmapFailure = restoreFailure(restoreTarget($jmapAccount, 'jm-1'));

    expect($gmailFailure->safeCode)->toBe(MailWriteCode::ProviderFailed)
        ->and($gmailFailure->providerCode)->toBe(MailImportCode::ProviderUnavailable)
        ->and($gmailFailure->writeSent)->toBeTrue()
        ->and($jmapFailure->safeCode)->toBe(MailWriteCode::ProviderFailed)
        ->and($jmapFailure->providerCode)->toBe(MailImportCode::ProviderUnavailable)
        ->and($jmapFailure->writeSent)->toBeTrue()
        ->and($gmail->writes)->toHaveCount(1)
        ->and($jmap->writes)->toHaveCount(1)
        ->and(cache()->get(restoreIntentKey($gmailAccount, 'gm-1')))->toBeTrue()
        ->and(cache()->get(restoreIntentKey($jmapAccount, 'jm-1')))->toBeTrue()
        ->and($gmail->messages['synthetic-restore-access-a']['gm-1']['labels'])->toBe(['TRASH'])
        ->and($jmap->emails['jmap-restore-a']['jm-1'])->toBe(['mb-trash' => true]);
});

it('refreshes a rejected Gmail token, never re-sends the write, and reports it as not applied', function (): void {
    $gmail = new TrashRestoreGmailProvider;
    $gmail->messages['synthetic-restore-access-a'] = ['gm-1' => ['labels' => ['TRASH'], 'prior' => ['INBOX']]];
    $gmail->rejectWriteToken = true;
    Http::fake($gmail->handler());
    $account = restoreGmailAccount('owner-a', 'restore-a@invented.test', 'synthetic-restore-access-a');

    $failure = restoreFailure(restoreTarget($account, 'gm-1'));

    expect($failure->safeCode)->toBe(MailWriteCode::ProviderFailed)
        ->and($failure->providerCode)->toBe(MailImportCode::AuthenticationFailed)
        ->and($failure->writeSent)->toBeFalse()
        ->and(cache()->has(restoreIntentKey($account, 'gm-1')))->toBeFalse()
        ->and($gmail->writes)->toBe(['synthetic-restore-access-a:gm-1']);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://oauth2.googleapis.com/token');
});

it('fails explicitly when JMAP rejects the update or the observed state changed', function (string $case, ?MailImportCode $providerCode, MailWriteCode $code): void {
    $jmap = new TrashRestoreJmapProvider;
    $jmap->emails['jmap-restore-a'] = ['jm-1' => ['mb-trash' => true]];
    match ($case) {
        'stateMismatch' => $jmap->setError = ['type' => 'stateMismatch'],
        'forbidden' => $jmap->notUpdated = ['jm-1' => ['type' => 'forbidden']],
        'notFound' => $jmap->notUpdated = ['jm-1' => ['type' => 'notFound']],
        default => throw new InvalidArgumentException('Unknown synthetic JMAP failure.'),
    };
    Http::fake($jmap->handler());
    $account = restoreJmapAccount('owner-a', 'jmap-restore-a');

    $failure = restoreFailure(restoreTarget($account, 'jm-1'));

    expect($failure->safeCode)->toBe($code)
        ->and($failure->providerCode)->toBe($providerCode)
        ->and($failure->writeSent)->toBeFalse()
        ->and(cache()->has(restoreIntentKey($account, 'jm-1')))->toBeFalse()
        ->and($jmap->writes)->toHaveCount(1)
        ->and($jmap->emails['jmap-restore-a']['jm-1'])->toBe(['mb-trash' => true]);
})->with([
    'ifInState mismatch' => ['stateMismatch', MailImportCode::StateMismatch, MailWriteCode::ProviderFailed],
    'forbidden update' => ['forbidden', MailImportCode::PermissionDenied, MailWriteCode::ProviderFailed],
    'destroyed before update' => ['notFound', MailImportCode::MessageUnavailable, MailWriteCode::MessageNotFound],
]);

it('reports an unconfirmed write when the provider re-read still shows Trash', function (): void {
    $gmail = new TrashRestoreGmailProvider;
    $gmail->messages['synthetic-restore-access-a'] = ['gm-1' => ['labels' => ['TRASH'], 'prior' => ['INBOX']]];
    $gmail->untrashIgnored = true;
    $jmap = new TrashRestoreJmapProvider;
    $jmap->emails['jmap-restore-a'] = ['jm-1' => ['mb-trash' => true]];
    $jmap->setIgnored = true;
    $gmailHandler = $gmail->handler();
    $jmapHandler = $jmap->handler();
    Http::fake(fn (Request $request) => str_contains($request->url(), 'fastmail') ? $jmapHandler($request) : $gmailHandler($request));
    $gmailAccount = restoreGmailAccount('owner-a', 'restore-a@invented.test', 'synthetic-restore-access-a');
    $jmapAccount = restoreJmapAccount('owner-a', 'jmap-restore-a');

    $gmailFailure = restoreFailure(restoreTarget($gmailAccount, 'gm-1'));
    $jmapFailure = restoreFailure(restoreTarget($jmapAccount, 'jm-1'));

    expect([$gmailFailure->safeCode, $jmapFailure->safeCode])->toBe([MailWriteCode::Unconfirmed, MailWriteCode::Unconfirmed])
        ->and([$gmailFailure->writeSent, $jmapFailure->writeSent])->toBe([true, true])
        ->and($gmail->writes)->toHaveCount(1)
        ->and($jmap->writes)->toHaveCount(1);
});

it('reports an unconfirmed write when the confirming re-read fails', function (): void {
    $gmail = new TrashRestoreGmailProvider;
    $gmail->messages['synthetic-restore-access-a'] = ['gm-1' => ['labels' => ['TRASH'], 'prior' => ['INBOX']]];
    $reads = 0;
    $handler = $gmail->handler();
    Http::fake(function (Request $request) use ($handler, &$reads) {
        if ($request->method() === 'GET' && ++$reads === 2) {
            return Http::response(['error' => 'synthetic provider failure'], 500);
        }

        return $handler($request);
    });
    $account = restoreGmailAccount('owner-a', 'restore-a@invented.test', 'synthetic-restore-access-a');

    $failure = restoreFailure(restoreTarget($account, 'gm-1'));

    expect($failure->safeCode)->toBe(MailWriteCode::Unconfirmed)
        ->and($failure->providerCode)->toBe(MailImportCode::ProviderUnavailable)
        ->and($failure->writeSent)->toBeTrue()
        ->and($gmail->writes)->toHaveCount(1);

    // The provider did apply the untrash; the idempotent retry confirms it without writing again.
    expect(app(MailWriteService::class)->restoreFromTrash(restoreTarget($account, 'gm-1'))->outcome)
        ->toBe(MailWriteOutcome::AlreadyApplied)
        ->and($gmail->writes)->toHaveCount(1);
});

it('keeps provider IDs and credentials out of write failure messages', function (): void {
    $gmail = new TrashRestoreGmailProvider;
    $gmail->messages['synthetic-restore-access-a'] = ['gm-secret-id' => ['labels' => ['TRASH'], 'prior' => ['INBOX']]];
    $gmail->untrashStatus = 500;
    Http::fake($gmail->handler());
    $account = restoreGmailAccount('owner-a', 'restore-a@invented.test', 'synthetic-restore-access-a');

    $failure = restoreFailure(restoreTarget($account, 'gm-secret-id'));

    expect($failure->getMessage())->not->toContain('gm-secret-id')
        ->and($failure->getMessage())->not->toContain('synthetic-restore-access-a')
        ->and($failure->getMessage())->not->toContain('restore-a@invented.test');
});

it('does not report success when the JMAP re-read shows the email outside both Trash and Inbox', function (): void {
    $jmap = new TrashRestoreJmapProvider;
    $jmap->emails['jmap-restore-a'] = ['jm-1' => ['mb-trash' => true]];
    $jmap->setMovesTo = 'mb-archive';
    Http::fake($jmap->handler());
    $account = restoreJmapAccount('owner-a', 'jmap-restore-a');

    $failure = restoreFailure(restoreTarget($account, 'jm-1'));

    expect($failure->safeCode)->toBe(MailWriteCode::Unconfirmed)
        ->and($failure->writeSent)->toBeTrue()
        ->and($jmap->writes)->toHaveCount(1);

    // The retry sees the recorded intent, but the email is not at the Inbox destination.
    expect(restoreFailure(restoreTarget($account, 'jm-1'))->safeCode)->toBe(MailWriteCode::NotInTrash)
        ->and($jmap->writes)->toHaveCount(1);
});

it('does not return an already applied JMAP restore once the email has left Inbox', function (): void {
    $jmap = new TrashRestoreJmapProvider;
    $jmap->emails['jmap-restore-a'] = ['jm-1' => ['mb-trash' => true]];
    Http::fake($jmap->handler());
    $account = restoreJmapAccount('owner-a', 'jmap-restore-a');

    expect(app(MailWriteService::class)->restoreFromTrash(restoreTarget($account, 'jm-1'))->outcome)->toBe(MailWriteOutcome::Applied);

    $jmap->emails['jmap-restore-a']['jm-1'] = ['mb-archive' => true];

    expect(restoreFailure(restoreTarget($account, 'jm-1'))->safeCode)->toBe(MailWriteCode::NotInTrash)
        ->and($jmap->writes)->toHaveCount(1);
});

it('records no intent for a Gmail write the provider rejected, so a later external restore is not claimed', function (int $status, MailImportCode $providerCode): void {
    $gmail = new TrashRestoreGmailProvider;
    $gmail->messages['synthetic-restore-access-a'] = ['gm-1' => ['labels' => ['TRASH'], 'prior' => ['INBOX']]];
    $gmail->untrashStatus = $status;
    Http::fake($gmail->handler());
    $account = restoreGmailAccount('owner-a', 'restore-a@invented.test', 'synthetic-restore-access-a');

    $failure = restoreFailure(restoreTarget($account, 'gm-1'));

    expect($failure->safeCode)->toBe(MailWriteCode::ProviderFailed)
        ->and($failure->providerCode)->toBe($providerCode)
        ->and($failure->writeSent)->toBeFalse()
        ->and(cache()->has(restoreIntentKey($account, 'gm-1')))->toBeFalse();

    $gmail->messages['synthetic-restore-access-a']['gm-1']['labels'] = ['INBOX'];

    expect(restoreFailure(restoreTarget($account, 'gm-1'))->safeCode)->toBe(MailWriteCode::NotInTrash)
        ->and($gmail->writes)->toHaveCount(1);
})->with([
    'bad request' => [400, MailImportCode::StateMismatch],
    'forbidden' => [403, MailImportCode::PermissionDenied],
    'rate limited' => [429, MailImportCode::RateLimited],
    'conflict' => [409, MailImportCode::UnexpectedFailure],
]);

it('does not claim an external JMAP restore after an ifInState rejection', function (): void {
    $jmap = new TrashRestoreJmapProvider;
    $jmap->emails['jmap-restore-a'] = ['jm-1' => ['mb-trash' => true]];
    $jmap->setError = ['type' => 'stateMismatch'];
    Http::fake($jmap->handler());
    $account = restoreJmapAccount('owner-a', 'jmap-restore-a');

    expect(restoreFailure(restoreTarget($account, 'jm-1'))->writeSent)->toBeFalse();

    $jmap->emails['jmap-restore-a']['jm-1'] = ['mb-inbox' => true];

    expect(restoreFailure(restoreTarget($account, 'jm-1'))->safeCode)->toBe(MailWriteCode::NotInTrash)
        ->and($jmap->writes)->toHaveCount(1);
});

it('clears the intent of an earlier restore cycle when a new attempt definitely fails', function (): void {
    $gmail = new TrashRestoreGmailProvider;
    $gmail->messages['synthetic-restore-access-a'] = ['gm-1' => ['labels' => ['TRASH'], 'prior' => ['INBOX']]];
    Http::fake($gmail->handler());
    $account = restoreGmailAccount('owner-a', 'restore-a@invented.test', 'synthetic-restore-access-a');
    $service = app(MailWriteService::class);

    expect($service->restoreFromTrash(restoreTarget($account, 'gm-1'))->outcome)->toBe(MailWriteOutcome::Applied)
        ->and(cache()->get(restoreIntentKey($account, 'gm-1')))->toBeTrue();

    $gmail->messages['synthetic-restore-access-a']['gm-1']['labels'] = ['TRASH'];
    $gmail->untrashStatus = 403;

    expect(restoreFailure(restoreTarget($account, 'gm-1'))->writeSent)->toBeFalse()
        ->and(cache()->has(restoreIntentKey($account, 'gm-1')))->toBeFalse();

    $gmail->messages['synthetic-restore-access-a']['gm-1']['labels'] = ['INBOX'];

    expect(restoreFailure(restoreTarget($account, 'gm-1'))->safeCode)->toBe(MailWriteCode::NotInTrash)
        ->and($gmail->writes)->toHaveCount(2);
});

it('serializes writes to one target and fails a concurrent write as busy before any request', function (): void {
    $gmail = new TrashRestoreGmailProvider;
    $gmail->messages['synthetic-restore-access-a'] = [
        'gm-1' => ['labels' => ['TRASH'], 'prior' => ['INBOX']],
        'gm-2' => ['labels' => ['TRASH'], 'prior' => ['INBOX']],
    ];
    Http::fake($gmail->handler());
    $account = restoreGmailAccount('owner-a', 'restore-a@invented.test', 'synthetic-restore-access-a');
    $service = app(MailWriteService::class);
    $concurrent = null;
    $requestsBefore = 0;
    $requestsAfter = 0;
    $otherTarget = null;
    $gmail->onWrite = function () use ($service, $account, &$concurrent, &$requestsBefore, &$requestsAfter, &$otherTarget, $gmail): void {
        if ($concurrent !== null) {
            return;
        }

        $requestsBefore = count(Http::recorded());

        try {
            $service->restoreFromTrash(restoreTarget($account, 'gm-1'));
        } catch (MailWriteFailure $failure) {
            $concurrent = $failure;
        }

        $requestsAfter = count(Http::recorded());
        $gmail->onWrite = null;
        $otherTarget = $service->restoreFromTrash(restoreTarget($account, 'gm-2'));
    };

    expect($service->restoreFromTrash(restoreTarget($account, 'gm-1'))->outcome)->toBe(MailWriteOutcome::Applied);
    assert($concurrent instanceof MailWriteFailure);

    expect($concurrent->safeCode)->toBe(MailWriteCode::TargetBusy)
        ->and($concurrent->writeSent)->toBeFalse()
        ->and($requestsAfter)->toBe($requestsBefore)
        ->and($otherTarget?->outcome)->toBe(MailWriteOutcome::Applied)
        ->and($gmail->writes)->toBe(['synthetic-restore-access-a:gm-1', 'synthetic-restore-access-a:gm-2'])
        // The lock is released afterwards, so the retry proceeds and confirms without writing.
        ->and($service->restoreFromTrash(restoreTarget($account, 'gm-1'))->outcome)->toBe(MailWriteOutcome::AlreadyApplied)
        ->and($gmail->writes)->toHaveCount(2);
});

it('reports any confirming-read exception after a sent write as unconfirmed', function (string $case): void {
    $driver = new TrashRestoreConfirmationDriver(fn (MailAccount $account, string $providerMessageId): TrashState => match ($case) {
        'mismatched state' => new TrashState($account->id, MailDriver::Gmail, 'another-message', false, true, []),
        'invalid argument' => throw new InvalidArgumentException('synthetic invalid state'),
        'budget exhausted' => throw new SyncBudgetExhausted('http_requests', [
            'http_requests' => 1, 'max_http_requests' => 1, 'listed_ids' => 0, 'max_listed_ids' => 1,
            'fetched_messages' => 0, 'max_fetched_messages' => 1, 'downloaded_bytes' => 0, 'max_downloaded_bytes' => 1,
            'elapsed_milliseconds' => 0, 'max_elapsed_milliseconds' => 1,
        ]),
        default => throw new RuntimeException('synthetic unexpected failure'),
    });
    $registry = new MailDriverRegistry;
    $registry->register(MailDriver::Gmail, $driver);
    app()->instance(MailDriverRegistry::class, $registry);
    app()->forgetInstance(MailWriteService::class);
    Http::fake();
    $account = restoreGmailAccount('owner-a', 'restore-a@invented.test', 'synthetic-restore-access-a');

    $failure = restoreFailure(restoreTarget($account, 'gm-1'));

    expect($failure->safeCode)->toBe(MailWriteCode::Unconfirmed)
        ->and($failure->writeSent)->toBeTrue()
        ->and($failure->getPrevious())->not->toBeNull()
        ->and($driver->writes)->toBe(1)
        ->and(cache()->get(restoreIntentKey($account, 'gm-1')))->toBeTrue();
    Http::assertNothingSent();
})->with(['mismatched state', 'invalid argument', 'budget exhausted', 'runtime']);
