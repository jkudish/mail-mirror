<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Jkudish\MailMirror\Credentials\ApiTokenCredential;
use Jkudish\MailMirror\Credentials\MailAccountConnection;
use Jkudish\MailMirror\Credentials\OAuthTokenSetCredential;
use Jkudish\MailMirror\Enums\MailboxAction;
use Jkudish\MailMirror\Enums\MailDriver;
use Jkudish\MailMirror\Enums\MailWriteCode;
use Jkudish\MailMirror\Enums\MailWriteOutcome;
use Jkudish\MailMirror\Exceptions\AccountResourceMismatch;
use Jkudish\MailMirror\Exceptions\MailWriteFailure;
use Jkudish\MailMirror\Gmail\GmailOAuth;
use Jkudish\MailMirror\Models\MailAccount;
use Jkudish\MailMirror\Write\MailboxChange;
use Jkudish\MailMirror\Write\MailWriteService;
use Jkudish\MailMirror\Write\MailWriteTarget;

/** Synthetic Gmail labels for one account; records every request path. */
final class MutationGmailProvider
{
    /** @var array<string, list<string>> */
    public array $labels = [];

    /** @var list<array{endpoint: string, body: array<string, mixed>}> */
    public array $writes = [];

    /** @var list<string> */
    public array $paths = [];

    public int $history = 100;

    /** Apply the write, then answer with this status, like a timeout after the provider applied it. */
    public ?int $statusAfterApply = null;

    /** @return Closure(Request): mixed */
    public function handler(): Closure
    {
        return function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $this->paths[] = $request->method().' '.$path;

            if (preg_match('#^/gmail/v1/users/me/messages/([^/]+)(?:/(modify|trash|untrash))?$#', $path, $matches) !== 1) {
                return Http::response(['error' => 'synthetic unexpected path'], 418);
            }

            $id = rawurldecode($matches[1]);
            $endpoint = $matches[2] ?? null;

            if (! isset($this->labels[$id])) {
                return Http::response(['error' => 'synthetic not found'], 404);
            }

            if ($endpoint === null) {
                return Http::response(['id' => $id, 'threadId' => 't-'.$id, 'historyId' => (string) $this->history, 'labelIds' => $this->labels[$id]]);
            }

            expect($request->method())->toBe('POST');
            /** @var array<string, mixed> $body */
            $body = $request->data();
            $this->writes[] = ['endpoint' => $endpoint, 'body' => $body];
            $labels = $this->labels[$id];
            /** @var list<string> $add */
            $add = match ($endpoint) {
                'trash' => ['TRASH'],
                'untrash' => [],
                default => $body['addLabelIds'] ?? [],
            };
            /** @var list<string> $remove */
            $remove = match ($endpoint) {
                'trash' => ['INBOX'],
                'untrash' => ['TRASH'],
                default => $body['removeLabelIds'] ?? [],
            };
            $this->labels[$id] = array_values(array_unique([...array_diff($labels, $remove), ...$add]));
            $this->history++;

            if ($this->statusAfterApply !== null) {
                return Http::response(['error' => 'synthetic failure'], $this->statusAfterApply);
            }

            return Http::response(['id' => $id, 'labelIds' => $this->labels[$id]]);
        };
    }
}

/** Synthetic Fastmail JMAP account with keyword and mailbox patches guarded by ifInState. */
final class MutationJmapProvider
{
    /** @var array<string, array{mailboxIds: array<string, true>, keywords: array<string, true>}> */
    public array $emails = [];

    /** @var list<array{id: string, name: string, role: string|null}> */
    public array $mailboxes = [
        ['id' => 'mb-inbox', 'name' => 'Inbox', 'role' => 'inbox'],
        ['id' => 'mb-archive', 'name' => 'Archive', 'role' => 'archive'],
        ['id' => 'mb-trash', 'name' => 'Trash', 'role' => 'trash'],
        ['id' => 'mb-junk', 'name' => 'Junk', 'role' => 'junk'],
        ['id' => 'mb-projects', 'name' => 'Projects', 'role' => null],
        ['id' => 'mb-receipts', 'name' => 'Receipts', 'role' => null],
    ];

    public int $state = 10;

    /** @var list<array<string, mixed>> */
    public array $writes = [];

    public ?int $statusAfterApply = null;

    /** @return Closure(Request): mixed */
    public function handler(): Closure
    {
        return function (Request $request) {
            if ($request->url() === 'https://api.fastmail.com/jmap/session') {
                return Http::response([
                    'capabilities' => ['urn:ietf:params:jmap:core' => [], 'urn:ietf:params:jmap:mail' => [], 'urn:ietf:params:jmap:submission' => []],
                    'accounts' => ['jmap-mutation-a' => ['accountCapabilities' => ['urn:ietf:params:jmap:mail' => [], 'urn:ietf:params:jmap:submission' => []]]],
                    'apiUrl' => 'https://api.fastmail.com/jmap/api/',
                    'downloadUrl' => 'https://www.fastmailusercontent.com/jmap/download/{accountId}/{blobId}/{name}?type={type}',
                    'state' => 'synthetic-mutation-session',
                ]);
            }

            $calls = $request->data()['methodCalls'] ?? null;
            $call = is_array($calls) ? ($calls[0] ?? null) : null;
            assert(is_array($call) && is_string($call[0]) && is_array($call[1]) && is_string($call[2]));
            [$method, $arguments, $callId] = $call;
            /** @var array<string, mixed> $arguments */
            $reply = fn (string $name, array $result): mixed => Http::response([
                'methodResponses' => [[$name, ['accountId' => $arguments['accountId']] + $result, $callId]],
                'sessionState' => 'synthetic-mutation-session',
            ]);
            $state = 'email-state-'.$this->state;

            if ($method === 'Mailbox/get') {
                return $reply('Mailbox/get', ['state' => 'mailbox-state', 'list' => $this->mailboxes, 'notFound' => []]);
            }

            if ($method === 'Email/get') {
                /** @var list<string> $ids */
                $ids = $arguments['ids'];
                $id = $ids[0];

                return isset($this->emails[$id])
                    ? $reply('Email/get', ['state' => $state, 'list' => [['id' => $id] + $this->emails[$id]], 'notFound' => []])
                    : $reply('Email/get', ['state' => $state, 'list' => [], 'notFound' => [$id]]);
            }

            expect($method)->toBe('Email/set');
            $this->writes[] = $arguments;

            if (($arguments['ifInState'] ?? null) !== $state) {
                return Http::response(['methodResponses' => [['error', ['type' => 'stateMismatch'], $callId]], 'sessionState' => 'synthetic-mutation-session']);
            }

            /** @var array<string, array<string, true|null>> $update */
            $update = $arguments['update'];

            foreach ($update as $id => $patch) {
                foreach ($patch as $path => $value) {
                    [$property, $key] = explode('/', $path, 2);
                    assert($property === 'mailboxIds' || $property === 'keywords');

                    if ($value === null) {
                        unset($this->emails[$id][$property][$key]);
                    } else {
                        $this->emails[$id][$property][$key] = true;
                    }
                }
            }

            $this->state++;

            if ($this->statusAfterApply !== null) {
                return Http::response(['error' => 'synthetic failure'], $this->statusAfterApply);
            }

            return $reply('Email/set', ['oldState' => $state, 'newState' => 'email-state-'.$this->state, 'updated' => array_fill_keys(array_keys($update), null), 'notUpdated' => null]);
        };
    }

    /**
     * @param  list<string>  $mailboxIds
     * @param  list<string>  $keywords
     */
    public function put(string $id, array $mailboxIds, array $keywords = []): void
    {
        $this->emails[$id] = ['mailboxIds' => array_fill_keys($mailboxIds, true), 'keywords' => array_fill_keys($keywords, true)];
    }

    /** @return list<string> */
    public function mailboxesOf(string $id): array
    {
        $ids = array_keys($this->emails[$id]['mailboxIds']);
        sort($ids);

        return $ids;
    }
}

function mutationGmailAccount(string $owner = 'owner-a'): MailAccount
{
    $account = MailAccount::query()->create([
        'owner_type' => 'synthetic-workspace',
        'owner_id' => $owner,
        'driver' => MailDriver::Gmail,
        'provider_account_id' => 'mutation-'.$owner.'@invented.test',
    ]);
    app(MailAccountConnection::class)->store($account, new OAuthTokenSetCredential(
        'synthetic-mutation-access',
        'synthetic-mutation-refresh',
        new DateTimeImmutable('+1 hour'),
        [GmailOAuth::SCOPE],
    ));

    return $account;
}

function mutationJmapAccount(): MailAccount
{
    $account = MailAccount::query()->create([
        'owner_type' => 'synthetic-workspace',
        'owner_id' => 'owner-a',
        'driver' => MailDriver::Jmap,
        'provider_account_id' => 'jmap-mutation-a',
    ]);
    app(MailAccountConnection::class)->store($account, new ApiTokenCredential('synthetic-mutation-jmap-token'));

    return $account;
}

function mutationTarget(MailAccount $account, string $id): MailWriteTarget
{
    return new MailWriteTarget($account->id, $account->owner_type, $account->owner_id, $id);
}

function mutationFailure(MailWriteTarget $target, MailboxChange $change): MailWriteFailure
{
    try {
        app(MailWriteService::class)->apply($target, $change);
    } catch (MailWriteFailure $failure) {
        return $failure;
    }

    throw new RuntimeException('The change reported success.');
}

/** @return array<string, array{MailboxChange}> */
function everyMailboxChange(): array
{
    return [
        'mark read' => [MailboxChange::markRead()],
        'mark unread' => [MailboxChange::markUnread()],
        'star' => [MailboxChange::star()],
        'unstar' => [MailboxChange::unstar()],
        'archive' => [MailboxChange::archive()],
        'unarchive' => [MailboxChange::unarchive()],
        'add container' => [MailboxChange::addContainer('Label_5')],
        'remove container' => [MailboxChange::removeContainer('Label_5')],
        'trash' => [MailboxChange::trash()],
        'untrash' => [MailboxChange::untrash()],
        'spam' => [MailboxChange::spam()],
        'not spam' => [MailboxChange::notSpam()],
    ];
}

beforeEach(function (): void {
    putenv('MAIL_MIRROR_GMAIL_CLIENT_SECRET=synthetic-mutation-client-secret');
    config()->set('mail-mirror.gmail.enabled', true);
    config()->set('mail-mirror.gmail.client_id', 'synthetic-mutation-client.apps.example.test');
    config()->set('mail-mirror.jmap.enabled', true);
    config()->set('mail-mirror.writes.enabled', true);
    Http::preventStrayRequests();
});

afterEach(function (): void {
    putenv('MAIL_MIRROR_GMAIL_CLIENT_SECRET');
});

it('ships with provider writes disabled', function (): void {
    $config = require dirname(__DIR__, 2).'/config/mail-mirror.php';
    $writes = is_array($config) ? ($config['writes'] ?? null) : null;

    expect(is_array($writes) ? ($writes['enabled'] ?? null) : null)->toBeFalse();
});

it('refuses every write with writes_disabled before any provider request while the switch is off', function (MailboxChange $change): void {
    config()->set('mail-mirror.writes.enabled', false);
    Http::fake();
    $gmail = mutationGmailAccount();
    $jmap = mutationJmapAccount();

    foreach ([$gmail, $jmap] as $account) {
        $failure = mutationFailure(mutationTarget($account, 'm-1'), $change);

        expect($failure->safeCode)->toBe(MailWriteCode::WritesDisabled)
            ->and($failure->writeSent)->toBeFalse();
    }

    try {
        app(MailWriteService::class)->restoreFromTrash(mutationTarget($gmail, 'm-1'));
        $restore = null;
    } catch (MailWriteFailure $failure) {
        $restore = $failure->safeCode;
    }

    expect($restore)->toBe(MailWriteCode::WritesDisabled);
    Http::assertNothingSent();
})->with(everyMailboxChange());

it('treats any switch value other than true as off', function (mixed $value): void {
    config()->set('mail-mirror.writes.enabled', $value);
    Http::fake();

    expect(mutationFailure(mutationTarget(mutationGmailAccount(), 'm-1'), MailboxChange::markRead())->safeCode)
        ->toBe(MailWriteCode::WritesDisabled);
    Http::assertNothingSent();
})->with(['string true' => ['true'], 'one' => [1], 'null' => [null]]);

it('rejects an owner or account mismatch with zero provider requests for every change', function (MailboxChange $change): void {
    Http::fake();
    $account = mutationGmailAccount();

    foreach ([
        new MailWriteTarget($account->id, 'synthetic-workspace', 'owner-b', 'm-1'),
        new MailWriteTarget($account->id, 'synthetic-team', 'owner-a', 'm-1'),
        new MailWriteTarget($account->id, null, null, 'm-1'),
        new MailWriteTarget($account->id + 50, 'synthetic-workspace', 'owner-a', 'm-1'),
    ] as $target) {
        expect(fn () => app(MailWriteService::class)->apply($target, $change))->toThrow(AccountResourceMismatch::class);
    }

    Http::assertNothingSent();
})->with(everyMailboxChange());

it('applies each Gmail change to one message through modify, trash, or untrash only', function (
    MailboxChange $change,
    array $before,
    string $endpoint,
    array $body,
    array $after,
): void {
    /** @var list<string> $before */
    $gmail = new MutationGmailProvider;
    $gmail->labels = ['gm-1' => $before, 'gm-2' => $before];
    Http::fake($gmail->handler());
    $account = mutationGmailAccount();

    $result = app(MailWriteService::class)->apply(mutationTarget($account, 'gm-1'), $change);

    expect($result->outcome)->toBe(MailWriteOutcome::Applied)
        ->and($result->providerMessageId)->toBe('gm-1')
        ->and($result->providerEvidence['label_ids'])->toBe($gmail->labels['gm-1'])
        ->and($gmail->writes)->toBe([['endpoint' => $endpoint, 'body' => $body]])
        ->and($gmail->labels['gm-1'])->toEqualCanonicalizing($after)
        ->and($gmail->labels['gm-2'])->toBe($before)
        ->and($gmail->paths)->toBe([
            'GET /gmail/v1/users/me/messages/gm-1',
            'POST /gmail/v1/users/me/messages/gm-1/'.$endpoint,
            'GET /gmail/v1/users/me/messages/gm-1',
        ]);
})->with([
    'mark read' => [MailboxChange::markRead(), ['INBOX', 'UNREAD'], 'modify', ['removeLabelIds' => ['UNREAD']], ['INBOX']],
    'mark unread' => [MailboxChange::markUnread(), ['INBOX'], 'modify', ['addLabelIds' => ['UNREAD']], ['INBOX', 'UNREAD']],
    'star' => [MailboxChange::star(), ['INBOX'], 'modify', ['addLabelIds' => ['STARRED']], ['INBOX', 'STARRED']],
    'unstar' => [MailboxChange::unstar(), ['INBOX', 'STARRED'], 'modify', ['removeLabelIds' => ['STARRED']], ['INBOX']],
    'archive' => [MailboxChange::archive(), ['INBOX', 'Label_5'], 'modify', ['removeLabelIds' => ['INBOX']], ['Label_5']],
    'unarchive' => [MailboxChange::unarchive(), ['Label_5'], 'modify', ['addLabelIds' => ['INBOX']], ['Label_5', 'INBOX']],
    'add label' => [MailboxChange::addContainer('Label_5'), ['INBOX'], 'modify', ['addLabelIds' => ['Label_5']], ['INBOX', 'Label_5']],
    'remove label' => [MailboxChange::removeContainer('Label_5'), ['INBOX', 'Label_5'], 'modify', ['removeLabelIds' => ['Label_5']], ['INBOX']],
    'trash' => [MailboxChange::trash(), ['INBOX'], 'trash', [], ['TRASH']],
    'untrash' => [MailboxChange::untrash(), ['TRASH'], 'untrash', [], []],
    'spam' => [MailboxChange::spam(), ['INBOX', 'UNREAD'], 'modify', ['addLabelIds' => ['SPAM'], 'removeLabelIds' => ['INBOX']], ['UNREAD', 'SPAM']],
    'not spam' => [MailboxChange::notSpam(), ['SPAM'], 'modify', ['addLabelIds' => ['INBOX'], 'removeLabelIds' => ['SPAM']], ['INBOX']],
]);

it('refuses Gmail system labels as containers before any provider request', function (string $labelId): void {
    Http::fake();

    expect(mutationFailure(mutationTarget(mutationGmailAccount(), 'gm-1'), MailboxChange::addContainer($labelId))->safeCode)
        ->toBe(MailWriteCode::UnsupportedContainer)
        ->and(mutationFailure(mutationTarget(mutationGmailAccount('owner-b'), 'gm-1'), MailboxChange::removeContainer($labelId))->safeCode)
        ->toBe(MailWriteCode::UnsupportedContainer);
    Http::assertNothingSent();
})->with(['INBOX', 'TRASH', 'SPAM', 'UNREAD', 'CATEGORY_PROMOTIONS']);

it('refuses Gmail changes that would leave Trash or Spam by the back door', function (MailboxChange $change, array $labels): void {
    /** @var list<string> $labels */
    $gmail = new MutationGmailProvider;
    $gmail->labels = ['gm-1' => $labels];
    Http::fake($gmail->handler());

    $failure = mutationFailure(mutationTarget(mutationGmailAccount(), 'gm-1'), $change);

    expect($failure->safeCode)->toBe(MailWriteCode::UnsupportedState)
        ->and($failure->writeSent)->toBeFalse()
        ->and($gmail->writes)->toBe([]);
})->with([
    'unarchive a trashed message' => [MailboxChange::unarchive(), ['TRASH']],
    'unarchive a spam message' => [MailboxChange::unarchive(), ['SPAM']],
    'not spam a message outside Spam' => [MailboxChange::notSpam(), ['Label_5']],
    'spam a trashed message' => [MailboxChange::spam(), ['TRASH']],
]);

it('applies each JMAP change as one Email/set patch guarded by ifInState', function (
    MailboxChange $change,
    array $mailboxes,
    array $keywords,
    array $patch,
    array $afterMailboxes,
): void {
    /** @var list<string> $mailboxes */
    /** @var list<string> $keywords */
    $jmap = new MutationJmapProvider;
    $jmap->put('jm-1', $mailboxes, $keywords);
    $jmap->put('jm-2', $mailboxes, $keywords);
    Http::fake($jmap->handler());

    $result = app(MailWriteService::class)->apply(mutationTarget(mutationJmapAccount(), 'jm-1'), $change);

    expect($result->outcome)->toBe(MailWriteOutcome::Applied)
        ->and($jmap->writes)->toBe([[
            'accountId' => 'jmap-mutation-a',
            'ifInState' => 'email-state-10',
            'update' => ['jm-1' => $patch],
        ]])
        ->and($jmap->mailboxesOf('jm-1'))->toBe($afterMailboxes)
        ->and($result->providerEvidence['mailbox_ids'])->toBe($afterMailboxes)
        ->and($result->providerEvidence['email_state'])->toBe('email-state-11')
        ->and($jmap->emails['jm-2'])->toBe(['mailboxIds' => array_fill_keys($mailboxes, true), 'keywords' => array_fill_keys($keywords, true)]);
})->with([
    'mark read' => [MailboxChange::markRead(), ['mb-inbox'], [], ['keywords/$seen' => true], ['mb-inbox']],
    'mark unread' => [MailboxChange::markUnread(), ['mb-inbox'], ['$seen'], ['keywords/$seen' => null], ['mb-inbox']],
    'star' => [MailboxChange::star(), ['mb-inbox'], [], ['keywords/$flagged' => true], ['mb-inbox']],
    'unstar' => [MailboxChange::unstar(), ['mb-inbox'], ['$flagged'], ['keywords/$flagged' => null], ['mb-inbox']],
    'archive keeps other mailboxes' => [MailboxChange::archive(), ['mb-inbox', 'mb-projects'], [], ['mailboxIds/mb-inbox' => null, 'mailboxIds/mb-archive' => true], ['mb-archive', 'mb-projects']],
    'unarchive' => [MailboxChange::unarchive(), ['mb-archive'], [], ['mailboxIds/mb-archive' => null, 'mailboxIds/mb-inbox' => true], ['mb-inbox']],
    'add mailbox' => [MailboxChange::addContainer('mb-projects'), ['mb-inbox'], [], ['mailboxIds/mb-projects' => true], ['mb-inbox', 'mb-projects']],
    'remove one of two mailboxes' => [MailboxChange::removeContainer('mb-projects'), ['mb-inbox', 'mb-projects'], [], ['mailboxIds/mb-projects' => null], ['mb-inbox']],
    'trash from several mailboxes' => [MailboxChange::trash(), ['mb-inbox', 'mb-projects'], [], ['mailboxIds/mb-inbox' => null, 'mailboxIds/mb-projects' => null, 'mailboxIds/mb-trash' => true], ['mb-trash']],
    'untrash' => [MailboxChange::untrash(), ['mb-trash'], [], ['mailboxIds/mb-trash' => null, 'mailboxIds/mb-inbox' => true], ['mb-inbox']],
    'spam' => [MailboxChange::spam(), ['mb-inbox'], [], ['mailboxIds/mb-inbox' => null, 'mailboxIds/mb-junk' => true], ['mb-junk']],
    'not spam' => [MailboxChange::notSpam(), ['mb-junk'], [], ['mailboxIds/mb-junk' => null, 'mailboxIds/mb-inbox' => true], ['mb-inbox']],
]);

it('resolves exactly one Archive or Junk role mailbox or fails ambiguous_mailbox_role without writing', function (MailboxChange $change, string $role, int $count): void {
    $jmap = new MutationJmapProvider;
    $jmap->mailboxes = array_values(array_filter($jmap->mailboxes, fn (array $mailbox): bool => $mailbox['role'] !== $role));

    for ($i = 1; $i <= $count; $i++) {
        $jmap->mailboxes[] = ['id' => 'mb-'.$role.'-'.$i, 'name' => ucfirst($role).' '.$i, 'role' => $role];
    }

    $jmap->put('jm-1', [$change->action === MailboxAction::Archive || $change->action === MailboxAction::Spam ? 'mb-inbox' : 'mb-'.$role.'-1']);
    Http::fake($jmap->handler());

    $failure = mutationFailure(mutationTarget(mutationJmapAccount(), 'jm-1'), $change);

    expect($failure->safeCode)->toBe(MailWriteCode::AmbiguousMailboxRole)
        ->and($failure->writeSent)->toBeFalse()
        ->and($jmap->writes)->toBe([]);
})->with([
    'archive with no Archive role' => [MailboxChange::archive(), 'archive', 0],
    'archive with two Archive roles' => [MailboxChange::archive(), 'archive', 2],
    'unarchive with two Archive roles' => [MailboxChange::unarchive(), 'archive', 2],
    'spam with no Junk role' => [MailboxChange::spam(), 'junk', 0],
    'spam with two Junk roles' => [MailboxChange::spam(), 'junk', 2],
    'not spam with two Junk roles' => [MailboxChange::notSpam(), 'junk', 2],
]);

it('refuses a JMAP change that would leave the email in no mailbox, or move it from a mailbox it is not in, before any write', function (MailboxChange $change, array $mailboxes): void {
    /** @var list<string> $mailboxes */
    $jmap = new MutationJmapProvider;
    $jmap->put('jm-1', $mailboxes);
    Http::fake($jmap->handler());

    $failure = mutationFailure(mutationTarget(mutationJmapAccount(), 'jm-1'), $change);

    expect($failure->safeCode)->toBe(MailWriteCode::UnsupportedState)
        ->and($failure->writeSent)->toBeFalse()
        ->and($jmap->writes)->toBe([])
        ->and($jmap->mailboxesOf('jm-1'))->toBe($mailboxes);
})->with([
    'remove the only mailbox' => [MailboxChange::removeContainer('mb-projects'), ['mb-projects']],
    'archive outside Inbox' => [MailboxChange::archive(), ['mb-projects']],
    'unarchive outside Archive' => [MailboxChange::unarchive(), ['mb-projects']],
    'not spam from Junk and another mailbox' => [MailboxChange::notSpam(), ['mb-junk', 'mb-projects']],
    'not spam outside Junk' => [MailboxChange::notSpam(), ['mb-projects']],
]);

it('refuses JMAP role mailboxes and unknown mailboxes as containers without writing', function (string $mailboxId, MailWriteCode $code): void {
    $jmap = new MutationJmapProvider;
    $jmap->put('jm-1', ['mb-inbox', 'mb-projects']);
    Http::fake($jmap->handler());

    expect(mutationFailure(mutationTarget(mutationJmapAccount(), 'jm-1'), MailboxChange::addContainer($mailboxId))->safeCode)->toBe($code)
        ->and($jmap->writes)->toBe([]);
})->with([
    'the Trash role' => ['mb-trash', MailWriteCode::UnsupportedContainer],
    'the Inbox role' => ['mb-inbox', MailWriteCode::UnsupportedContainer],
    'an unknown mailbox' => ['mb-missing', MailWriteCode::ContainerNotFound],
]);

it('confirms a possibly applied write on retry as already applied without a second write', function (MailboxChange $change): void {
    $gmail = new MutationGmailProvider;
    $gmail->labels = ['gm-1' => ['INBOX', 'UNREAD', 'Label_5']];
    $gmail->statusAfterApply = 503;
    $jmap = new MutationJmapProvider;
    $jmap->put('jm-1', ['mb-inbox', 'mb-receipts']);
    $jmap->statusAfterApply = 503;
    $gmailHandler = $gmail->handler();
    $jmapHandler = $jmap->handler();
    Http::fake(fn (Request $request) => str_contains($request->url(), 'fastmail') ? $jmapHandler($request) : $gmailHandler($request));
    $service = app(MailWriteService::class);
    $jmapChange = $change->containerId === null ? $change : MailboxChange::removeContainer('mb-receipts');

    foreach ([[mutationGmailAccount(), 'gm-1', $change], [mutationJmapAccount(), 'jm-1', $jmapChange]] as [$account, $id, $applied]) {
        $failure = mutationFailure(mutationTarget($account, $id), $applied);

        expect($failure->safeCode)->toBe(MailWriteCode::ProviderFailed)
            ->and($failure->writeSent)->toBeTrue()
            ->and($service->apply(mutationTarget($account, $id), $applied)->outcome)->toBe(MailWriteOutcome::AlreadyApplied);
    }

    expect($gmail->writes)->toHaveCount(1)
        ->and($jmap->writes)->toHaveCount(1);
})->with([
    'mark read' => [MailboxChange::markRead()],
    'archive' => [MailboxChange::archive()],
    'remove container' => [MailboxChange::removeContainer('Label_5')],
    'trash' => [MailboxChange::trash()],
]);

it('does not report a state that already held without an intent as applied', function (): void {
    $gmail = new MutationGmailProvider;
    $gmail->labels = ['gm-1' => ['INBOX']];
    $jmap = new MutationJmapProvider;
    $jmap->put('jm-1', ['mb-archive'], ['$seen']);
    $gmailHandler = $gmail->handler();
    $jmapHandler = $jmap->handler();
    Http::fake(fn (Request $request) => str_contains($request->url(), 'fastmail') ? $jmapHandler($request) : $gmailHandler($request));

    $failures = [
        mutationFailure(mutationTarget(mutationGmailAccount(), 'gm-1'), MailboxChange::markRead()),
        mutationFailure(mutationTarget(mutationJmapAccount(), 'jm-1'), MailboxChange::archive()),
        mutationFailure(mutationTarget(mutationJmapAccount(), 'jm-1'), MailboxChange::markRead()),
    ];

    expect(array_map(fn (MailWriteFailure $failure): MailWriteCode => $failure->safeCode, $failures))
        ->toBe([MailWriteCode::AlreadyInState, MailWriteCode::AlreadyInState, MailWriteCode::AlreadyInState])
        ->and(array_map(fn (MailWriteFailure $failure): bool => $failure->writeSent, $failures))->toBe([false, false, false])
        ->and($gmail->writes)->toBe([])
        ->and($jmap->writes)->toBe([]);
});

it('keeps intents for opposite changes apart, so a reversed change writes again', function (): void {
    $gmail = new MutationGmailProvider;
    $gmail->labels = ['gm-1' => ['INBOX']];
    Http::fake($gmail->handler());
    $account = mutationGmailAccount();
    $service = app(MailWriteService::class);

    expect($service->apply(mutationTarget($account, 'gm-1'), MailboxChange::archive())->outcome)->toBe(MailWriteOutcome::Applied)
        ->and($service->apply(mutationTarget($account, 'gm-1'), MailboxChange::unarchive())->outcome)->toBe(MailWriteOutcome::Applied)
        ->and($service->apply(mutationTarget($account, 'gm-1'), MailboxChange::archive())->outcome)->toBe(MailWriteOutcome::Applied)
        ->and($gmail->writes)->toHaveCount(3)
        // An intent for one label does not confirm a different label.
        ->and(mutationFailure(mutationTarget($account, 'gm-1'), MailboxChange::removeContainer('Label_9'))->safeCode)->toBe(MailWriteCode::AlreadyInState);
});

it('reports a JMAP ifInState rejection as not applied', function (): void {
    $jmap = new MutationJmapProvider;
    $jmap->put('jm-1', ['mb-inbox']);
    $handler = $jmap->handler();
    // Another client changes the account between the read and the write.
    Http::fake(function (Request $request) use ($handler, $jmap) {
        $calls = $request->data()['methodCalls'] ?? null;
        $call = is_array($calls) ? ($calls[0] ?? null) : null;

        if (is_array($call) && ($call[0] ?? null) === 'Email/set') {
            $jmap->state++;
        }

        return $handler($request);
    });

    $failure = mutationFailure(mutationTarget(mutationJmapAccount(), 'jm-1'), MailboxChange::archive());

    expect($failure->safeCode)->toBe(MailWriteCode::ProviderFailed)
        ->and($failure->writeSent)->toBeFalse()
        ->and($jmap->mailboxesOf('jm-1'))->toBe(['mb-inbox']);
});

it('validates mailbox changes', function (Closure $build): void {
    expect($build)->toThrow(InvalidArgumentException::class);
})->with([
    'container action without a container' => [fn () => new MailboxChange(MailboxAction::AddContainer)],
    'non-container action with a container' => [fn () => new MailboxChange(MailboxAction::Archive, 'Label_5')],
    'empty container' => [fn () => MailboxChange::addContainer('')],
    'container with whitespace' => [fn () => MailboxChange::addContainer('Label 5')],
    'oversized container' => [fn () => MailboxChange::addContainer(str_repeat('a', 256))],
]);

it('does not let an intent survive a later write to the same message', function (array $applied, array $external, MailboxChange $retry, MailWriteCode $code): void {
    /** @var list<MailboxChange> $applied */
    /** @var list<string> $external */
    $gmail = new MutationGmailProvider;
    $gmail->labels = ['gm-1' => ['INBOX', 'UNREAD']];
    Http::fake($gmail->handler());
    $account = mutationGmailAccount();
    $service = app(MailWriteService::class);

    foreach ($applied as $change) {
        expect($service->apply(mutationTarget($account, 'gm-1'), $change)->outcome)->toBe(MailWriteOutcome::Applied);
    }

    // Someone outside this package moves the message into the retry's destination state.
    $gmail->labels['gm-1'] = $external;
    $failure = mutationFailure(mutationTarget($account, 'gm-1'), $retry);

    expect($failure->safeCode)->toBe($code)
        ->and($failure->writeSent)->toBeFalse()
        ->and($gmail->writes)->toHaveCount(count($applied));
})->with([
    'inverse change on the same state' => [
        [MailboxChange::markRead(), MailboxChange::markUnread()], ['INBOX'], MailboxChange::markRead(), MailWriteCode::AlreadyInState,
    ],
    'other changes that touch the same label' => [
        [MailboxChange::archive(), MailboxChange::spam(), MailboxChange::notSpam()], [], MailboxChange::archive(), MailWriteCode::AlreadyInState,
    ],
    'a trash after the legacy restore intent' => [
        [MailboxChange::trash(), MailboxChange::untrash(), MailboxChange::trash()], ['INBOX'], MailboxChange::untrash(), MailWriteCode::NotInTrash,
    ],
]);
