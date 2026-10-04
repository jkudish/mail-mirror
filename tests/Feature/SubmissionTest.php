<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Jkudish\MailMirror\Credentials\ApiTokenCredential;
use Jkudish\MailMirror\Credentials\MailAccountConnection;
use Jkudish\MailMirror\Credentials\OAuthTokenSetCredential;
use Jkudish\MailMirror\Enums\MailDriver;
use Jkudish\MailMirror\Enums\MailWriteCode;
use Jkudish\MailMirror\Enums\SubmissionOutcome;
use Jkudish\MailMirror\Exceptions\AccountResourceMismatch;
use Jkudish\MailMirror\Exceptions\MailWriteFailure;
use Jkudish\MailMirror\Gmail\GmailDraftContent;
use Jkudish\MailMirror\Gmail\GmailOAuth;
use Jkudish\MailMirror\Models\MailAccount;
use Jkudish\MailMirror\Models\MailIdentity;
use Jkudish\MailMirror\Write\DraftContent;
use Jkudish\MailMirror\Write\DraftTarget;
use Jkudish\MailMirror\Write\MailWriteService;

/** Synthetic Gmail drafts and sent messages; drafts.send moves a draft to SENT and deletes it. */
final class SubmitGmailProvider
{
    /** @var array<string, array{message: string, thread: string, raw: string}> */
    public array $drafts = [];

    /** @var array<string, array{thread: string, labels: list<string>, messageIdHeader: string|null}> */
    public array $messages = [];

    /** @var list<array<string, mixed>> */
    public array $sends = [];

    /** Apply the next send, then answer with this status, like a timeout after Gmail accepted it. */
    public ?int $sendStatusAfterApply = null;

    /** Answer the next send with this status without applying it. */
    public ?int $sendStatusBeforeApply = null;

    /** When set, the sent copy carries this Message-ID header instead of the draft's. */
    public ?string $sentMessageIdHeader = null;

    /** @var list<string> */
    public array $paths = [];

    /** @var array<string, string> the bytes Gmail actually sent, keyed by sent message ID */
    public array $sentRaw = [];

    /** @var (Closure(): void)|null Runs during each SENT search, for example an edit in Gmail's UI. */
    public ?Closure $onSentSearch = null;

    /** @return Closure(Request): mixed */
    public function handler(): Closure
    {
        return function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $this->paths[] = $request->method().' '.$path;

            if ($path === '/gmail/v1/users/me/drafts/send') {
                expect($request->method())->toBe('POST');
                /** @var array{id: string, message?: array{raw: string}} $body */
                $body = $request->data();
                $this->sends[] = $body;

                if ($this->sendStatusBeforeApply !== null) {
                    $status = $this->sendStatusBeforeApply;
                    $this->sendStatusBeforeApply = null;

                    return Http::response(['error' => 'synthetic failure'], $status);
                }

                $draft = $this->drafts[$body['id']] ?? null;

                if ($draft === null) {
                    return Http::response(['error' => 'synthetic not found'], 404);
                }

                $id = 'sent-'.$draft['message'];
                // drafts.send with a message sends those bytes; with only an ID, the stored draft.
                $raw = isset($body['message']) ? (string) base64_decode(strtr($body['message']['raw'], '-_', '+/'), true) : $draft['raw'];
                $this->sentRaw[$id] = $raw;
                $header = $this->sentMessageIdHeader ?? '<'.DraftContent::messageIdOf($raw).'>';
                $this->messages[$id] = ['thread' => $draft['thread'], 'labels' => ['SENT'], 'messageIdHeader' => $header];
                unset($this->drafts[$body['id']]);

                if ($this->sendStatusAfterApply !== null) {
                    $status = $this->sendStatusAfterApply;
                    $this->sendStatusAfterApply = null;

                    return Http::response(['error' => 'synthetic failure'], $status);
                }

                return Http::response(['id' => $id, 'threadId' => $draft['thread'], 'labelIds' => ['SENT']]);
            }

            if (preg_match('#^/gmail/v1/users/me/drafts/([^/]+)$#', $path, $matches) === 1) {
                $draft = $this->drafts[rawurldecode($matches[1])] ?? null;

                return $draft === null ? Http::response(['error' => 'synthetic not found'], 404) : Http::response(['id' => rawurldecode($matches[1]), 'message' => [
                    'id' => $draft['message'], 'threadId' => $draft['thread'], 'labelIds' => ['DRAFT'],
                    'raw' => rtrim(strtr(base64_encode($draft['raw']), '+/', '-_'), '='),
                ]]);
            }

            if ($path === '/gmail/v1/users/me/messages') {
                expect($query['labelIds'] ?? null)->toBe('SENT');

                if ($this->onSentSearch !== null) {
                    ($this->onSentSearch)();
                }

                $q = $query['q'] ?? null;
                $wanted = '<'.substr(is_string($q) ? $q : '', strlen('rfc822msgid:')).'>';

                return Http::response(['messages' => array_map(
                    fn (string $id): array => ['id' => $id, 'threadId' => $this->messages[$id]['thread']],
                    array_keys(array_filter($this->messages, fn (array $message): bool => $message['messageIdHeader'] === $wanted)),
                )]);
            }

            if (preg_match('#^/gmail/v1/users/me/messages/([^/]+)$#', $path, $matches) === 1) {
                $id = rawurldecode($matches[1]);
                $message = $this->messages[$id] ?? null;

                if ($message === null) {
                    return Http::response(['error' => 'synthetic not found'], 404);
                }

                return Http::response(['id' => $id, 'threadId' => $message['thread'], 'labelIds' => $message['labels']]
                    + (($query['format'] ?? null) === 'metadata'
                        ? ['payload' => ['headers' => $message['messageIdHeader'] === null ? [] : [['name' => 'Message-ID', 'value' => $message['messageIdHeader']]]]]
                        : []));
            }

            return Http::response(['error' => 'synthetic unexpected path'], 418);
        };
    }
}

/** Synthetic Fastmail JMAP drafts with EmailSubmission. */
final class SubmitJmapProvider
{
    /** @var array<string, array{blob: string, mailboxIds: array<string, true>, keywords: array<string, true>}> */
    public array $emails = [];

    /** @var array<string, string> */
    public array $blobs = [];

    /** @var array<string, array{emailId: string, undoStatus: string}> */
    public array $submissions = [];

    /** @var list<array<string, mixed>> */
    public array $sends = [];

    /** @var list<string> */
    public array $methods = [];

    public ?int $sendStatusAfterApply = null;

    /** Accept the submission but leave the email in Drafts, as if onSuccessUpdateEmail never applied. */
    public bool $skipOnSuccess = false;

    /** @var Closure(int): void|null */
    public ?Closure $onSubmissionQuery = null;

    /** @return Closure(Request): mixed */
    public function handler(): Closure
    {
        return function (Request $request) {
            $url = $request->url();

            if ($url === 'https://api.fastmail.com/jmap/session') {
                return Http::response([
                    'capabilities' => ['urn:ietf:params:jmap:core' => [], 'urn:ietf:params:jmap:mail' => [], 'urn:ietf:params:jmap:submission' => []],
                    'accounts' => ['jmap-submit-a' => ['accountCapabilities' => ['urn:ietf:params:jmap:mail' => [], 'urn:ietf:params:jmap:submission' => []]]],
                    'apiUrl' => 'https://api.fastmail.com/jmap/api/',
                    'downloadUrl' => 'https://www.fastmailusercontent.com/jmap/download/{accountId}/{blobId}/{name}?type={type}',
                    'uploadUrl' => 'https://api.fastmail.com/jmap/upload/{accountId}/',
                    'state' => 'synthetic-submit-session',
                ]);
            }

            if (str_starts_with($url, 'https://www.fastmailusercontent.com/jmap/download/jmap-submit-a/')) {
                $blobId = rawurldecode(explode('/', substr($url, strlen('https://www.fastmailusercontent.com/jmap/download/jmap-submit-a/')))[0]);

                return Http::response($this->blobs[$blobId] ?? '');
            }

            $calls = $request->data()['methodCalls'] ?? null;
            $call = is_array($calls) ? ($calls[0] ?? null) : null;
            assert(is_array($call) && is_string($call[0]) && is_array($call[1]) && is_string($call[2]));
            [$method, $arguments, $callId] = $call;
            /** @var array<string, mixed> $arguments */
            $this->methods[] = $method;
            $reply = fn (string $name, array $result): mixed => Http::response([
                'methodResponses' => [[$name, ['accountId' => 'jmap-submit-a'] + $result, $callId]],
                'sessionState' => 'synthetic-submit-session',
            ]);

            return match ($method) {
                'Mailbox/get' => $reply('Mailbox/get', ['state' => 'mailbox-state', 'notFound' => [], 'list' => [
                    ['id' => 'mb-inbox', 'name' => 'Inbox', 'role' => 'inbox'],
                    ['id' => 'mb-drafts', 'name' => 'Drafts', 'role' => 'drafts'],
                    ['id' => 'mb-sent', 'name' => 'Sent', 'role' => 'sent'],
                ]]),
                'Email/get' => (function () use ($arguments, $reply): mixed {
                    /** @var list<string> $ids */
                    $ids = $arguments['ids'];
                    $id = $ids[0];
                    $email = $this->emails[$id] ?? null;

                    return $email === null
                        ? $reply('Email/get', ['state' => 'email-state', 'notFound' => [$id], 'list' => []])
                        : $reply('Email/get', ['state' => 'email-state', 'notFound' => [], 'list' => [[
                            'id' => $id, 'blobId' => $email['blob'], 'threadId' => 'thread-'.$id,
                            'mailboxIds' => $email['mailboxIds'], 'keywords' => $email['keywords'],
                            'messageId' => [DraftContent::messageIdOf($this->blobs[$email['blob']])],
                        ]]]);
                })(),
                'Email/query' => (function () use ($arguments, $reply): mixed {
                    /** @var array{conditions: array{0: array{inMailbox: string}, 1: array{header: array{0: string, 1: string}}}} $filter */
                    $filter = $arguments['filter'];

                    return $reply('Email/query', ['queryState' => 'q', 'position' => 0, 'ids' => array_keys(array_filter(
                        $this->emails,
                        fn (array $email): bool => isset($email['mailboxIds'][$filter['conditions'][0]['inMailbox']])
                            && '<'.DraftContent::messageIdOf($this->blobs[$email['blob']]).'>' === $filter['conditions'][1]['header'][1],
                    ))]);
                })(),
                'EmailSubmission/query' => (function () use ($arguments, $reply): mixed {
                    /** @var array{emailIds: list<string>} $filter */
                    $filter = $arguments['filter'];
                    $position = is_int($arguments['position'] ?? null) ? $arguments['position'] : 0;

                    if ($this->onSubmissionQuery !== null) {
                        ($this->onSubmissionQuery)($position);
                    }

                    $all = array_keys(array_filter(
                        $this->submissions,
                        fn (array $submission): bool => in_array($submission['emailId'], $filter['emailIds'], true),
                    ));
                    $limit = min(10, is_int($arguments['limit'] ?? null) ? $arguments['limit'] : 10);

                    return $reply('EmailSubmission/query', [
                        'queryState' => hash('sha256', serialize($this->submissions)),
                        'position' => $position, 'limit' => $limit, 'total' => count($all),
                        'ids' => array_slice($all, $position, $limit),
                    ]);
                })(),
                'EmailSubmission/get' => (function () use ($arguments, $reply): mixed {
                    /** @var list<string> $ids */
                    $ids = $arguments['ids'];

                    return $reply('EmailSubmission/get', ['state' => 's', 'notFound' => [], 'list' => array_map(
                        fn (string $id): array => ['id' => $id, 'threadId' => 'thread-'.$this->submissions[$id]['emailId']] + $this->submissions[$id],
                        $ids,
                    )]);
                })(),
                'EmailSubmission/set' => (function () use ($arguments, $callId): mixed {
                    $this->sends[] = $arguments;
                    /** @var array{send: array{identityId: string, emailId: string}} $create */
                    $create = $arguments['create'];
                    $emailId = $create['send']['emailId'];
                    $this->submissions['sub-'.count($this->sends)] = ['emailId' => $emailId, 'undoStatus' => 'final'];
                    /** @var array{'#send': array<string, true|null>} $onSuccess */
                    $onSuccess = $arguments['onSuccessUpdateEmail'];

                    foreach ($this->skipOnSuccess ? [] : $onSuccess['#send'] as $path => $value) {
                        [$property, $key] = explode('/', $path, 2);
                        assert($property === 'mailboxIds' || $property === 'keywords');

                        if ($value === null) {
                            unset($this->emails[$emailId][$property][$key]);
                        } else {
                            $this->emails[$emailId][$property][$key] = true;
                        }
                    }

                    if ($this->sendStatusAfterApply !== null) {
                        $status = $this->sendStatusAfterApply;
                        $this->sendStatusAfterApply = null;

                        return Http::response(['error' => 'synthetic failure'], $status);
                    }

                    return Http::response(['methodResponses' => [
                        ['EmailSubmission/set', ['accountId' => 'jmap-submit-a', 'created' => ['send' => ['id' => 'sub-'.count($this->sends)]], 'notCreated' => null], $callId],
                        ['Email/set', ['accountId' => 'jmap-submit-a', 'updated' => [$emailId => null]], $callId],
                    ], 'sessionState' => 'synthetic-submit-session']);
                })(),
                default => Http::response(['error' => 'synthetic unexpected method'], 418),
            };
        };
    }

    public function put(string $id, string $raw): void
    {
        $this->blobs['blob-'.$id] = $raw;
        $this->emails[$id] = ['blob' => 'blob-'.$id, 'mailboxIds' => ['mb-drafts' => true], 'keywords' => ['$draft' => true, '$seen' => true]];
    }
}

function submitBytes(string $messageId, string $from = 'Owner <Owner@Invented.test>'): string
{
    return "From: {$from}\r\nTo: friend@invented.test\r\nSubject: Synthetic send\r\nMessage-ID: <{$messageId}>\r\n\r\nSynthetic body.\r\n";
}

function submitAccount(MailDriver $driver): MailAccount
{
    $account = MailAccount::query()->create([
        'owner_type' => 'synthetic-workspace',
        'owner_id' => 'owner-a',
        'driver' => $driver,
        'provider_account_id' => $driver === MailDriver::Gmail ? 'owner@invented.test' : 'jmap-submit-a',
    ]);
    app(MailAccountConnection::class)->store($account, $driver === MailDriver::Gmail
        ? new OAuthTokenSetCredential('synthetic-submit-access', 'synthetic-submit-refresh', new DateTimeImmutable('+1 hour'), [GmailOAuth::SCOPE])
        : new ApiTokenCredential('synthetic-submit-jmap-token'));
    MailIdentity::query()->create(['mail_account_id' => $account->id, 'provider_identity_id' => 'identity-owner', 'email_address' => 'owner@invented.test']);
    MailIdentity::query()->create(['mail_account_id' => $account->id, 'provider_identity_id' => 'identity-alias', 'email_address' => 'alias@invented.test']);

    return $account;
}

function submitTarget(MailAccount $account, string $draftId): DraftTarget
{
    return new DraftTarget($account->id, $account->owner_type, $account->owner_id, $draftId);
}

function submitFailure(Closure $write): MailWriteFailure
{
    try {
        $write(app(MailWriteService::class));
    } catch (MailWriteFailure $failure) {
        return $failure;
    }

    throw new RuntimeException('The submission reported success.');
}

/** @return array{SubmitGmailProvider, SubmitJmapProvider, MailAccount} */
function submitSetup(MailDriver $driver, string $messageId = 'send-1@invented.test', ?string $from = null): array
{
    $gmail = new SubmitGmailProvider;
    $jmap = new SubmitJmapProvider;
    $bytes = $from === null ? submitBytes($messageId) : submitBytes($messageId, $from);
    $gmail->drafts['d-1'] = ['message' => 'm-1', 'thread' => 't-1', 'raw' => $bytes];
    $jmap->put('d-1', $bytes);
    $gmailHandler = $gmail->handler();
    $jmapHandler = $jmap->handler();
    Http::fake(fn (Request $request) => str_contains($request->url(), 'fastmail') ? $jmapHandler($request) : $gmailHandler($request));

    return [$gmail, $jmap, submitAccount($driver)];
}

beforeEach(function (): void {
    putenv('MAIL_MIRROR_GMAIL_CLIENT_SECRET=synthetic-submit-client-secret');
    config()->set('mail-mirror.gmail.enabled', true);
    config()->set('mail-mirror.gmail.client_id', 'synthetic-submit-client.apps.example.test');
    config()->set('mail-mirror.jmap.enabled', true);
    config()->set('mail-mirror.writes.enabled', true);
    Http::preventStrayRequests();
});

afterEach(function (): void {
    putenv('MAIL_MIRROR_GMAIL_CLIENT_SECRET');
    Date::setTestNow();
});

it('refuses an initial submission claim before any provider request and leaves legacy submit available', function (MailDriver $driver): void {
    [$gmail, $jmap, $account] = submitSetup($driver);
    $service = app(MailWriteService::class);
    $target = submitTarget($account, 'd-1');
    $revision = $service->draft($target)->revision;
    $requests = count(Http::recorded());
    $calls = 0;

    $failure = submitFailure(function (MailWriteService $s) use ($target, $revision, &$calls): void {
        $s->submit($target, $revision, function () use (&$calls): void {
            $calls++;
            throw new MailWriteFailure(MailWriteCode::ClaimSuperseded);
        });
    });

    expect($calls)->toBe(1)
        ->and($failure->safeCode)->toBe(MailWriteCode::ClaimSuperseded)
        ->and($failure->writeSent)->toBeFalse()
        ->and(count(Http::recorded()))->toBe($requests)
        ->and($gmail->sends)->toBe([])
        ->and($jmap->sends)->toBe([]);

    expect($service->submit($target, $revision)->outcome)->toBe(SubmissionOutcome::Submitted)
        ->and($driver === MailDriver::Gmail ? $gmail->sends : $jmap->sends)->toHaveCount(1);
})->with([MailDriver::Gmail, MailDriver::Jmap]);

it('submits once with a current consumer claim checked twice', function (MailDriver $driver): void {
    [$gmail, $jmap, $account] = submitSetup($driver);
    $service = app(MailWriteService::class);
    $target = submitTarget($account, 'd-1');
    $revision = $service->draft($target)->revision;
    $calls = 0;

    $result = $service->submit($target, $revision, function () use (&$calls, $gmail, $jmap): void {
        $calls++;
        expect($gmail->sends)->toBe([])->and($jmap->sends)->toBe([]);
    });

    expect($calls)->toBe(2)
        ->and($result->outcome)->toBe(SubmissionOutcome::Submitted)
        ->and($driver === MailDriver::Gmail ? $gmail->sends : $jmap->sends)->toHaveCount(1);
})->with([MailDriver::Gmail, MailDriver::Jmap]);

it('checks the submission deadline after the final consumer callback finishes', function (MailDriver $driver): void {
    Date::setTestNow('2026-01-01 12:00:00');
    [$gmail, $jmap, $account] = submitSetup($driver);
    $service = app(MailWriteService::class);
    $target = submitTarget($account, 'd-1');
    $revision = $service->draft($target)->revision;
    $calls = 0;

    $failure = submitFailure(function (MailWriteService $s) use ($target, $revision, &$calls): void {
        $s->submit($target, $revision, function () use (&$calls): void {
            if (++$calls === 2) {
                Date::setTestNow(Date::now()->addSeconds(301));
            }
        });
    });

    expect($calls)->toBe(2)
        ->and($failure->safeCode)->toBe(MailWriteCode::LockExpired)
        ->and($failure->writeSent)->toBeFalse()
        ->and($gmail->sends)->toBe([])
        ->and($jmap->sends)->toBe([]);
})->with([MailDriver::Gmail, MailDriver::Jmap]);

it('fences a submission claimant superseded during slow Gmail credential preparation', function (): void {
    Date::setTestNow('2026-01-01 12:00:00');
    DB::statement('CREATE TABLE synthetic_submission_claims (id INTEGER PRIMARY KEY, claim VARCHAR(50))');
    DB::table('synthetic_submission_claims')->insert(['id' => 1, 'claim' => 'original']);
    $gmail = new SubmitGmailProvider;
    $gmail->drafts['d-1'] = ['message' => 'm-1', 'thread' => 't-1', 'raw' => submitBytes('send-1@invented.test')];
    $account = submitAccount(MailDriver::Gmail);
    $handler = $gmail->handler();
    $refreshes = 0;
    $calls = 0;
    Http::fake(function (Request $request) use ($handler, &$refreshes, &$calls) {
        if ($request->url() === 'https://oauth2.googleapis.com/token') {
            expect($calls)->toBe(1);
            $refreshes++;
            // Recovery commits a new durable claim while the old worker waits.
            Date::setTestNow(Date::now()->addSeconds(301));
            DB::transaction(fn () => DB::table('synthetic_submission_claims')->where('id', 1)->update(['claim' => 'recovery']));

            return Http::response(['access_token' => 'synthetic-new-access', 'expires_in' => 3600, 'token_type' => 'Bearer', 'scope' => GmailOAuth::SCOPE]);
        }

        $response = $handler($request);
        if ($request->method() === 'GET') {
            Date::setTestNow(Date::now()->addSeconds(40));
        }

        return $response;
    });
    $service = app(MailWriteService::class);
    $target = submitTarget($account, 'd-1');
    $revision = $service->draft($target)->revision;
    $stored = $account->credential()->firstOrFail();
    app(MailAccountConnection::class)->rotate($account, $stored, new OAuthTokenSetCredential(
        'synthetic-submit-access', 'synthetic-submit-refresh', Date::now()->addSeconds(100)->toDateTimeImmutable(), [GmailOAuth::SCOPE],
    ), $stored->version);

    $failure = submitFailure(function (MailWriteService $s) use ($target, $revision, &$calls): void {
        $s->submit($target, $revision, function () use (&$calls): void {
            $calls++;
            if (DB::table('synthetic_submission_claims')->where('id', 1)->value('claim') !== 'original') {
                throw new MailWriteFailure(MailWriteCode::ClaimSuperseded);
            }
        });
    });

    expect($calls)->toBe(2)
        ->and($refreshes)->toBe(1)
        ->and($failure->safeCode)->toBe(MailWriteCode::ClaimSuperseded)
        ->and($failure->writeSent)->toBeFalse()
        ->and($gmail->sends)->toBe([])
        ->and($gmail->drafts)->toHaveKey('d-1');
    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/drafts/send'));
});

it('sends a Gmail draft with exactly one drafts.send and returns the confirmed sent message', function (): void {
    [$gmail, , $account] = submitSetup(MailDriver::Gmail);
    $service = app(MailWriteService::class);
    $revision = $service->draft(submitTarget($account, 'd-1'))->revision;

    $result = $service->submit(submitTarget($account, 'd-1'), $revision);

    expect($result->outcome)->toBe(SubmissionOutcome::Submitted)
        ->and($result->providerMessageId)->toBe('sent-m-1')
        ->and($result->threadId)->toBe('t-1')
        ->and($result->matchedBy)->toBe('provider_id')
        ->and($gmail->sends)->toBe([['id' => 'd-1', 'message' => ['raw' => rtrim(strtr(base64_encode(submitBytes('send-1@invented.test')), '+/', '-_'), '='), 'threadId' => 't-1']]])
        ->and($gmail->drafts)->toBe([])
        ->and(array_slice($gmail->paths, -2))->toBe(['POST /gmail/v1/users/me/drafts/send', 'GET /gmail/v1/users/me/messages/sent-m-1']);
});

it('sends a JMAP draft with one EmailSubmission/set that moves it from Drafts to Sent without $draft', function (): void {
    [, $jmap, $account] = submitSetup(MailDriver::Jmap);
    $service = app(MailWriteService::class);
    $revision = $service->draft(submitTarget($account, 'd-1'))->revision;

    $result = $service->submit(submitTarget($account, 'd-1'), $revision);

    expect($result->outcome)->toBe(SubmissionOutcome::Submitted)
        ->and($result->providerMessageId)->toBe('d-1')
        ->and($result->threadId)->toBe('thread-d-1')
        ->and($jmap->sends)->toBe([[
            'accountId' => 'jmap-submit-a',
            'create' => ['send' => ['identityId' => 'identity-owner', 'emailId' => 'd-1']],
            'onSuccessUpdateEmail' => ['#send' => ['mailboxIds/mb-drafts' => null, 'mailboxIds/mb-sent' => true, 'keywords/$draft' => null]],
        ]])
        ->and($jmap->emails['d-1']['mailboxIds'])->toBe(['mb-sent' => true])
        ->and($jmap->emails['d-1']['keywords'])->toBe(['$seen' => true]);
});

it('sends nothing when the draft revision is stale', function (MailDriver $driver): void {
    [$gmail, $jmap, $account] = submitSetup($driver);
    $service = app(MailWriteService::class);
    $revision = $service->draft(submitTarget($account, 'd-1'))->revision;
    $gmail->drafts['d-1'] = ['message' => 'm-edited', 'thread' => 't-1', 'raw' => submitBytes('send-1@invented.test', 'owner@invented.test')];
    $jmap->blobs['blob-d-1'] = submitBytes('send-1@invented.test', 'owner@invented.test');

    $failure = submitFailure(fn (MailWriteService $s) => $s->submit(submitTarget($account, 'd-1'), $revision));

    expect($failure->safeCode)->toBe(MailWriteCode::StaleRevision)
        ->and($failure->writeSent)->toBeFalse()
        ->and($gmail->sends)->toBe([])
        ->and($jmap->sends)->toBe([]);
})->with([MailDriver::Gmail, MailDriver::Jmap]);

it('rejects an earlier send approval after a delegated Date-only edit changes exact raw revision', function (): void {
    [$gmail, , $account] = submitSetup(MailDriver::Gmail);
    $input = new DraftContent(submitBytes('send-1@invented.test'));
    $gmail->drafts['d-1'] = ['message' => 'm-1', 'thread' => 't-1', 'raw' => "Date: Fri, 2 Oct 2026 11:55:53 +0000\r\n".$input->bytes];
    $service = app(MailWriteService::class);
    $target = submitTarget($account, 'd-1');
    $approved = $service->draft($target);
    // Keep provider IDs and all other bytes fixed, isolating exact Date hashing.
    $changedBytes = "Date: Fri, 2 Oct 2026 12:58:07 +0000\r\n".$input->bytes;
    $gmail->drafts['d-1'] = ['message' => 'm-1', 'thread' => 't-1', 'raw' => $changedBytes];
    $observed = $service->draft($target);

    expect(GmailDraftContent::matches($changedBytes, $input))->toBeTrue()
        ->and($observed->providerMessageId)->toBe($approved->providerMessageId)
        ->and($observed->rawBytes)->toBe($changedBytes)->and($observed->rawSha256)->toBe(hash('sha256', $changedBytes))
        ->and($observed->rawSha256)->not->toBe($approved->rawSha256)->and($observed->revision)->not->toBe($approved->revision);
    $failure = submitFailure(fn (MailWriteService $s) => $s->submit($target, $approved->revision));

    expect($failure->safeCode)->toBe(MailWriteCode::StaleRevision)->and($failure->writeSent)->toBeFalse()
        ->and($gmail->sends)->toBe([])->and($gmail->sentRaw)->toBe([])
        ->and(array_filter($gmail->paths, fn (string $path): bool => str_starts_with($path, 'POST')))->toBe([]);
});

it('refuses a From address that does not match exactly one identity before any submission request', function (MailDriver $driver, string $from): void {
    [$gmail, $jmap, $account] = submitSetup($driver, 'identity@invented.test', $from);
    MailIdentity::query()->create(['mail_account_id' => $account->id, 'provider_identity_id' => 'identity-dup', 'email_address' => 'DUP@invented.test']);
    MailIdentity::query()->create(['mail_account_id' => $account->id, 'provider_identity_id' => 'identity-dup-2', 'email_address' => 'dup@invented.test']);
    $other = MailAccount::query()->create(['owner_type' => 'synthetic-workspace', 'owner_id' => 'owner-a', 'driver' => $driver, 'provider_account_id' => 'other-account']);
    MailIdentity::query()->create(['mail_account_id' => $other->id, 'provider_identity_id' => 'identity-other', 'email_address' => 'other-account@invented.test']);
    $service = app(MailWriteService::class);
    $revision = $service->draft(submitTarget($account, 'd-1'))->revision;

    $failure = submitFailure(fn (MailWriteService $s) => $s->submit(submitTarget($account, 'd-1'), $revision));

    expect($failure->safeCode)->toBe(MailWriteCode::IdentityMismatch)
        ->and($failure->writeSent)->toBeFalse()
        ->and($gmail->sends)->toBe([])
        ->and($jmap->sends)->toBe([])
        ->and(array_filter($gmail->paths, fn (string $path): bool => str_starts_with($path, 'POST')))->toBe([]);
})->with([MailDriver::Gmail, MailDriver::Jmap])->with([
    'an address with no identity' => ['Stranger <stranger@invented.test>'],
    'an address with two identities' => ['dup@invented.test'],
    'two From mailboxes' => ['owner@invented.test, alias@invented.test'],
    'another account\'s identity' => ['other-account@invented.test'],
]);

it('matches the identity address through display names, quotes, and case', function (): void {
    [$gmail, , $account] = submitSetup(MailDriver::Gmail, 'quoted@invented.test', '"Owner, Alias <x@y>" (work) <ALIAS@invented.test>');
    $service = app(MailWriteService::class);

    expect($service->submit(submitTarget($account, 'd-1'), $service->draft(submitTarget($account, 'd-1'))->revision)->outcome)
        ->toBe(SubmissionOutcome::Submitted)
        ->and($gmail->sends)->toHaveCount(1);
});

it('never sends again after a possibly sent submit and reconciles it from provider reads', function (MailDriver $driver): void {
    [$gmail, $jmap, $account] = submitSetup($driver);
    $gmail->sendStatusAfterApply = 503;
    $jmap->sendStatusAfterApply = 503;
    $service = app(MailWriteService::class);
    $revision = $service->draft(submitTarget($account, 'd-1'))->revision;

    $failure = submitFailure(fn (MailWriteService $s) => $s->submit(submitTarget($account, 'd-1'), $revision));
    $requests = count(Http::recorded());
    $retry = submitFailure(fn (MailWriteService $s) => $s->submit(submitTarget($account, 'd-1'), $revision));

    expect($failure->writeSent)->toBeTrue()
        ->and($retry->safeCode)->toBe(MailWriteCode::SubmissionUnknown)
        ->and($retry->writeSent)->toBeFalse()
        ->and(count(Http::recorded()))->toBe($requests);

    $reconciled = $service->reconcileSubmission(submitTarget($account, 'd-1'), $revision, 'send-1@invented.test');

    expect($reconciled->outcome)->toBe(SubmissionOutcome::Submitted)
        ->and($reconciled->providerMessageId)->toBe($driver === MailDriver::Gmail ? 'sent-m-1' : 'd-1')
        ->and($reconciled->matchedBy)->toBe($driver === MailDriver::Gmail ? 'message_id' : 'provider_id')
        ->and($driver === MailDriver::Gmail ? $gmail->sends : $jmap->sends)->toHaveCount(1)
        ->and(submitFailure(fn (MailWriteService $s) => $s->submit(submitTarget($account, 'd-1'), $revision))->safeCode)->toBe(MailWriteCode::DraftNotFound)
        ->and($driver === MailDriver::Gmail ? $gmail->sends : $jmap->sends)->toHaveCount(1);
})->with([MailDriver::Gmail, MailDriver::Jmap]);

it('reports unknown, never not_submitted, when nothing matched and the draft is unchanged, and keeps blocking', function (MailDriver $driver): void {
    [$gmail, $jmap, $account] = submitSetup($driver);
    $gmail->sendStatusBeforeApply = 503;
    $service = app(MailWriteService::class);
    $revision = $service->draft(submitTarget($account, 'd-1'))->revision;

    if ($driver === MailDriver::Gmail) {
        expect(submitFailure(fn (MailWriteService $s) => $s->submit(submitTarget($account, 'd-1'), $revision))->writeSent)->toBeTrue();
    }

    $reconciled = $service->reconcileSubmission(submitTarget($account, 'd-1'), $revision, 'send-1@invented.test');

    expect($reconciled->outcome)->toBe(SubmissionOutcome::Unknown)
        ->and($reconciled->providerEvidence)->toBe(['draft_exists' => true, 'draft_at_expected_revision' => true]);

    if ($driver === MailDriver::Gmail) {
        // Unknown never clears the recorded attempt.
        expect(submitFailure(fn (MailWriteService $s) => $s->submit(submitTarget($account, 'd-1'), $revision))->safeCode)->toBe(MailWriteCode::SubmissionUnknown)
            ->and($gmail->sends)->toHaveCount(1);
    } else {
        // Without any recorded attempt, reconciliation itself blocks nothing.
        expect($service->submit(submitTarget($account, 'd-1'), $revision)->outcome)->toBe(SubmissionOutcome::Submitted)
            ->and($jmap->sends)->toHaveCount(1);
    }
})->with([MailDriver::Gmail, MailDriver::Jmap]);

it('reports unknown, never not_submitted, when the Gmail draft is gone and the sent copy has another Message-ID', function (): void {
    [$gmail, , $account] = submitSetup(MailDriver::Gmail);
    $gmail->sendStatusAfterApply = 503;
    // Gmail stored the sent copy under a Message-ID of its own.
    $gmail->sentMessageIdHeader = '<rewritten-by-provider@mail.invented.test>';
    $service = app(MailWriteService::class);
    $revision = $service->draft(submitTarget($account, 'd-1'))->revision;

    expect(submitFailure(fn (MailWriteService $s) => $s->submit(submitTarget($account, 'd-1'), $revision))->writeSent)->toBeTrue();

    $reconciled = $service->reconcileSubmission(submitTarget($account, 'd-1'), $revision, 'send-1@invented.test');

    expect($reconciled->outcome)->toBe(SubmissionOutcome::Unknown)
        ->and($reconciled->providerEvidence)->toBe(['draft_exists' => false, 'draft_at_expected_revision' => false])
        // Unknown keeps the submit blocked.
        ->and(submitFailure(fn (MailWriteService $s) => $s->submit(submitTarget($account, 'd-1'), $revision))->safeCode)->toBe(MailWriteCode::SubmissionUnknown)
        ->and($gmail->sends)->toHaveCount(1);
});

it('reports unknown when the draft changed and nothing matched', function (MailDriver $driver): void {
    [$gmail, $jmap, $account] = submitSetup($driver);
    $revision = app(MailWriteService::class)->draft(submitTarget($account, 'd-1'))->revision;
    $gmail->drafts['d-1']['raw'] = submitBytes('send-1@invented.test', 'owner@invented.test');
    unset($jmap->emails['d-1']);

    expect(app(MailWriteService::class)->reconcileSubmission(submitTarget($account, 'd-1'), $revision, 'send-1@invented.test')->outcome)
        ->toBe(SubmissionOutcome::Unknown);
})->with([MailDriver::Gmail, MailDriver::Jmap]);

it('treats a Gmail Message-ID match while the draft still exists as unknown', function (): void {
    [$gmail, , $account] = submitSetup(MailDriver::Gmail);
    $gmail->messages['sent-elsewhere'] = ['thread' => 't-9', 'labels' => ['SENT'], 'messageIdHeader' => '<send-1@invented.test>'];
    $revision = app(MailWriteService::class)->draft(submitTarget($account, 'd-1'))->revision;

    $result = app(MailWriteService::class)->reconcileSubmission(submitTarget($account, 'd-1'), $revision, 'send-1@invented.test');

    expect($result->outcome)->toBe(SubmissionOutcome::Unknown)
        ->and($result->matchedBy)->toBe('message_id');
});

it('reconciles JMAP by EmailSubmission on the email ID, ignoring canceled submissions', function (string $undoStatus, SubmissionOutcome $outcome): void {
    [, $jmap, $account] = submitSetup(MailDriver::Jmap);
    $jmap->submissions['sub-x'] = ['emailId' => 'd-1', 'undoStatus' => $undoStatus];
    $revision = app(MailWriteService::class)->draft(submitTarget($account, 'd-1'))->revision;

    $result = app(MailWriteService::class)->reconcileSubmission(submitTarget($account, 'd-1'), $revision, 'send-1@invented.test');

    expect($result->outcome)->toBe($outcome)
        ->and($jmap->sends)->toBe([])
        ->and($jmap->methods)->toContain('EmailSubmission/query');
})->with([
    'pending, while the email is still a draft' => ['pending', SubmissionOutcome::Submitted],
    'final' => ['final', SubmissionOutcome::Submitted],
    'canceled' => ['canceled', SubmissionOutcome::Unknown],
]);

it('refuses submit with writes_disabled before any request while the switch is off', function (): void {
    config()->set('mail-mirror.writes.enabled', false);
    Http::fake();
    $account = submitAccount(MailDriver::Gmail);

    expect(submitFailure(fn (MailWriteService $s) => $s->submit(submitTarget($account, 'd-1'), str_repeat('0', 64)))->safeCode)
        ->toBe(MailWriteCode::WritesDisabled);
    Http::assertNothingSent();
});

it('rejects an owner mismatch for submit and reconcile with zero provider requests', function (): void {
    Http::fake();
    $account = submitAccount(MailDriver::Jmap);

    expect(fn () => app(MailWriteService::class)->submit(new DraftTarget($account->id, 'synthetic-workspace', 'owner-b', 'd-1'), str_repeat('0', 64)))
        ->toThrow(AccountResourceMismatch::class)
        ->and(fn () => app(MailWriteService::class)->reconcileSubmission(new DraftTarget($account->id, null, null, 'd-1'), str_repeat('0', 64), 'm@invented.test'))
        ->toThrow(AccountResourceMismatch::class);
    Http::assertNothingSent();
});

it('never sends a second JMAP submission after the submit intent is evicted', function (): void {
    [, $jmap, $account] = submitSetup(MailDriver::Jmap);
    $jmap->skipOnSuccess = true;
    $jmap->sendStatusAfterApply = 503;
    $service = app(MailWriteService::class);
    $revision = $service->draft(submitTarget($account, 'd-1'))->revision;

    expect(submitFailure(fn (MailWriteService $s) => $s->submit(submitTarget($account, 'd-1'), $revision))->writeSent)->toBeTrue();

    // The submission was accepted, but the email stayed a draft at the same revision.
    expect($service->draft(submitTarget($account, 'd-1'))->revision)->toBe($revision);

    // The cache evicts the recorded attempt.
    cache()->flush();
    $failure = submitFailure(fn (MailWriteService $s) => $s->submit(submitTarget($account, 'd-1'), $revision));

    expect($failure->safeCode)->toBe(MailWriteCode::SubmissionUnknown)
        ->and($failure->writeSent)->toBeFalse()
        ->and($jmap->sends)->toHaveCount(1)
        ->and($service->reconcileSubmission(submitTarget($account, 'd-1'), $revision, 'send-1@invented.test')->outcome)
        ->toBe(SubmissionOutcome::Submitted)
        ->and($jmap->sends)->toHaveCount(1);
});

it('sends the approved Gmail bytes and From even when the draft changes during the pre-send search', function (): void {
    [$gmail, , $account] = submitSetup(MailDriver::Gmail);
    $service = app(MailWriteService::class);
    $approved = $service->draft(submitTarget($account, 'd-1'));
    // Someone edits the draft in Gmail's UI after the revision and identity checks.
    $gmail->onSentSearch = function () use ($gmail): void {
        $gmail->drafts['d-1']['raw'] = submitBytes('send-1@invented.test', 'Attacker <attacker@invented.test>');
        $gmail->onSentSearch = null;
    };

    $result = $service->submit(submitTarget($account, 'd-1'), $approved->revision);

    expect($result->outcome)->toBe(SubmissionOutcome::Submitted)
        ->and($gmail->sentRaw['sent-m-1'])->toBe(submitBytes('send-1@invented.test'))
        ->and(DraftContent::fromAddressOf($gmail->sentRaw['sent-m-1']))->toBe('owner@invented.test');
});

it('reports unknown for a changed but existing draft with only a Message-ID match, and the attempt still blocks', function (): void {
    [$gmail, , $account] = submitSetup(MailDriver::Gmail);
    $gmail->sendStatusBeforeApply = 503;
    $service = app(MailWriteService::class);
    $revision = $service->draft(submitTarget($account, 'd-1'))->revision;

    expect(submitFailure(fn (MailWriteService $s) => $s->submit(submitTarget($account, 'd-1'), $revision))->writeSent)->toBeTrue();

    $gmail->drafts['d-1']['raw'] = submitBytes('send-1@invented.test', 'alias@invented.test');
    $gmail->messages['sent-elsewhere'] = ['thread' => 't-9', 'labels' => ['SENT'], 'messageIdHeader' => '<send-1@invented.test>'];
    $result = $service->reconcileSubmission(submitTarget($account, 'd-1'), $revision, 'send-1@invented.test');

    expect($result->outcome)->toBe(SubmissionOutcome::Unknown)
        ->and($result->matchedBy)->toBe('message_id')
        ->and(submitFailure(fn (MailWriteService $s) => $s->submit(submitTarget($account, 'd-1'), $revision))->safeCode)->toBe(MailWriteCode::SubmissionUnknown)
        ->and($gmail->sends)->toHaveCount(1);
});

it('finds a final JMAP submission behind ten canceled ones after the attempt is evicted', function (): void {
    [, $jmap, $account] = submitSetup(MailDriver::Jmap);

    for ($i = 1; $i <= 10; $i++) {
        $jmap->submissions['canceled-'.$i] = ['emailId' => 'd-1', 'undoStatus' => 'canceled'];
    }

    $jmap->skipOnSuccess = true;
    $jmap->sendStatusAfterApply = 503;
    $service = app(MailWriteService::class);
    $revision = $service->draft(submitTarget($account, 'd-1'))->revision;

    expect(submitFailure(fn (MailWriteService $s) => $s->submit(submitTarget($account, 'd-1'), $revision))->writeSent)->toBeTrue();
    cache()->flush();

    expect(submitFailure(fn (MailWriteService $s) => $s->submit(submitTarget($account, 'd-1'), $revision))->safeCode)->toBe(MailWriteCode::SubmissionUnknown)
        ->and($jmap->sends)->toHaveCount(1)
        ->and($service->reconcileSubmission(submitTarget($account, 'd-1'), $revision, 'send-1@invented.test')->outcome)->toBe(SubmissionOutcome::Submitted)
        ->and($jmap->sends)->toHaveCount(1);
});

it('treats a JMAP submission search that reaches its bound as unknown, never as absence', function (): void {
    [, $jmap, $account] = submitSetup(MailDriver::Jmap);

    for ($i = 1; $i <= 250; $i++) {
        $jmap->submissions['canceled-'.$i] = ['emailId' => 'd-1', 'undoStatus' => 'canceled'];
    }

    $service = app(MailWriteService::class);
    $revision = $service->draft(submitTarget($account, 'd-1'))->revision;

    expect(submitFailure(fn (MailWriteService $s) => $s->submit(submitTarget($account, 'd-1'), $revision))->safeCode)->toBe(MailWriteCode::SubmissionUnknown)
        ->and($jmap->sends)->toBe([])
        ->and($service->reconcileSubmission(submitTarget($account, 'd-1'), $revision, 'send-1@invented.test')->outcome)->toBe(SubmissionOutcome::Unknown);
});

it('refuses submit after intent eviction when a JMAP submission moves behind the pagination cursor', function (): void {
    [, $jmap, $account] = submitSetup(MailDriver::Jmap);

    for ($i = 1; $i <= 10; $i++) {
        $jmap->submissions['canceled-'.$i] = ['emailId' => 'd-1', 'undoStatus' => 'canceled'];
    }

    $jmap->skipOnSuccess = true;
    $jmap->sendStatusAfterApply = 503;
    $service = app(MailWriteService::class);
    $revision = $service->draft(submitTarget($account, 'd-1'))->revision;
    expect(submitFailure(fn (MailWriteService $s) => $s->submit(submitTarget($account, 'd-1'), $revision))->writeSent)->toBeTrue();
    cache()->flush();

    $jmap->onSubmissionQuery = function (int $position) use ($jmap): void {
        if ($position === 10) {
            // The final submission moves from index 10 to 9. This page is empty,
            // but its changed queryState cannot prove the query is exhausted.
            unset($jmap->submissions['canceled-1']);
            $jmap->onSubmissionQuery = null;
        }
    };

    $failure = submitFailure(fn (MailWriteService $s) => $s->submit(submitTarget($account, 'd-1'), $revision));

    expect($failure->safeCode)->toBe(MailWriteCode::SubmissionUnknown)
        ->and($failure->writeSent)->toBeFalse()
        ->and($jmap->onSubmissionQuery)->toBeNull()
        ->and($jmap->sends)->toHaveCount(1);
});

it('reports unknown and retains the intent when the JMAP submission query changes between pages', function (): void {
    [, $jmap, $account] = submitSetup(MailDriver::Jmap);

    for ($i = 1; $i <= 10; $i++) {
        $jmap->submissions['canceled-'.$i] = ['emailId' => 'd-1', 'undoStatus' => 'canceled'];
    }

    $jmap->skipOnSuccess = true;
    $jmap->sendStatusAfterApply = 503;
    $service = app(MailWriteService::class);
    $revision = $service->draft(submitTarget($account, 'd-1'))->revision;
    expect(submitFailure(fn (MailWriteService $s) => $s->submit(submitTarget($account, 'd-1'), $revision))->writeSent)->toBeTrue();
    unset($jmap->emails['d-1']);

    $jmap->onSubmissionQuery = function (int $position) use ($jmap): void {
        if ($position === 10) {
            unset($jmap->submissions['canceled-1']);
            $jmap->onSubmissionQuery = null;
            // A Sent Message-ID match must not rescue an inconsistent scan.
            $jmap->put('sent-copy', submitBytes('send-1@invented.test'));
            $jmap->emails['sent-copy']['mailboxIds'] = ['mb-sent' => true];
        }
    };

    $result = $service->reconcileSubmission(submitTarget($account, 'd-1'), $revision, 'send-1@invented.test');
    $requests = count(Http::recorded());

    expect($result->outcome)->toBe(SubmissionOutcome::Unknown)
        ->and($result->matchedBy)->toBeNull()
        ->and($jmap->onSubmissionQuery)->toBeNull()
        ->and(submitFailure(fn (MailWriteService $s) => $s->submit(submitTarget($account, 'd-1'), $revision))->safeCode)->toBe(MailWriteCode::SubmissionUnknown)
        ->and(count(Http::recorded()))->toBe($requests)
        ->and($jmap->sends)->toHaveCount(1);
});

it('reports unknown after an accepted JMAP submission whose record was destroyed, and keeps refusing', function (): void {
    [, $jmap, $account] = submitSetup(MailDriver::Jmap);
    $jmap->skipOnSuccess = true;
    $jmap->sendStatusAfterApply = 503;
    $service = app(MailWriteService::class);
    $revision = $service->draft(submitTarget($account, 'd-1'))->revision;

    expect(submitFailure(fn (MailWriteService $s) => $s->submit(submitTarget($account, 'd-1'), $revision))->writeSent)->toBeTrue();
    // RFC 8621 lets the server destroy the submission record after sending.
    $jmap->submissions = [];
    $requests = count(Http::recorded());
    $result = $service->reconcileSubmission(submitTarget($account, 'd-1'), $revision, 'send-1@invented.test');

    expect($result->outcome)->toBe(SubmissionOutcome::Unknown)
        ->and($result->providerEvidence)->toBe(['draft_exists' => true, 'draft_at_expected_revision' => true])
        ->and(count(Http::recorded()))->toBeGreaterThan($requests);

    $requests = count(Http::recorded());

    expect(submitFailure(fn (MailWriteService $s) => $s->submit(submitTarget($account, 'd-1'), $revision))->safeCode)->toBe(MailWriteCode::SubmissionUnknown)
        ->and(count(Http::recorded()))->toBe($requests)
        ->and($jmap->sends)->toHaveCount(1);
});
