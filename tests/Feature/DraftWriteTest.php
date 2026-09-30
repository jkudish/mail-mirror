<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Jkudish\MailMirror\Credentials\ApiTokenCredential;
use Jkudish\MailMirror\Credentials\MailAccountConnection;
use Jkudish\MailMirror\Credentials\OAuthTokenSetCredential;
use Jkudish\MailMirror\Enums\MailDriver;
use Jkudish\MailMirror\Enums\MailWriteCode;
use Jkudish\MailMirror\Enums\MailWriteOutcome;
use Jkudish\MailMirror\Exceptions\AccountResourceMismatch;
use Jkudish\MailMirror\Exceptions\MailWriteFailure;
use Jkudish\MailMirror\Gmail\GmailOAuth;
use Jkudish\MailMirror\Models\MailAccount;
use Jkudish\MailMirror\Write\DraftContent;
use Jkudish\MailMirror\Write\DraftTarget;
use Jkudish\MailMirror\Write\MailAccountTarget;
use Jkudish\MailMirror\Write\MailWriteService;
use Jkudish\MailMirror\Write\MailWriteTarget;

/** Synthetic Gmail drafts; every create or update issues a new message ID, as Gmail does. */
final class DraftGmailProvider
{
    /** @var array<string, array{message: string, thread: string, raw: string}> */
    public array $drafts = [];

    /** @var list<string> */
    public array $writes = [];

    /** @var list<array<string, mixed>> */
    public array $bodies = [];

    public int $next = 1;

    /** Apply the next write, then answer with this status, like a timeout after the provider applied it. */
    public ?int $statusAfterApply = null;

    /** @var (Closure(): void)|null Runs while a create is in flight. */
    public ?Closure $onCreate = null;

    /** @var (Closure(): void)|null Runs after an update applies, for example an external edit. */
    public ?Closure $afterUpdate = null;

    /** @return Closure(Request): mixed */
    public function handler(): Closure
    {
        return function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            if ($path === '/gmail/v1/users/me/drafts' && $request->method() === 'GET') {
                $q = $query['q'] ?? null;
                $matches = array_filter($this->drafts, fn (array $draft): bool => ! is_string($q)
                    || 'rfc822msgid:'.DraftContent::messageIdOf($draft['raw']) === $q);

                return Http::response(['drafts' => array_map(
                    fn (string $id): array => ['id' => $id, 'message' => ['id' => $this->drafts[$id]['message'], 'threadId' => $this->drafts[$id]['thread']]],
                    array_keys($matches),
                )]);
            }

            if ($path === '/gmail/v1/users/me/drafts' && $request->method() === 'POST') {
                $this->writes[] = 'create';

                if ($this->onCreate !== null) {
                    ($this->onCreate)();
                }
                /** @var array{message: array{raw: string, threadId?: string}} $body */
                $body = $request->data();
                $this->bodies[] = $body;
                $id = 'd-'.$this->next;
                $this->drafts[$id] = ['message' => 'm-'.$this->next++, 'thread' => $body['message']['threadId'] ?? 't-new', 'raw' => draftDecode($body['message']['raw'])];

                return $this->afterWrite(['id' => $id, 'message' => ['id' => $this->drafts[$id]['message']]]);
            }

            if (preg_match('#^/gmail/v1/users/me/drafts/([^/]+)$#', $path, $matches) !== 1) {
                return Http::response(['error' => 'synthetic unexpected path'], 418);
            }

            $id = rawurldecode($matches[1]);

            if (! isset($this->drafts[$id])) {
                return Http::response(['error' => 'synthetic not found'], 404);
            }

            if ($request->method() === 'GET') {
                expect($query)->toBe(['format' => 'raw']);

                return Http::response(['id' => $id, 'message' => [
                    'id' => $this->drafts[$id]['message'],
                    'threadId' => $this->drafts[$id]['thread'],
                    'labelIds' => ['DRAFT'],
                    'raw' => rtrim(strtr(base64_encode($this->drafts[$id]['raw']), '+/', '-_'), '='),
                ]]);
            }

            if ($request->method() === 'PUT') {
                $this->writes[] = 'update:'.$id;
                /** @var array{id: string, message: array{raw: string, threadId: string}} $body */
                $body = $request->data();
                $this->bodies[] = $body;
                $this->drafts[$id]['raw'] = draftDecode($body['message']['raw']);
                $this->drafts[$id]['message'] = 'm-'.$this->next++;

                if ($this->afterUpdate !== null) {
                    ($this->afterUpdate)();
                }

                return $this->afterWrite(['id' => $id, 'message' => ['id' => $this->drafts[$id]['message']]]);
            }

            expect($request->method())->toBe('DELETE');
            $this->writes[] = 'delete:'.$id;
            unset($this->drafts[$id]);

            return $this->statusAfterApply !== null ? $this->afterWrite([]) : Http::response('', 204);
        };
    }

    /** @param array<string, mixed> $body */
    private function afterWrite(array $body): mixed
    {
        $status = $this->statusAfterApply;
        $this->statusAfterApply = null;

        return $status === null ? Http::response($body) : Http::response(['error' => 'synthetic failure'], $status);
    }
}

/** Synthetic Fastmail JMAP drafts with blobs, Email/import, and ifInState-guarded destroys. */
final class DraftJmapProvider
{
    /** @var array<string, array{blob: string, mailboxIds: array<string, true>, keywords: array<string, true>}> */
    public array $emails = [];

    /** @var array<string, string> */
    public array $blobs = [];

    public int $state = 1;

    public int $next = 1;

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    public array $writes = [];

    public int $uploads = 0;

    /** Another client changes the account while the blob uploads, after the pre-write read. */
    public bool $changeDuringUpload = false;

    /** Answer the next destroy with this status without applying it, like a crash before it lands. */
    public ?int $destroyStatus = null;

    /** Apply the next import, then answer with this status. */
    public ?int $importStatusAfterApply = null;

    /** @return Closure(Request): mixed */
    public function handler(): Closure
    {
        return function (Request $request) {
            $url = $request->url();

            if ($url === 'https://api.fastmail.com/jmap/session') {
                return Http::response([
                    'capabilities' => ['urn:ietf:params:jmap:core' => [], 'urn:ietf:params:jmap:mail' => [], 'urn:ietf:params:jmap:submission' => []],
                    'accounts' => ['jmap-draft-a' => ['accountCapabilities' => ['urn:ietf:params:jmap:mail' => [], 'urn:ietf:params:jmap:submission' => []]]],
                    'apiUrl' => 'https://api.fastmail.com/jmap/api/',
                    'downloadUrl' => 'https://www.fastmailusercontent.com/jmap/download/{accountId}/{blobId}/{name}?type={type}',
                    'uploadUrl' => 'https://api.fastmail.com/jmap/upload/{accountId}/',
                    'state' => 'synthetic-draft-session',
                ]);
            }

            if ($url === 'https://api.fastmail.com/jmap/upload/jmap-draft-a/') {
                $this->uploads++;

                if ($this->changeDuringUpload) {
                    $this->state++;
                }
                $blobId = 'blob-'.$this->next++;
                $this->blobs[$blobId] = $request->body();

                return Http::response(['accountId' => 'jmap-draft-a', 'blobId' => $blobId, 'type' => 'message/rfc822', 'size' => strlen($request->body())]);
            }

            if (str_starts_with($url, 'https://www.fastmailusercontent.com/jmap/download/jmap-draft-a/')) {
                $blobId = rawurldecode(explode('/', substr($url, strlen('https://www.fastmailusercontent.com/jmap/download/jmap-draft-a/')))[0]);

                return isset($this->blobs[$blobId]) ? Http::response($this->blobs[$blobId]) : Http::response('', 404);
            }

            $calls = $request->data()['methodCalls'] ?? null;
            $call = is_array($calls) ? ($calls[0] ?? null) : null;
            assert(is_array($call) && is_string($call[0]) && is_array($call[1]) && is_string($call[2]));
            [$method, $arguments, $callId] = $call;
            /** @var array<string, mixed> $arguments */
            $state = 'email-state-'.$this->state;
            $reply = fn (string $name, array $result): mixed => Http::response([
                'methodResponses' => [[$name, ['accountId' => 'jmap-draft-a'] + $result, $callId]],
                'sessionState' => 'synthetic-draft-session',
            ]);
            $error = fn (string $type): mixed => Http::response(['methodResponses' => [['error', ['type' => $type], $callId]], 'sessionState' => 'synthetic-draft-session']);

            return match ($method) {
                'Mailbox/get' => $reply('Mailbox/get', ['state' => 'mailbox-state', 'notFound' => [], 'list' => [
                    ['id' => 'mb-inbox', 'name' => 'Inbox', 'role' => 'inbox'],
                    ['id' => 'mb-drafts', 'name' => 'Drafts', 'role' => 'drafts'],
                ]]),
                'Email/get' => (function () use ($arguments, $reply, $state): mixed {
                    /** @var list<string> $ids */
                    $ids = $arguments['ids'];
                    $id = $ids[0];

                    return isset($this->emails[$id])
                        ? $reply('Email/get', ['state' => $state, 'notFound' => [], 'list' => [[
                            'id' => $id, 'blobId' => $this->emails[$id]['blob'], 'threadId' => 'thread-'.$id,
                            'mailboxIds' => $this->emails[$id]['mailboxIds'], 'keywords' => $this->emails[$id]['keywords'],
                        ]]])
                        : $reply('Email/get', ['state' => $state, 'notFound' => [$id], 'list' => []]);
                })(),
                'Email/query' => (function () use ($arguments, $reply): mixed {
                    /** @var array{conditions: array{0: array{inMailbox: string}, 1: array{header: array{0: string, 1: string}}}} $filter */
                    $filter = $arguments['filter'];
                    $header = $filter['conditions'][1]['header'][1];

                    return $reply('Email/query', ['queryState' => 'q', 'position' => 0, 'ids' => array_keys(array_filter(
                        $this->emails,
                        fn (array $email): bool => isset($email['mailboxIds'][$filter['conditions'][0]['inMailbox']])
                            && '<'.DraftContent::messageIdOf($this->blobs[$email['blob']]).'>' === $header,
                    ))]);
                })(),
                'Email/import' => (function () use ($arguments, $reply, $error, $state): mixed {
                    $this->writes[] = ['Email/import', $arguments];

                    if (array_key_exists('ifInState', $arguments) && $arguments['ifInState'] !== $state) {
                        return $error('stateMismatch');
                    }

                    /** @var array{draft: array{blobId: string, mailboxIds: array<string, true>, keywords: array<string, true>}} $emails */
                    $emails = $arguments['emails'];
                    $id = 'e-'.$this->next++;
                    $this->emails[$id] = ['blob' => $emails['draft']['blobId'], 'mailboxIds' => $emails['draft']['mailboxIds'], 'keywords' => $emails['draft']['keywords']];
                    $this->state++;

                    if ($this->importStatusAfterApply !== null) {
                        $status = $this->importStatusAfterApply;
                        $this->importStatusAfterApply = null;

                        return Http::response(['error' => 'synthetic failure'], $status);
                    }

                    return $reply('Email/import', ['oldState' => $state, 'newState' => 'email-state-'.$this->state, 'created' => ['draft' => ['id' => $id, 'blobId' => $emails['draft']['blobId']]], 'notCreated' => null]);
                })(),
                'Email/set' => (function () use ($arguments, $reply, $error, $state): mixed {
                    $this->writes[] = ['Email/set', $arguments];

                    if ($this->destroyStatus !== null) {
                        $status = $this->destroyStatus;
                        $this->destroyStatus = null;

                        return Http::response(['error' => 'synthetic failure'], $status);
                    }

                    if (($arguments['ifInState'] ?? null) !== $state) {
                        return $error('stateMismatch');
                    }

                    /** @var list<string> $destroy */
                    $destroy = $arguments['destroy'];

                    foreach ($destroy as $id) {
                        unset($this->emails[$id]);
                    }

                    $this->state++;

                    return $reply('Email/set', ['oldState' => $state, 'newState' => 'email-state-'.$this->state, 'destroyed' => $destroy, 'notDestroyed' => null]);
                })(),
                default => Http::response(['error' => 'synthetic unexpected method'], 418),
            };
        };
    }

    /** A draft created outside this package. */
    public function put(string $id, string $raw): void
    {
        $this->blobs['blob-'.$id] = $raw;
        $this->emails[$id] = ['blob' => 'blob-'.$id, 'mailboxIds' => ['mb-drafts' => true], 'keywords' => ['$draft' => true]];
    }

    /** @return list<string> */
    public function writeMethods(): array
    {
        return array_map(fn (array $write): string => $write[0], $this->writes);
    }
}

function draftDecode(string $raw): string
{
    return (string) base64_decode(strtr($raw, '-_', '+/'), true);
}

function draftBytes(string $messageId, string $body = 'Synthetic draft body.'): string
{
    return "From: Draft Owner <owner@invented.test>\r\nTo: friend@invented.test\r\nSubject: Synthetic draft\r\n"
        ."Message-ID: <{$messageId}>\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=utf-8\r\n\r\n{$body}\r\n";
}

function draftGmailAccount(string $owner = 'owner-a'): MailAccount
{
    $account = MailAccount::query()->create([
        'owner_type' => 'synthetic-workspace',
        'owner_id' => $owner,
        'driver' => MailDriver::Gmail,
        'provider_account_id' => 'draft-'.$owner.'@invented.test',
    ]);
    app(MailAccountConnection::class)->store($account, new OAuthTokenSetCredential(
        'synthetic-draft-access', 'synthetic-draft-refresh', new DateTimeImmutable('+1 hour'), [GmailOAuth::SCOPE],
    ));

    return $account;
}

function draftJmapAccount(): MailAccount
{
    $account = MailAccount::query()->create([
        'owner_type' => 'synthetic-workspace',
        'owner_id' => 'owner-a',
        'driver' => MailDriver::Jmap,
        'provider_account_id' => 'jmap-draft-a',
    ]);
    app(MailAccountConnection::class)->store($account, new ApiTokenCredential('synthetic-draft-jmap-token'));

    return $account;
}

function draftAccountTarget(MailAccount $account): MailAccountTarget
{
    return new MailAccountTarget($account->id, $account->owner_type, $account->owner_id);
}

function draftTarget(MailAccount $account, string $draftId): DraftTarget
{
    return new DraftTarget($account->id, $account->owner_type, $account->owner_id, $draftId);
}

function draftFailure(Closure $write): MailWriteFailure
{
    try {
        $write(app(MailWriteService::class));
    } catch (MailWriteFailure $failure) {
        return $failure;
    }

    throw new RuntimeException('The draft write reported success.');
}

/** Route Gmail and JMAP requests to their fakes. */
function draftFake(DraftGmailProvider $gmail, DraftJmapProvider $jmap): void
{
    $gmailHandler = $gmail->handler();
    $jmapHandler = $jmap->handler();
    Http::fake(fn (Request $request) => str_contains($request->url(), 'fastmail') ? $jmapHandler($request) : $gmailHandler($request));
}

beforeEach(function (): void {
    putenv('MAIL_MIRROR_GMAIL_CLIENT_SECRET=synthetic-draft-client-secret');
    config()->set('mail-mirror.gmail.enabled', true);
    config()->set('mail-mirror.gmail.client_id', 'synthetic-draft-client.apps.example.test');
    config()->set('mail-mirror.jmap.enabled', true);
    config()->set('mail-mirror.writes.enabled', true);
    Http::preventStrayRequests();
});

afterEach(function (): void {
    putenv('MAIL_MIRROR_GMAIL_CLIENT_SECRET');
});

it('creates a Gmail draft from the exact bytes and confirms its revision by re-read', function (): void {
    $gmail = new DraftGmailProvider;
    draftFake($gmail, new DraftJmapProvider);
    $bytes = draftBytes('create-1@invented.test');

    $result = app(MailWriteService::class)->createDraft(draftAccountTarget(draftGmailAccount()), new DraftContent($bytes), 'thread-7');

    expect($result->outcome)->toBe(MailWriteOutcome::Applied)
        ->and($result->draft?->draftId)->toBe('d-1')
        ->and($result->draft?->providerMessageId)->toBe('m-1')
        ->and($result->draft?->messageId)->toBe('create-1@invented.test')
        ->and($result->draft?->threadId)->toBe('thread-7')
        ->and($result->draft?->rawSha256)->toBe(hash('sha256', $bytes))
        ->and($gmail->writes)->toBe(['create'])
        ->and($gmail->bodies[0])->toBe(['message' => ['raw' => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '='), 'threadId' => 'thread-7']])
        ->and($gmail->drafts['d-1']['raw'])->toBe($bytes);
});

it('creates a JMAP draft by uploading a blob and importing it into Drafts as a seen draft', function (): void {
    $jmap = new DraftJmapProvider;
    draftFake(new DraftGmailProvider, $jmap);
    $bytes = draftBytes('create-2@invented.test');

    $result = app(MailWriteService::class)->createDraft(draftAccountTarget(draftJmapAccount()), new DraftContent($bytes));

    expect($result->outcome)->toBe(MailWriteOutcome::Applied)
        ->and($result->draft?->draftId)->toBe('e-2')
        ->and($result->draft?->providerMessageId)->toBe('e-2')
        ->and($result->draft?->messageId)->toBe('create-2@invented.test')
        ->and($result->draft?->threadId)->toBe('thread-e-2')
        ->and($result->draft?->rawSha256)->toBe(hash('sha256', $bytes))
        ->and($jmap->uploads)->toBe(1)
        ->and($jmap->writes)->toBe([['Email/import', [
            'accountId' => 'jmap-draft-a',
            'emails' => ['draft' => ['blobId' => 'blob-1', 'mailboxIds' => ['mb-drafts' => true], 'keywords' => ['$draft' => true, '$seen' => true]]],
        ]]])
        ->and($jmap->blobs['blob-1'])->toBe($bytes);
});

it('finds a draft created by a possibly applied request by Message-ID and never creates a second one', function (MailDriver $driver): void {
    $gmail = new DraftGmailProvider;
    $gmail->statusAfterApply = 503;
    $jmap = new DraftJmapProvider;
    $jmap->importStatusAfterApply = 503;
    draftFake($gmail, $jmap);
    $account = $driver === MailDriver::Gmail ? draftGmailAccount() : draftJmapAccount();
    $content = new DraftContent(draftBytes('retry-1@invented.test'));

    $failure = draftFailure(fn (MailWriteService $service) => $service->createDraft(draftAccountTarget($account), $content));
    $retry = app(MailWriteService::class)->createDraft(draftAccountTarget($account), $content);

    expect($failure->safeCode)->toBe(MailWriteCode::ProviderFailed)
        ->and($failure->writeSent)->toBeTrue()
        ->and($retry->outcome)->toBe(MailWriteOutcome::AlreadyApplied)
        ->and($retry->draft?->rawSha256)->toBe($content->sha256)
        ->and($driver === MailDriver::Gmail ? count($gmail->drafts) : count($jmap->emails))->toBe(1)
        ->and($driver === MailDriver::Gmail ? $gmail->writes : $jmap->writeMethods())->toHaveCount(1);
})->with([MailDriver::Gmail, MailDriver::Jmap]);

it('refuses to create a draft whose Message-ID another draft already uses with different bytes', function (): void {
    $gmail = new DraftGmailProvider;
    $gmail->drafts['d-9'] = ['message' => 'm-9', 'thread' => 't-9', 'raw' => draftBytes('taken@invented.test', 'Other body.')];
    draftFake($gmail, new DraftJmapProvider);

    $failure = draftFailure(fn (MailWriteService $service) => $service->createDraft(
        draftAccountTarget(draftGmailAccount()),
        new DraftContent(draftBytes('taken@invented.test')),
    ));

    expect($failure->safeCode)->toBe(MailWriteCode::MessageIdConflict)
        ->and($failure->writeSent)->toBeFalse()
        ->and($gmail->writes)->toBe([]);
});

it('refuses a stale revision for replace and delete with zero provider writes', function (MailDriver $driver): void {
    $gmail = new DraftGmailProvider;
    $gmail->drafts['d-1'] = ['message' => 'm-1', 'thread' => 't-1', 'raw' => draftBytes('stale@invented.test')];
    $jmap = new DraftJmapProvider;
    $jmap->put('d-1', draftBytes('stale@invented.test'));
    draftFake($gmail, $jmap);
    $account = $driver === MailDriver::Gmail ? draftGmailAccount() : draftJmapAccount();
    $revision = app(MailWriteService::class)->draft(draftTarget($account, 'd-1'))->revision;

    // The draft is edited outside this package after the caller read it.
    $gmail->drafts['d-1'] = ['message' => 'm-edited', 'thread' => 't-1', 'raw' => draftBytes('stale@invented.test', 'Edited elsewhere.')];
    $jmap->blobs['blob-d-1'] = draftBytes('stale@invented.test', 'Edited elsewhere.');

    $replace = draftFailure(fn (MailWriteService $service) => $service->replaceDraft(
        draftTarget($account, 'd-1'), $revision, new DraftContent(draftBytes('stale@invented.test', 'Mine.')),
    ));
    $delete = draftFailure(fn (MailWriteService $service) => $service->deleteDraft(draftTarget($account, 'd-1'), $revision));

    expect([$replace->safeCode, $delete->safeCode])->toBe([MailWriteCode::StaleRevision, MailWriteCode::StaleRevision])
        ->and([$replace->writeSent, $delete->writeSent])->toBe([false, false])
        ->and($gmail->writes)->toBe([])
        ->and($jmap->writes)->toBe([])
        ->and($jmap->uploads)->toBe(0);
})->with([MailDriver::Gmail, MailDriver::Jmap]);

it('replaces a Gmail draft in place and confirms the new bytes by re-read', function (): void {
    $gmail = new DraftGmailProvider;
    $gmail->next = 5;
    $gmail->drafts['d-1'] = ['message' => 'm-1', 'thread' => 't-1', 'raw' => draftBytes('replace@invented.test')];
    draftFake($gmail, new DraftJmapProvider);
    $account = draftGmailAccount();
    $service = app(MailWriteService::class);
    $revision = $service->draft(draftTarget($account, 'd-1'))->revision;
    $content = new DraftContent(draftBytes('replace@invented.test', 'Revised.'));

    $result = $service->replaceDraft(draftTarget($account, 'd-1'), $revision, $content);

    expect($result->outcome)->toBe(MailWriteOutcome::Applied)
        ->and($result->draft?->draftId)->toBe('d-1')
        ->and($result->draft?->providerMessageId)->toBe('m-5')
        ->and($result->draft?->rawSha256)->toBe($content->sha256)
        ->and($result->draft?->revision)->not->toBe($revision)
        ->and($gmail->writes)->toBe(['update:d-1'])
        ->and($gmail->bodies[0]['message'])->toBe(['raw' => rtrim(strtr(base64_encode($content->bytes), '+/', '-_'), '='), 'threadId' => 't-1']);
});

it('reports a Gmail edit that races the update as a conflict, not applied', function (): void {
    $gmail = new DraftGmailProvider;
    $gmail->drafts['d-1'] = ['message' => 'm-1', 'thread' => 't-1', 'raw' => draftBytes('race@invented.test')];
    $gmail->afterUpdate = function () use ($gmail): void {
        $gmail->drafts['d-1']['raw'] = draftBytes('race@invented.test', 'Edited in Gmail during the write.');
        $gmail->drafts['d-1']['message'] = 'm-external';
    };
    draftFake($gmail, new DraftJmapProvider);
    $account = draftGmailAccount();
    $revision = app(MailWriteService::class)->draft(draftTarget($account, 'd-1'))->revision;

    $failure = draftFailure(fn (MailWriteService $service) => $service->replaceDraft(
        draftTarget($account, 'd-1'), $revision, new DraftContent(draftBytes('race@invented.test', 'Mine.')),
    ));

    expect($failure->safeCode)->toBe(MailWriteCode::RevisionConflict)
        ->and($failure->writeSent)->toBeTrue()
        ->and($gmail->writes)->toBe(['update:d-1']);
});

it('replaces a JMAP draft by importing first and destroying the old email only after, each ifInState-guarded', function (): void {
    $jmap = new DraftJmapProvider;
    $jmap->put('e-old', draftBytes('jmap-replace@invented.test'));
    draftFake(new DraftGmailProvider, $jmap);
    $account = draftJmapAccount();
    $service = app(MailWriteService::class);
    $revision = $service->draft(draftTarget($account, 'e-old'))->revision;
    $content = new DraftContent(draftBytes('jmap-replace@invented.test', 'Revised.'));

    $result = $service->replaceDraft(draftTarget($account, 'e-old'), $revision, $content);

    expect($result->outcome)->toBe(MailWriteOutcome::Applied)
        ->and($result->draft?->draftId)->toBe('e-2')
        ->and($result->draft?->rawSha256)->toBe($content->sha256)
        ->and($jmap->writes)->toBe([
            ['Email/import', [
                'accountId' => 'jmap-draft-a',
                'ifInState' => 'email-state-1',
                'emails' => ['draft' => ['blobId' => 'blob-1', 'mailboxIds' => ['mb-drafts' => true], 'keywords' => ['$draft' => true, '$seen' => true]]],
            ]],
            ['Email/set', ['accountId' => 'jmap-draft-a', 'ifInState' => 'email-state-2', 'destroy' => ['e-old']]],
        ])
        ->and(array_keys($jmap->emails))->toBe(['e-2']);
});

it('never destroys the old JMAP draft when the import is rejected', function (): void {
    $jmap = new DraftJmapProvider;
    $jmap->put('e-old', draftBytes('jmap-keep@invented.test'));
    draftFake(new DraftGmailProvider, $jmap);
    $account = draftJmapAccount();
    $service = app(MailWriteService::class);
    $revision = $service->draft(draftTarget($account, 'e-old'))->revision;
    $jmap->changeDuringUpload = true;

    $failure = draftFailure(fn (MailWriteService $s) => $s->replaceDraft(
        draftTarget($account, 'e-old'), $revision, new DraftContent(draftBytes('jmap-keep@invented.test', 'Revised.')),
    ));

    expect($failure->writeSent)->toBeFalse()
        ->and($jmap->writeMethods())->toBe(['Email/import'])
        ->and(array_keys($jmap->emails))->toBe(['e-old']);
});

it('finishes a JMAP replace interrupted between import and destroy on retry, leaving exactly one replacement', function (): void {
    $jmap = new DraftJmapProvider;
    $jmap->put('e-old', draftBytes('crash@invented.test'));
    $jmap->destroyStatus = 503;
    draftFake(new DraftGmailProvider, $jmap);
    $account = draftJmapAccount();
    $service = app(MailWriteService::class);
    $revision = $service->draft(draftTarget($account, 'e-old'))->revision;
    $content = new DraftContent(draftBytes('crash@invented.test', 'Revised.'));

    $failure = draftFailure(fn (MailWriteService $s) => $s->replaceDraft(draftTarget($account, 'e-old'), $revision, $content));

    expect($failure->writeSent)->toBeTrue()
        ->and(array_keys($jmap->emails))->toBe(['e-old', 'e-2']);

    $retry = $service->replaceDraft(draftTarget($account, 'e-old'), $revision, $content);

    expect($retry->outcome)->toBe(MailWriteOutcome::Applied)
        ->and($retry->draft?->draftId)->toBe('e-2')
        ->and(array_keys($jmap->emails))->toBe(['e-2'])
        ->and($jmap->writeMethods())->toBe(['Email/import', 'Email/set', 'Email/set'])
        ->and($jmap->uploads)->toBe(1)
        // The completed replace is confirmed without writing again.
        ->and($service->replaceDraft(draftTarget($account, 'e-old'), $revision, $content)->outcome)->toBe(MailWriteOutcome::AlreadyApplied)
        ->and($jmap->writes)->toHaveCount(3);
});

it('refuses a replace whose Message-ID another draft uses with different bytes', function (): void {
    $jmap = new DraftJmapProvider;
    $jmap->put('e-old', draftBytes('shared@invented.test'));
    $jmap->put('e-other', draftBytes('shared@invented.test', 'Different.'));
    draftFake(new DraftGmailProvider, $jmap);
    $account = draftJmapAccount();
    $revision = app(MailWriteService::class)->draft(draftTarget($account, 'e-old'))->revision;

    $failure = draftFailure(fn (MailWriteService $s) => $s->replaceDraft(
        draftTarget($account, 'e-old'), $revision, new DraftContent(draftBytes('shared@invented.test', 'Revised.')),
    ));

    expect($failure->safeCode)->toBe(MailWriteCode::MessageIdConflict)
        ->and($jmap->writes)->toBe([]);
});

it('confirms a Gmail replace retried after a possibly applied update without a second write', function (): void {
    $gmail = new DraftGmailProvider;
    $gmail->drafts['d-1'] = ['message' => 'm-1', 'thread' => 't-1', 'raw' => draftBytes('replay@invented.test')];
    $gmail->statusAfterApply = 503;
    draftFake($gmail, new DraftJmapProvider);
    $account = draftGmailAccount();
    $service = app(MailWriteService::class);
    $revision = $service->draft(draftTarget($account, 'd-1'))->revision;
    $content = new DraftContent(draftBytes('replay@invented.test', 'Revised.'));

    expect(draftFailure(fn (MailWriteService $s) => $s->replaceDraft(draftTarget($account, 'd-1'), $revision, $content))->writeSent)->toBeTrue()
        ->and($service->replaceDraft(draftTarget($account, 'd-1'), $revision, $content)->outcome)->toBe(MailWriteOutcome::AlreadyApplied)
        ->and($gmail->writes)->toBe(['update:d-1']);
});

it('deletes a draft, confirms it is gone, and confirms a retry without a second write', function (MailDriver $driver): void {
    $gmail = new DraftGmailProvider;
    $gmail->drafts['d-1'] = ['message' => 'm-1', 'thread' => 't-1', 'raw' => draftBytes('delete@invented.test')];
    $gmail->statusAfterApply = 503;
    $jmap = new DraftJmapProvider;
    $jmap->put('d-1', draftBytes('delete@invented.test'));
    draftFake($gmail, $jmap);
    $account = $driver === MailDriver::Gmail ? draftGmailAccount() : draftJmapAccount();
    $service = app(MailWriteService::class);
    $revision = $service->draft(draftTarget($account, 'd-1'))->revision;

    if ($driver === MailDriver::Gmail) {
        expect(draftFailure(fn (MailWriteService $s) => $s->deleteDraft(draftTarget($account, 'd-1'), $revision))->writeSent)->toBeTrue();
        $retry = $service->deleteDraft(draftTarget($account, 'd-1'), $revision);
        expect($retry->outcome)->toBe(MailWriteOutcome::AlreadyApplied)->and($gmail->writes)->toBe(['delete:d-1']);
    } else {
        $result = $service->deleteDraft(draftTarget($account, 'd-1'), $revision);
        expect($result->outcome)->toBe(MailWriteOutcome::Applied)
            ->and($jmap->writes)->toBe([['Email/set', ['accountId' => 'jmap-draft-a', 'ifInState' => 'email-state-1', 'destroy' => ['d-1']]]]);
    }

    expect($gmail->drafts === [] || $driver === MailDriver::Jmap)->toBeTrue()
        ->and(draftFailure(fn (MailWriteService $s) => $s->draft(draftTarget($account, 'd-1')))->safeCode)->toBe(MailWriteCode::DraftNotFound);
})->with([MailDriver::Gmail, MailDriver::Jmap]);

it('does not claim a draft deleted outside this package as deleted', function (): void {
    $gmail = new DraftGmailProvider;
    $gmail->drafts['d-1'] = ['message' => 'm-1', 'thread' => 't-1', 'raw' => draftBytes('gone@invented.test')];
    draftFake($gmail, new DraftJmapProvider);
    $account = draftGmailAccount();
    $revision = app(MailWriteService::class)->draft(draftTarget($account, 'd-1'))->revision;
    unset($gmail->drafts['d-1']);

    expect(draftFailure(fn (MailWriteService $s) => $s->deleteDraft(draftTarget($account, 'd-1'), $revision))->safeCode)
        ->toBe(MailWriteCode::DraftNotFound)
        ->and($gmail->writes)->toBe([]);
});

it('resolves a draft started outside jMail by its provider message ID on both providers', function (): void {
    $gmail = new DraftGmailProvider;
    $gmail->drafts = [
        'd-other' => ['message' => 'm-other', 'thread' => 't-other', 'raw' => draftBytes('other@invented.test')],
        'd-web' => ['message' => 'm-web', 'thread' => 't-web', 'raw' => "From: owner@invented.test\r\nSubject: Started in Gmail\r\n\r\nNo Message-ID yet.\r\n"],
    ];
    $jmap = new DraftJmapProvider;
    $jmap->put('e-web', draftBytes('web@invented.test'));
    draftFake($gmail, $jmap);
    $service = app(MailWriteService::class);
    $gmailAccount = draftGmailAccount();
    $jmapAccount = draftJmapAccount();

    $gmailDraft = $service->resolveDraft(new MailWriteTarget($gmailAccount->id, $gmailAccount->owner_type, $gmailAccount->owner_id, 'm-web'));
    $jmapDraft = $service->resolveDraft(new MailWriteTarget($jmapAccount->id, $jmapAccount->owner_type, $jmapAccount->owner_id, 'e-web'));

    expect([$gmailDraft->draftId, $gmailDraft->providerMessageId, $gmailDraft->messageId, $gmailDraft->threadId])->toBe(['d-web', 'm-web', null, 't-web'])
        ->and($gmailDraft->rawSha256)->toBe(hash('sha256', $gmail->drafts['d-web']['raw']))
        ->and([$jmapDraft->draftId, $jmapDraft->providerMessageId, $jmapDraft->messageId])->toBe(['e-web', 'e-web', 'web@invented.test'])
        ->and(draftFailure(fn (MailWriteService $s) => $s->resolveDraft(new MailWriteTarget($gmailAccount->id, $gmailAccount->owner_type, $gmailAccount->owner_id, 'm-missing')))->safeCode)
        ->toBe(MailWriteCode::DraftNotFound);
});

it('refuses draft bytes without exactly one valid Message-ID or with malformed headers before any request', function (string $bytes): void {
    Http::fake();

    try {
        new DraftContent($bytes);
        $code = null;
    } catch (MailWriteFailure $failure) {
        $code = $failure->safeCode;
    }

    expect($code)->toBe(MailWriteCode::InvalidDraft);
    Http::assertNothingSent();
})->with([
    'no Message-ID' => ["From: a@invented.test\r\nSubject: x\r\n\r\nBody\r\n"],
    'two Message-IDs' => ["Message-ID: <a@invented.test>\r\nMessage-ID: <b@invented.test>\r\n\r\nBody\r\n"],
    'Message-ID without angle brackets' => ["Message-ID: a@invented.test\r\n\r\nBody\r\n"],
    'Message-ID without a domain' => ["Message-ID: <local-only>\r\n\r\nBody\r\n"],
    'bare LF line endings' => ["Message-ID: <a@invented.test>\nSubject: x\n\nBody\n"],
    'no header terminator' => ["Message-ID: <a@invented.test>\r\nSubject: x\r\n"],
    'line without a colon' => ["Message-ID: <a@invented.test>\r\nnot a header\r\n\r\nBody\r\n"],
    'space in a field name' => ["Message-ID: <a@invented.test>\r\nX Bad: y\r\n\r\nBody\r\n"],
    'leading continuation' => [" folded\r\nMessage-ID: <a@invented.test>\r\n\r\nBody\r\n"],
    'NUL in a header' => ["Message-ID: <a@invented.test>\r\nSubject: a\0b\r\n\r\nBody\r\n"],
    'duplicate Subject' => ["Message-ID: <a@invented.test>\r\nSubject: a\r\nSubject: b\r\n\r\nBody\r\n"],
    'oversized header line' => ["Message-ID: <a@invented.test>\r\nSubject: ".str_repeat('a', 1000)."\r\n\r\nBody\r\n"],
]);

it('accepts folded headers and keeps the exact bytes', function (): void {
    $bytes = "Message-ID:\r\n <folded@invented.test>\r\nSubject: a\r\n\tcontinued\r\n\r\nBody\r\n";
    $content = new DraftContent($bytes);

    expect($content->messageId)->toBe('folded@invented.test')
        ->and($content->bytes)->toBe($bytes)
        ->and($content->sha256)->toBe(hash('sha256', $bytes));
});

it('refuses draft bytes over the configured size limit before any request', function (): void {
    config()->set('mail-mirror.writes.max_draft_bytes', 2048);
    Http::fake();
    $account = draftGmailAccount();
    $content = new DraftContent(draftBytes('big@invented.test', str_repeat('x', 2048)));

    expect(draftFailure(fn (MailWriteService $s) => $s->createDraft(draftAccountTarget($account), $content))->safeCode)->toBe(MailWriteCode::DraftTooLarge)
        ->and(draftFailure(fn (MailWriteService $s) => $s->replaceDraft(draftTarget($account, 'd-1'), str_repeat('0', 64), $content))->safeCode)->toBe(MailWriteCode::DraftTooLarge);
    Http::assertNothingSent();
});

it('refuses every draft write with writes_disabled before any request while the switch is off', function (): void {
    config()->set('mail-mirror.writes.enabled', false);
    Http::fake();
    $account = draftGmailAccount();
    $content = new DraftContent(draftBytes('off@invented.test'));
    $failures = [
        draftFailure(fn (MailWriteService $s) => $s->createDraft(draftAccountTarget($account), $content)),
        draftFailure(fn (MailWriteService $s) => $s->replaceDraft(draftTarget($account, 'd-1'), str_repeat('0', 64), $content)),
        draftFailure(fn (MailWriteService $s) => $s->deleteDraft(draftTarget($account, 'd-1'), str_repeat('0', 64))),
    ];

    expect(array_map(fn (MailWriteFailure $failure): MailWriteCode => $failure->safeCode, $failures))
        ->toBe([MailWriteCode::WritesDisabled, MailWriteCode::WritesDisabled, MailWriteCode::WritesDisabled]);
    Http::assertNothingSent();
});

it('rejects an owner or account mismatch with zero provider requests for every draft operation', function (): void {
    Http::fake();
    $account = draftGmailAccount();
    $content = new DraftContent(draftBytes('owner@invented.test'));
    $operations = [
        fn (MailWriteService $s): mixed => $s->createDraft(new MailAccountTarget($account->id, 'synthetic-workspace', 'owner-b'), $content),
        fn (MailWriteService $s): mixed => $s->replaceDraft(new DraftTarget($account->id, 'synthetic-workspace', 'owner-b', 'd-1'), str_repeat('0', 64), $content),
        fn (MailWriteService $s): mixed => $s->deleteDraft(new DraftTarget($account->id, null, null, 'd-1'), str_repeat('0', 64)),
        fn (MailWriteService $s): mixed => $s->draft(new DraftTarget($account->id + 9, 'synthetic-workspace', 'owner-a', 'd-1')),
        fn (MailWriteService $s): mixed => $s->resolveDraft(new MailWriteTarget($account->id, 'synthetic-team', 'owner-a', 'm-1')),
    ];

    foreach ($operations as $operation) {
        expect(fn () => $operation(app(MailWriteService::class)))->toThrow(AccountResourceMismatch::class);
    }

    Http::assertNothingSent();
});

it('serializes a create and a replace that use the same Message-ID, in either order', function (string $first): void {
    $gmail = new DraftGmailProvider;
    $gmail->drafts['d-old'] = ['message' => 'm-old', 'thread' => 't-old', 'raw' => draftBytes('other@invented.test')];
    draftFake($gmail, new DraftJmapProvider);
    $account = draftGmailAccount();
    $service = app(MailWriteService::class);
    $revision = $service->draft(draftTarget($account, 'd-old'))->revision;
    $created = new DraftContent(draftBytes('contended@invented.test', 'Created.'));
    $replacement = new DraftContent(draftBytes('contended@invented.test', 'Replaced.'));
    $create = fn (): mixed => $service->createDraft(draftAccountTarget($account), $created);
    $replace = fn (): mixed => $service->replaceDraft(draftTarget($account, 'd-old'), $revision, $replacement);
    $blocked = null;
    $requests = [0, 0];
    $inFlight = function () use (&$blocked, &$requests, $first, $create, $replace): void {
        $requests[0] = count(Http::recorded());

        try {
            ($first === 'create' ? $replace : $create)();
        } catch (MailWriteFailure $failure) {
            $blocked = $failure;
        }

        $requests[1] = count(Http::recorded());
    };

    if ($first === 'create') {
        $gmail->onCreate = $inFlight;
        $create();
    } else {
        $gmail->afterUpdate = $inFlight;
        $replace();
    }

    assert($blocked instanceof MailWriteFailure);
    $withMessageId = array_filter($gmail->drafts, fn (array $draft): bool => DraftContent::messageIdOf($draft['raw']) === 'contended@invented.test');

    expect($blocked->safeCode)->toBe(MailWriteCode::TargetBusy)
        ->and($blocked->writeSent)->toBeFalse()
        ->and($requests[1])->toBe($requests[0])
        ->and($gmail->writes)->toBe([$first === 'create' ? 'create' : 'update:d-old'])
        ->and($withMessageId)->toHaveCount(1);
})->with(['create', 'replace']);
