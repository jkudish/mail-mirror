<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Jkudish\MailMirror\Credentials\ApiTokenCredential;
use Jkudish\MailMirror\Credentials\MailAccountConnection;
use Jkudish\MailMirror\Credentials\OAuthTokenSetCredential;
use Jkudish\MailMirror\Enums\MailDriver;
use Jkudish\MailMirror\Enums\MailWriteCode;
use Jkudish\MailMirror\Enums\MailWriteOutcome;
use Jkudish\MailMirror\Exceptions\AccountResourceMismatch;
use Jkudish\MailMirror\Exceptions\MailImportFailure;
use Jkudish\MailMirror\Exceptions\MailWriteFailure;
use Jkudish\MailMirror\Gmail\GmailMailboxReader;
use Jkudish\MailMirror\Gmail\GmailOAuth;
use Jkudish\MailMirror\Jmap\FastmailJmapMailboxReader;
use Jkudish\MailMirror\Models\MailAccount;
use Jkudish\MailMirror\Tests\Support\LocalWriteServer;
use Jkudish\MailMirror\Write\DraftContent;
use Jkudish\MailMirror\Write\DraftTarget;
use Jkudish\MailMirror\Write\DraftUploadSession;
use Jkudish\MailMirror\Write\MailAccountTarget;
use Jkudish\MailMirror\Write\MailWriteService;
use Jkudish\MailMirror\Write\MailWriteTarget;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;
use ZBateson\MailMimeParser\MailMimeParser;

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

    /** @var array<string, array{bytes: string, size: int, thread: string, draft: string|null}> */
    public array $sessions = [];

    /** @var list<string> */
    public array $uploadBodies = [];

    public ?int $sessionStatus = null;

    public ?string $rangeOverride = null;

    /** @var Closure(string): string|null */
    public ?Closure $rewrite = null;

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

            if ($path === '/upload/gmail/v1/users/me/drafts') {
                if ($request->method() === 'POST') {
                    $id = 'upload-'.(count($this->sessions) + 1);
                    /** @var array{message: array{threadId?: string}} $metadata */
                    $metadata = json_decode($request->body(), true, flags: JSON_THROW_ON_ERROR);
                    $this->bodies[] = $metadata;
                    expect($request->header('X-Upload-Content-Type'))->toBe(['message/rfc822']);
                    $size = $request->header('X-Upload-Content-Length')[0] ?? null;
                    assert(is_string($size));
                    $this->sessions[$id] = ['bytes' => '', 'size' => (int) $size,
                        'thread' => $metadata['message']['threadId'] ?? 't-new', 'draft' => null];

                    return Http::response('', 200, ['Location' => 'https://gmail.googleapis.com/upload/gmail/v1/users/me/drafts?uploadType=resumable&upload_id='.$id.'&session_crd='.str_repeat('s', 586)]);
                }

                $uploadId = $query['upload_id'] ?? '';
                assert(is_string($uploadId));
                $session = $this->sessions[$uploadId] ?? null;

                if ($session === null || $this->sessionStatus !== null) {
                    return Http::response([], $this->sessionStatus ?? 404);
                }

                if ($request->body() === '') {
                    expect($request->header('Content-Range'))->toBe(['bytes */'.$session['size']]);

                    return $session['draft'] !== null ? Http::response(['id' => $session['draft']])
                        : Http::response('', 308, $this->rangeOverride !== null ? ['Range' => $this->rangeOverride]
                            : ($session['bytes'] === '' ? [] : ['Range' => 'bytes=0-'.(strlen($session['bytes']) - 1)]));
                }

                $offset = strlen($session['bytes']);
                expect($request->header('Content-Range'))->toBe(['bytes '.$offset.'-'.($session['size'] - 1).'/'.$session['size']]);
                $this->uploadBodies[] = $request->body();
                $this->sessions[$uploadId]['bytes'] .= $request->body();
                $raw = $this->sessions[$uploadId]['bytes'];
                expect(strlen($raw))->toBe($session['size']);
                $this->writes[] = 'create';

                if ($this->onCreate !== null) {
                    ($this->onCreate)();
                }

                $draftId = 'd-'.$this->next;
                $this->drafts[$draftId] = ['message' => 'm-'.$this->next++, 'thread' => $session['thread'],
                    'raw' => $this->rewrite === null ? $raw : ($this->rewrite)($raw)];
                $this->sessions[$uploadId]['draft'] = $draftId;

                return $this->afterWrite(['id' => $draftId]);
            }

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
                $raw = draftDecode($body['message']['raw']);
                $thread = $this->drafts[$id]['thread'];
                $this->drafts[$id] = ['raw' => $this->rewrite === null ? $raw : ($this->rewrite)($raw),
                    'message' => 'm-'.$this->next++, 'thread' => $thread];

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

    $service = app(MailWriteService::class);
    $target = draftAccountTarget(draftGmailAccount());
    $content = new DraftContent($bytes);
    $session = $service->prepareDraftUpload($target, 'create-1', $content, 'thread-7');
    $result = $service->createDraft($target, $content, 'thread-7', DraftUploadSession::fromCheckpoint($session->checkpoint()));

    expect($result->outcome)->toBe(MailWriteOutcome::Applied)
        ->and($result->draft?->draftId)->toBe('d-1')
        ->and($result->draft?->providerMessageId)->toBe('m-1')
        ->and($result->draft?->messageId)->toBe('create-1@invented.test')
        ->and($result->draft?->threadId)->toBe('thread-7')
        ->and($result->draft?->rawSha256)->toBe(hash('sha256', $bytes))
        ->and($gmail->writes)->toBe(['create'])
        ->and($gmail->bodies[0])->toBe(['message' => ['threadId' => 'thread-7']])
        ->and($gmail->uploadBodies)->toBe([$bytes])
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
    $session = $driver === MailDriver::Gmail ? app(MailWriteService::class)->prepareDraftUpload(draftAccountTarget($account), 'retry-1', $content) : null;

    $failure = draftFailure(fn (MailWriteService $service) => $service->createDraft(draftAccountTarget($account), $content, upload: $session));
    $retry = app(MailWriteService::class)->createDraft(draftAccountTarget($account), $content, upload: $session);

    expect($failure->safeCode)->toBe($driver === MailDriver::Gmail ? MailWriteCode::DraftUploadUnknown : MailWriteCode::ProviderFailed)
        ->and($failure->writeSent)->toBeTrue()
        ->and($retry->outcome)->toBe($driver === MailDriver::Gmail ? MailWriteOutcome::Applied : MailWriteOutcome::AlreadyApplied)
        ->and($retry->draft?->rawSha256)->toBe($content->sha256)
        ->and($driver === MailDriver::Gmail ? count($gmail->drafts) : count($jmap->emails))->toBe(1)
        ->and($driver === MailDriver::Gmail ? $gmail->writes : $jmap->writeMethods())->toHaveCount(1);
})->with([MailDriver::Gmail, MailDriver::Jmap]);

it('refuses to create a draft whose Message-ID another draft already uses with different bytes', function (): void {
    $jmap = new DraftJmapProvider;
    $jmap->put('e-9', draftBytes('taken@invented.test', 'Other body.'));
    draftFake(new DraftGmailProvider, $jmap);

    $failure = draftFailure(fn (MailWriteService $service) => $service->createDraft(
        draftAccountTarget(draftJmapAccount()),
        new DraftContent(draftBytes('taken@invented.test')),
    ));

    expect($failure->safeCode)->toBe(MailWriteCode::MessageIdConflict)
        ->and($failure->writeSent)->toBeFalse()
        ->and($jmap->writes)->toBe([]);
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
        ->and(draftFailure(fn (MailWriteService $s) => $s->prepareDraftUpload(draftAccountTarget($account), 'oversize', $content))->safeCode)->toBe(MailWriteCode::DraftTooLarge)
        ->and(draftFailure(fn (MailWriteService $s) => $s->replaceDraft(draftTarget($account, 'd-1'), str_repeat('0', 64), $content))->safeCode)->toBe(MailWriteCode::DraftTooLarge);
    Http::assertNothingSent();
});

it('refuses every draft write with writes_disabled before any request while the switch is off', function (): void {
    config()->set('mail-mirror.writes.enabled', false);
    Http::fake();
    $account = draftGmailAccount();
    $content = new DraftContent(draftBytes('off@invented.test'));
    $failures = [
        draftFailure(fn (MailWriteService $s) => $s->prepareDraftUpload(draftAccountTarget($account), 'disabled', $content)),
        draftFailure(fn (MailWriteService $s) => $s->createDraft(draftAccountTarget($account), $content)),
        draftFailure(fn (MailWriteService $s) => $s->replaceDraft(draftTarget($account, 'd-1'), str_repeat('0', 64), $content)),
        draftFailure(fn (MailWriteService $s) => $s->deleteDraft(draftTarget($account, 'd-1'), str_repeat('0', 64))),
    ];

    expect(array_map(fn (MailWriteFailure $failure): MailWriteCode => $failure->safeCode, $failures))
        ->toBe([MailWriteCode::WritesDisabled, MailWriteCode::WritesDisabled, MailWriteCode::WritesDisabled, MailWriteCode::WritesDisabled]);
    Http::assertNothingSent();
});

it('rejects an owner or account mismatch with zero provider requests for every draft operation', function (): void {
    Http::fake();
    $account = draftGmailAccount();
    $content = new DraftContent(draftBytes('owner@invented.test'));
    $operations = [
        fn (MailWriteService $s): mixed => $s->prepareDraftUpload(new MailAccountTarget($account->id, 'synthetic-workspace', 'owner-b'), 'wrong-owner', $content),
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
    $session = $service->prepareDraftUpload(draftAccountTarget($account), 'contended', $created);
    $create = fn (): mixed => $service->createDraft(draftAccountTarget($account), $created, upload: $session);
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

it('prepares without MIME and recovers a completed Gmail session twice despite a rewritten Message-ID', function (): void {
    $gmail = new DraftGmailProvider;
    $gmail->rewrite = fn (string $raw): string => "Received: by synthetic.gmail.test\r\n".str_replace('<retry@invented.test>', '<provider@gmail.invented.test>', $raw);
    draftFake($gmail, new DraftJmapProvider);
    $target = draftAccountTarget(draftGmailAccount());
    $content = new DraftContent(draftBytes('retry@invented.test'));
    $service = app(MailWriteService::class);
    $session = $service->prepareDraftUpload($target, 'durable-op-7', $content, 'thread-reply');
    $checkpoint = $session->checkpoint();

    expect($gmail->drafts)->toBe([])->and($gmail->uploadBodies)->toBe([])
        ->and($checkpoint)->not->toContain('googleapis.com')
        ->and(json_encode($session, JSON_THROW_ON_ERROR))->not->toContain('upload_id')
        ->and(fn () => serialize($session))->toThrow(LogicException::class);
    expect(strlen($session->uri()))->toBe(699);
    $gmail->statusAfterApply = 503;
    expect(draftFailure(fn (MailWriteService $s) => $s->createDraft($target, $content, 'thread-reply', $session))->writeSent)->toBeTrue();

    $restored = DraftUploadSession::fromCheckpoint($checkpoint);
    expect($restored->uri())->toBe($session->uri());
    $result = $service->createDraft($target, $content, 'thread-reply', $restored);
    $again = $service->createDraft($target, $content, 'thread-reply', $restored);

    expect($result->draft?->draftId)->toBe('d-1')
        ->and($again->draft?->revision)->toBe($result->draft?->revision)
        ->and($result->draft?->messageId)->toBe('provider@gmail.invented.test')
        ->and($result->draft?->rawSha256)->toBe(hash('sha256', $gmail->drafts['d-1']['raw']))
        ->and($result->draft?->rawSha256)->not->toBe($content->sha256)
        ->and($gmail->sessions)->toHaveCount(1)->and($gmail->uploadBodies)->toBe([$content->bytes])
        ->and($gmail->writes)->toBe(['create']);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && $request->url() === $session->uri() && $request->body() === $content->bytes);
});

it('resumes only the missing Gmail upload suffix established by provider Range', function (): void {
    $gmail = new DraftGmailProvider;
    draftFake($gmail, new DraftJmapProvider);
    $target = draftAccountTarget(draftGmailAccount());
    $content = new DraftContent(draftBytes('partial@invented.test'));
    $service = app(MailWriteService::class);
    $session = $service->prepareDraftUpload($target, 'partial', $content);
    $gmail->sessions['upload-1']['bytes'] = substr($content->bytes, 0, 43);

    expect($service->createDraft($target, $content, upload: $session)->draft?->rawSha256)->toBe($content->sha256)
        ->and($gmail->uploadBodies)->toBe([substr($content->bytes, 43)])
        ->and($gmail->writes)->toBe(['create']);
});

it('never recreates from an expired or ambiguous Gmail session', function (int $status, ?string $range): void {
    $gmail = new DraftGmailProvider;
    draftFake($gmail, new DraftJmapProvider);
    $target = draftAccountTarget(draftGmailAccount());
    $content = new DraftContent(draftBytes('expired@invented.test'));
    $service = app(MailWriteService::class);
    $session = $service->prepareDraftUpload($target, 'expired', $content);
    $gmail->sessionStatus = $status === 308 ? null : $status;
    $gmail->rangeOverride = $range;

    $failure = draftFailure(fn (MailWriteService $s) => $s->createDraft($target, $content, upload: $session));

    expect($failure->safeCode)->toBe(MailWriteCode::DraftUploadUnknown)
        ->and($failure->writeSent)->toBeTrue()->and($gmail->sessions)->toHaveCount(1)
        ->and($gmail->uploadBodies)->toBe([])->and($gmail->writes)->toBe([]);
})->with([
    'expired' => [404, null], 'gone' => [410, null], 'server failure' => [503, null],
    'redirect' => [307, null], 'ambiguous range' => [308, 'bytes=5-42'],
    'all bytes but no draft ID' => [308, 'bytes=0-999999'],
]);

it('refuses absent or mismatched Gmail recovery state before any provider request', function (): void {
    Http::fake();
    $account = draftGmailAccount();
    $otherAccount = draftGmailAccount('owner-b');
    $target = draftAccountTarget($account);
    $content = new DraftContent(draftBytes('bound@invented.test'));
    $uri = 'https://www.googleapis.com/upload/gmail/v1/users/me/drafts?uploadType=resumable&upload_id=synthetic';
    $session = DraftUploadSession::issued($target, 'bound', $content, 'thread-bound', $uri);
    $wrongOwner = DraftUploadSession::issued(new MailAccountTarget($account->id, 'synthetic-workspace', 'owner-b'), 'bound', $content, 'thread-bound', $uri);

    expect(draftFailure(fn (MailWriteService $s) => $s->createDraft($target, $content))->safeCode)->toBe(MailWriteCode::DraftUploadRequired);

    foreach ([
        fn (MailWriteService $s): mixed => $s->createDraft(draftAccountTarget($otherAccount), $content, 'thread-bound', $session),
        fn (MailWriteService $s): mixed => $s->createDraft($target, $content, 'thread-bound', $wrongOwner),
        fn (MailWriteService $s): mixed => $s->createDraft($target, new DraftContent(draftBytes('bound@invented.test', 'Changed.')), 'thread-bound', $session),
        fn (MailWriteService $s): mixed => $s->createDraft($target, $content, 'thread-other', $session),
    ] as $call) {
        expect(draftFailure($call)->safeCode)->toBe(MailWriteCode::InvalidDraftUpload);
    }

    expect(fn () => DraftUploadSession::fromCheckpoint('missing-or-corrupt'))->toThrow(MailWriteFailure::class);
    Http::assertNothingSent();
});

it('rejects unsafe Gmail session destinations before transport', function (string $uri): void {
    Http::fake();

    expect(fn () => DraftUploadSession::issued(draftAccountTarget(draftGmailAccount()), 'unsafe', new DraftContent(draftBytes('safe@invented.test')), null, $uri))
        ->toThrow(MailWriteFailure::class);
    Http::assertNothingSent();
})->with([
    'http://www.googleapis.com/upload/gmail/v1/users/me/drafts?uploadType=resumable&upload_id=x',
    'https://www.googleapis.com.evil.test/upload/gmail/v1/users/me/drafts?uploadType=resumable&upload_id=x',
    'https://evil@www.googleapis.com/upload/gmail/v1/users/me/drafts?uploadType=resumable&upload_id=x',
    'https://www.googleapis.com/upload/gmail/v1/users/me/messages/send?uploadType=resumable&upload_id=x',
    'https://www.googleapis.com/upload/gmail/v1/users/another/drafts?uploadType=resumable&upload_id=x',
    'unknown key' => 'https://gmail.googleapis.com/upload/gmail/v1/users/me/drafts?uploadType=resumable&upload_id=x&other=y',
    'unknown fourth key' => 'https://gmail.googleapis.com/upload/gmail/v1/users/me/drafts?uploadType=resumable&upload_id=x&session_crd=y&other=z',
    'duplicate required key' => 'https://gmail.googleapis.com/upload/gmail/v1/users/me/drafts?uploadType=resumable&upload_id=x&upload_id=y',
    'duplicate optional key' => 'https://gmail.googleapis.com/upload/gmail/v1/users/me/drafts?uploadType=resumable&upload_id=x&session_crd=y&session_crd=z',
    'encoded duplicate optional key' => 'https://gmail.googleapis.com/upload/gmail/v1/users/me/drafts?uploadType=resumable&upload_id=x&session_crd=y&session%5Fcrd=z',
    'empty optional value' => 'https://gmail.googleapis.com/upload/gmail/v1/users/me/drafts?uploadType=resumable&upload_id=x&session_crd=',
    'array optional value' => 'https://gmail.googleapis.com/upload/gmail/v1/users/me/drafts?uploadType=resumable&upload_id=x&session_crd[]=y',
    'normalized unknown key' => 'https://gmail.googleapis.com/upload/gmail/v1/users/me/drafts?uploadType=resumable&upload_id=x&session.crd=y',
    'missing required key' => 'https://gmail.googleapis.com/upload/gmail/v1/users/me/drafts?uploadType=resumable&session_crd=y',
    'explicit port' => 'https://gmail.googleapis.com:443/upload/gmail/v1/users/me/drafts?uploadType=resumable&upload_id=x&session_crd=y',
    'fragment' => 'https://gmail.googleapis.com/upload/gmail/v1/users/me/drafts?uploadType=resumable&upload_id=x&session_crd=y#fragment',
    'control character' => "https://gmail.googleapis.com/upload/gmail/v1/users/me/drafts?uploadType=resumable&upload_id=x&session_crd=y\n",
    'oversized capability' => 'https://gmail.googleapis.com/upload/gmail/v1/users/me/drafts?uploadType=resumable&upload_id=x&session_crd='.str_repeat('s', 8192),
]);

function meaningfulDraftBytes(): string
{
    return "Received: caller-trace\r\nFrom: Owner <owner@invented.test>\r\nTo: friend@invented.test\r\nCc: cc@invented.test\r\nBcc: hidden@invented.test\r\nReply-To: reply@invented.test\r\n"
        ."Subject: Meaningful\r\nDate: Thu, 1 Oct 2026 17:04:05 -0700\r\nMessage-ID: <meaning@invented.test>\r\n"
        ."In-Reply-To: <parent@invented.test>\r\nReferences: <older@invented.test> <parent@invented.test>\r\nX-Private: preserved\r\n"
        ."MIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=synthetic\r\n\r\n"
        ."--synthetic\r\nContent-Type: text/plain\r\n\r\nBody unchanged.\r\n"
        ."--synthetic\r\nContent-Type: application/octet-stream\r\nContent-Transfer-Encoding: base64\r\n\r\nAAECAw==\r\n"
        ."--synthetic\r\nContent-Type: message/rfc822\r\n\r\nReceived: nested-significant\r\nMessage-ID: <nested@invented.test>\r\n\r\nNested body.\r\n--synthetic--\r\n";
}

it('allows only supported Gmail header differences while keeping exact provider revisions', function (): void {
    $gmail = new DraftGmailProvider;
    $gmail->rewrite = fn (string $raw): string => "Received: added by synthetic.gmail.test\r\n".str_replace(
        ['<meaning@invented.test>', 'Thu, 1 Oct 2026 17:04:05 -0700'],
        ['<assigned@gmail.invented.test>', 'Fri, 02 Oct 2026 00:04:05 GMT'], $raw);
    draftFake($gmail, new DraftJmapProvider);
    $target = draftAccountTarget(draftGmailAccount());
    $content = new DraftContent(meaningfulDraftBytes());
    $service = app(MailWriteService::class);
    $session = $service->prepareDraftUpload($target, 'meaning', $content);
    $draft = $service->createDraft($target, $content, upload: $session)->draft;
    assert($draft !== null);

    expect($draft->messageId)->toBe('assigned@gmail.invented.test')->and($draft->rawBytes)->toBe($gmail->drafts['d-1']['raw'])
        ->and($draft->rawSha256)->toBe(hash('sha256', $gmail->drafts['d-1']['raw']))->and($draft->rawSha256)->not->toBe($content->sha256);
    // A provider-byte-only difference still changes the exact revision and blocks stale writes.
    $gmail->drafts['d-1']['raw'] = "Received: another provider hop\r\n".$gmail->drafts['d-1']['raw'];
    $draftTarget = new DraftTarget($target->mailAccountId, $target->ownerType, $target->ownerId, 'd-1');
    expect($service->draft($draftTarget)->revision)->not->toBe($draft->revision)
        ->and(draftFailure(fn (MailWriteService $s) => $s->replaceDraft($draftTarget, $draft->revision, $content))->safeCode)->toBe(MailWriteCode::StaleRevision)
        ->and($gmail->writes)->toBe(['create']);
});

it('rejects Gmail transformations of meaningful MIME and retains the known draft ID', function (string $before, string $after): void {
    $gmail = new DraftGmailProvider;
    $gmail->rewrite = fn (string $raw): string => str_replace($before, $after, $raw);
    draftFake($gmail, new DraftJmapProvider);
    $target = draftAccountTarget(draftGmailAccount());
    $content = new DraftContent(meaningfulDraftBytes());
    $service = app(MailWriteService::class);
    $session = $service->prepareDraftUpload($target, 'mutated', $content);
    $failure = draftFailure(fn (MailWriteService $s) => $s->createDraft($target, $content, upload: $session));

    expect($failure->safeCode)->toBe(MailWriteCode::RevisionConflict)->and($failure->writeSent)->toBeTrue()
        ->and($failure->draftId)->toBe('d-1')
        ->and(draftFailure(fn (MailWriteService $s) => $s->createDraft($target, $content, upload: $session))->draftId)->toBe('d-1')
        ->and($gmail->writes)->toBe(['create']);
})->with([
    'From' => ['owner@invented.test', 'intruder@invented.test'], 'To' => ['friend@invented.test', 'different@invented.test'],
    'Cc' => ['cc@invented.test', 'else@invented.test'], 'Bcc' => ['hidden@invented.test', 'leaked@invented.test'],
    'Reply-To' => ['reply@invented.test', 'redirect@invented.test'], 'Subject' => ['Subject: Meaningful', 'Subject: Altered'],
    'Date instant' => ['17:04:05 -0700', '17:04:06 -0700'], 'invalid Date' => ['1 Oct 2026', '32 Oct 2026'],
    'unsupported Date' => ['-0700', 'PST'], 'caller trace' => ['caller-trace', 'discarded'],
    'threading' => ['<parent@invented.test>', '<unrelated@invented.test>'], 'X header' => ['X-Private: preserved', 'X-Private: removed'],
    'MIME boundary' => ['boundary=synthetic', 'boundary=other'], 'body' => ['Body unchanged.', 'Body changed.'],
    'attachment' => ['AAECAw==', 'AAECAA=='], 'nested Message-ID' => ['<nested@invented.test>', '<other@invented.test>'],
    'nested Received' => ['nested-significant', 'nested-changed'], 'encoding' => ['Content-Transfer-Encoding: base64', 'Content-Transfer-Encoding: x-unknown'],
    'duplicate field' => ['Subject: Meaningful', "Subject: Meaningful\r\nSubject: Ambiguous"],
]);

it('recovers a Gmail replace at its known draft ID after Gmail rewrites the client Message-ID', function (): void {
    $gmail = new DraftGmailProvider;
    $gmail->drafts['d-1'] = ['message' => 'm-old', 'thread' => 't-1', 'raw' => draftBytes('before@invented.test')];
    $gmail->rewrite = fn (string $raw): string => "Received: synthetic\r\n".str_replace('<replace@invented.test>', '<gmail-assigned@invented.test>', $raw);
    $gmail->statusAfterApply = 503;
    draftFake($gmail, new DraftJmapProvider);
    $account = draftGmailAccount();
    $service = app(MailWriteService::class);
    $revision = $service->draft(draftTarget($account, 'd-1'))->revision;
    $content = new DraftContent(draftBytes('replace@invented.test', 'Replaced bytes.'));

    expect(draftFailure(fn (MailWriteService $s) => $s->replaceDraft(draftTarget($account, 'd-1'), $revision, $content))->writeSent)->toBeTrue();
    $result = $service->replaceDraft(draftTarget($account, 'd-1'), $revision, $content);
    expect($result->outcome)->toBe(MailWriteOutcome::AlreadyApplied)->and($result->draft?->messageId)->toBe('gmail-assigned@invented.test')
        ->and($gmail->writes)->toBe(['update:d-1']);
    $gmail->drafts['d-1']['raw'] = str_replace('Replaced bytes.', 'Edited externally.', $gmail->drafts['d-1']['raw']);
    expect(draftFailure(fn (MailWriteService $s) => $s->replaceDraft(draftTarget($account, 'd-1'), $revision, $content))->safeCode)->toBe(MailWriteCode::StaleRevision)
        ->and($gmail->writes)->toBe(['update:d-1']);
});

it('never recovers a deleted Gmail replacement through an unrelated Message-ID match', function (): void {
    $gmail = new DraftGmailProvider;
    $gmail->drafts['d-original'] = ['message' => 'm-old', 'thread' => 't-1', 'raw' => draftBytes('before@invented.test')];
    $gmail->statusAfterApply = 503;
    draftFake($gmail, new DraftJmapProvider);
    $account = draftGmailAccount();
    $service = app(MailWriteService::class);
    $target = draftTarget($account, 'd-original');
    $revision = $service->draft($target)->revision;
    $content = new DraftContent(draftBytes('replacement@invented.test', 'Approved replacement.'));
    expect(draftFailure(fn (MailWriteService $s) => $s->replaceDraft($target, $revision, $content))->writeSent)->toBeTrue();

    unset($gmail->drafts['d-original']);
    $gmail->drafts['d-unrelated'] = ['message' => 'm-unrelated', 'thread' => 't-1',
        'raw' => "Received: unrelated synthetic trace\r\n".$content->bytes];
    $requestsBeforeRetry = Http::recorded()->count();
    $failure = draftFailure(fn (MailWriteService $s) => $s->replaceDraft($target, $revision, $content));

    expect($failure->safeCode)->toBe(MailWriteCode::DraftNotFound)->and($failure->writeSent)->toBeFalse()
        ->and($gmail->writes)->toBe(['update:d-original'])
        ->and(Http::recorded()->slice($requestsBeforeRetry)->map(fn (array $pair): string => $pair[0]->url())->values()->all())
        ->toBe(['https://gmail.googleapis.com/gmail/v1/users/me/drafts/d-original?format=raw']);
});

function draftDebugDump(mixed $value): string
{
    $cloner = new VarCloner;
    $cloner->setMaxItems(-1);
    $dump = (new CliDumper)->dump($cloner->cloneVar($value), true);

    if (! is_string($dump) || $dump === '') {
        throw new RuntimeException('The debug dump was empty.');
    }

    return $dump;
}

it('keeps a session capability encrypted in real object dumps and enabled exception argument traces', function (bool $transportFailure): void {
    $original = ini_set('zend.exception_ignore_args', '0');

    try {
        $account = draftGmailAccount();
        $target = draftAccountTarget($account);
        $content = new DraftContent(draftBytes('private@invented.test', 'PRIVATE-MIME-MARKER'));
        $uri = 'https://gmail.googleapis.com/upload/gmail/v1/users/me/drafts?uploadType=resumable&upload_id=FAKE-CAPABILITY&session_crd=FAKE-SESSION-CREDENTIAL';
        $session = DraftUploadSession::issued($target, 'private-operation', $content, null, $uri);
        $restored = DraftUploadSession::fromCheckpoint($session->checkpoint());

        expect($session->checkpoint())->not->toContain('FAKE-CAPABILITY', 'FAKE-SESSION-CREDENTIAL')
            ->and(json_encode($session, JSON_THROW_ON_ERROR))->not->toContain('FAKE-CAPABILITY', 'FAKE-SESSION-CREDENTIAL')
            ->and(draftDebugDump([$session, $restored]))->not->toContain('FAKE-CAPABILITY', 'FAKE-SESSION-CREDENTIAL', 'PRIVATE-MIME-MARKER')
            ->and(var_export([$session, $restored], true))->not->toContain('FAKE-CAPABILITY', 'FAKE-SESSION-CREDENTIAL')
            ->and($restored->uri())->toBe($uri);

        Http::fake(function (Request $request) use ($transportFailure) {
            if ($request->body() === '') {
                return Http::response('', 308);
            }

            if ($transportFailure) {
                throw new ConnectionException('FAKE-CAPABILITY FAKE-SESSION-CREDENTIAL PRIVATE-MIME-MARKER');
            }

            return Http::response('PRIVATE-MIME-MARKER', 503, ['Location' => 'FAKE-CAPABILITY FAKE-SESSION-CREDENTIAL']);
        });

        try {
            app(MailWriteService::class)->createDraft($target, $content, upload: $restored);
            throw new RuntimeException('The synthetic upload unexpectedly succeeded.');
        } catch (MailWriteFailure $failure) {
            expect($failure->safeCode)->toBe(MailWriteCode::DraftUploadUnknown)->and($failure->getPrevious())->toBeNull();
            // Exclude Pest's circular runner arguments, not any package frame.
            $trace = var_export(array_filter($failure->getTrace(), fn (array $frame): bool => str_starts_with($frame['class'] ?? '', 'Jkudish\\MailMirror\\')), true);
            expect($trace)->toContain('SensitiveParameterValue');
            expect($trace)->not->toContain('FAKE-CAPABILITY', 'FAKE-SESSION-CREDENTIAL', 'PRIVATE-MIME-MARKER')
                ->and(draftDebugDump([$failure, $failure->getTrace()]))->not->toContain('FAKE-CAPABILITY', 'FAKE-SESSION-CREDENTIAL', 'PRIVATE-MIME-MARKER');
        }

        try {
            DraftUploadSession::issued($target, 'invalid', $content, null, str_replace('https:', 'http:', $uri));
            throw new RuntimeException('The unsafe URI was accepted.');
        } catch (MailWriteFailure $failure) {
            expect(draftDebugDump([$failure, $failure->getTrace()]))->not->toContain('FAKE-CAPABILITY', 'FAKE-SESSION-CREDENTIAL', 'PRIVATE-MIME-MARKER');
        }
    } finally {
        ini_set('zend.exception_ignore_args', $original === false ? '1' : $original);
    }
})->with(['HTTP rejection' => false, 'connection failure' => true]);

it('does not leak recorded capability or MIME through a draft confirmation failure trace', function (): void {
    $original = ini_set('zend.exception_ignore_args', '0');

    try {
        $gmail = new DraftGmailProvider;
        $gmail->rewrite = fn (string $raw): string => str_replace('PRIVATE-MIME-MARKER', 'Changed externally.', $raw);
        draftFake($gmail, new DraftJmapProvider);
        $target = draftAccountTarget(draftGmailAccount());
        $content = new DraftContent(draftBytes('confirm@invented.test', 'PRIVATE-MIME-MARKER'));
        $service = app(MailWriteService::class);
        $session = $service->prepareDraftUpload($target, 'confirm', $content);

        try {
            $service->createDraft($target, $content, upload: $session);
            throw new RuntimeException('The changed content was accepted.');
        } catch (MailWriteFailure $failure) {
            expect($failure->safeCode)->toBe(MailWriteCode::RevisionConflict)->and($failure->draftId)->toBe('d-1')
                ->and(draftDebugDump([$failure, $failure->getTrace(), $failure->getPrevious()?->getTrace()]))->not->toContain('upload_id=upload-1', 'PRIVATE-MIME-MARKER');
        }
    } finally {
        ini_set('zend.exception_ignore_args', $original === false ? '1' : $original);
    }
});

it('restores an old checkpoint without keeping its plaintext capability in the object', function (): void {
    $account = draftGmailAccount();
    $content = new DraftContent(draftBytes('legacy@invented.test'));
    $uri = 'https://www.googleapis.com/upload/gmail/v1/users/me/drafts?uploadType=resumable&upload_id=LEGACY-CAPABILITY';
    $checkpoint = Crypt::encryptString(json_encode([
        'version' => 1, 'account' => $account->id, 'owner_type' => $account->owner_type, 'owner_id' => $account->owner_id,
        'operation' => 'legacy', 'sha256' => $content->sha256, 'size' => $content->size(), 'thread' => null, 'uri' => $uri,
    ], JSON_THROW_ON_ERROR));
    $session = DraftUploadSession::fromCheckpoint($checkpoint);

    expect($session->uri())->toBe($uri)->and(var_export($session, true))->not->toContain('LEGACY-CAPABILITY')
        ->and(DraftUploadSession::fromCheckpoint($session->checkpoint())->uri())->toBe($uri);
});

it('dispatches MIME once on real TLS despite a lost response or redirect and recovers by status', function (string $mode): void {
    $server = new LocalWriteServer($mode);

    try {
        $http = new Factory;
        $http->globalOptions(['verify' => $server->certificate, 'proxy' => '', 'timeout' => 3]);
        // Route through a real wire, using the retained Laravel middleware seam.
        $http->globalRequestMiddleware(fn (RequestInterface $request): RequestInterface => $request->withUri(
            $request->getUri()->withHost('localhost')->withPort((int) parse_url($server->origin, PHP_URL_PORT)),
        ));
        $reader = new GmailMailboxReader($http, app(MailAccountConnection::class), app(GmailOAuth::class), app(MailMimeParser::class));
        $account = draftGmailAccount();
        $content = new DraftContent(draftBytes('wire@invented.test', 'Synthetic complete body.'));
        $session = DraftUploadSession::issued(draftAccountTarget($account), 'wire', $content, null,
            'https://www.googleapis.com/upload/gmail/v1/users/me/drafts?uploadType=resumable&upload_id=wire');

        try {
            $reader->uploadDraft($account, $content, $session);
            throw new RuntimeException('The ambiguous wire response was accepted.');
        } catch (MailWriteFailure $failure) {
            expect($failure->safeCode)->toBe(MailWriteCode::DraftUploadUnknown)->and($failure->writeSent)->toBeTrue();
        }

        expect($reader->uploadDraft($account, $content, DraftUploadSession::fromCheckpoint($session->checkpoint())))->toBe('d-wire');
        $requests = $server->records();
        expect($requests)->toHaveCount(3)
            ->and(array_column($requests, 'range'))->toBe(['bytes */'.$content->size(), 'bytes 0-'.($content->size() - 1).'/'.$content->size(), 'bytes */'.$content->size()])
            ->and(array_column($requests, 'length'))->toBe([0, $content->size(), 0])
            ->and(array_column($requests, 'sha256')[1])->toBe(hash('sha256', $content->bytes))
            ->and(array_column($requests, 'line'))->toBe(array_fill(0, 3, 'PUT /upload/gmail/v1/users/me/drafts?uploadType=resumable&upload_id=wire HTTP/1.1'))
            ->and($server->records(true))->toHaveCount(3);
    } finally {
        $server->close();
    }
})->with(['lost response' => 'close', 'redirect' => 'redirect']);

it('routes provider writes through the fixed profile while leaving reads on the existing transport', function (string $operation): void {
    $server = new LocalWriteServer('echo');

    try {
        $http = new Factory;
        // Reads may use ordinary Guzzle options; writes must refuse this override.
        // If the fixed write handler is not selected, the local peer records a request.
        $http->globalOptions(['verify' => $server->certificate, 'curl' => [CURLOPT_FOLLOWLOCATION => true]]);
        $gmail = new DraftGmailProvider;
        $gmail->drafts['d-1'] = ['message' => 'm-1', 'thread' => 't-1', 'raw' => draftBytes('route@invented.test')];
        $jmap = new DraftJmapProvider;
        $jmap->put('e-1', draftBytes('route@invented.test'));
        $gmailHandler = $gmail->handler();
        $jmapHandler = $jmap->handler();
        $http->globalRequestMiddleware(function (RequestInterface $request) use ($server): RequestInterface {
            $payload = json_decode((string) $request->getBody(), true);
            $calls = is_array($payload) ? ($payload['methodCalls'] ?? null) : null;
            $call = is_array($calls) ? ($calls[0] ?? null) : null;
            $write = $request->getMethod() === 'DELETE'
                || str_contains($request->getUri()->getPath(), '/upload/')
                || (is_array($call) && ($call[0] ?? null) === 'Email/set');

            return $write ? $request->withUri($request->getUri()->withHost('localhost')->withPort((int) parse_url($server->origin, PHP_URL_PORT))) : $request;
        });
        $http->fake(function (Request $request) use ($gmailHandler, $jmapHandler) {
            if (str_starts_with($request->url(), 'https://localhost:')) {
                return null;
            }

            return str_contains($request->url(), 'fastmail') ? $jmapHandler($request) : $gmailHandler($request);
        });

        if ($operation === 'gmail-delete') {
            $account = draftGmailAccount();
            $reader = new GmailMailboxReader($http, app(MailAccountConnection::class), app(GmailOAuth::class), app(MailMimeParser::class));
            $draft = $reader->draft($account, 'd-1');
        } else {
            $account = draftJmapAccount();
            $reader = new FastmailJmapMailboxReader($http, app(MailAccountConnection::class));
            $draft = $reader->draft($account, 'e-1');
        }
        expect($draft)->not->toBeNull();
        if ($draft === null) {
            throw new LogicException('The read was refused by write-only options.');
        }

        try {
            if ($operation === 'jmap-upload') {
                $reader->stageDraft($account, new DraftContent(draftBytes('stage@invented.test')));
            } else {
                $reader->deleteDraft($account, $draft);
            }
            throw new LogicException('The provider write used the ordinary transport.');
        } catch (MailImportFailure|MailWriteFailure $failure) {
            expect($failure instanceof MailImportFailure ? $failure->safeCode->value : $failure->providerCode?->value)->toBe('provider_unavailable');
        }

        expect($server->records(true))->toBe([])->and($gmail->writes)->toBe([])->and($jmap->writes)->toBe([]);
    } finally {
        $server->close();
    }
})->with(['gmail-delete', 'jmap-delete', 'jmap-upload']);
