<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Jkudish\MailMirror\Credentials\ApiTokenCredential;
use Jkudish\MailMirror\Credentials\MailAccountConnection;
use Jkudish\MailMirror\Enums\ConnectionStatus;
use Jkudish\MailMirror\Enums\MailDriver;
use Jkudish\MailMirror\Enums\MailImportCode;
use Jkudish\MailMirror\Exceptions\MailImportFailure;
use Jkudish\MailMirror\Import\MailImportEngine;
use Jkudish\MailMirror\Jmap\FastmailJmapMailboxReader;
use Jkudish\MailMirror\Models\MailAccount;
use Jkudish\MailMirror\Models\MailAttachment;
use Jkudish\MailMirror\Models\MailIdentity;
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
function jmapResponse(string $method, array $result, string $callId): array
{
    return ['methodResponses' => [[$method, $result, $callId]], 'sessionState' => 'jmap-session-state-3207'];
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

/**
 * @param  array<string, mixed>  $fixture
 * @param  array<string, int>  $calls
 */
function fakeJmap(array $fixture, array &$calls, bool $changes = false): void
{
    $raw = (string) file_get_contents(__DIR__.'/../Fixtures/synthetic-message.eml');

    Http::fake(function (Request $request) use ($fixture, &$calls, $changes, $raw) {
        if ($request->method() === 'GET' && $request->url() === 'https://api.fastmail.com/jmap/session') {
            $calls['Session/get'] = ($calls['Session/get'] ?? 0) + 1;

            return Http::response(jmapFixturePart($fixture, 'session'));
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

        if ($method === 'Mailbox/get') {
            return Http::response(jmapResponse($method, jmapFixturePart($fixture, 'mailboxes'), $callId));
        }

        if ($method === 'Identity/get') {
            return Http::response(jmapResponse($method, jmapFixturePart($fixture, 'identities'), $callId));
        }

        if ($method === 'Email/changes') {
            $result = $changes
                ? ['accountId' => 'jmap-account-synthetic-3207', 'oldState' => $arguments['sinceState'], 'newState' => 'jmap-email-state-2', 'hasMoreChanges' => false, 'created' => [], 'updated' => ['jmap-email-a'], 'destroyed' => ['jmap-email-gone']]
                : ['accountId' => 'jmap-account-synthetic-3207', 'oldState' => $arguments['sinceState'], 'newState' => 'jmap-email-state-1', 'hasMoreChanges' => false, 'created' => [], 'updated' => [], 'destroyed' => []];

            return Http::response(jmapResponse($method, $result, $callId));
        }

        if ($method === 'Email/query') {
            $position = $arguments['position'] ?? 0;
            $ids = match ($position) {
                0 => ['jmap-email-a'],
                1 => ['jmap-email-a'],
                default => ['jmap-email-draft'],
            };

            return Http::response(jmapResponse($method, [
                'accountId' => 'jmap-account-synthetic-3207',
                'queryState' => 'jmap-query-state-1',
                'canCalculateChanges' => true,
                'position' => $position,
                'ids' => $ids,
                'total' => 3,
            ], $callId));
        }

        if ($method === 'Email/get') {
            $ids = $arguments['ids'] ?? [];
            assert(is_array($ids) && array_filter($ids, fn (mixed $id): bool => ! is_string($id)) === []);
            $properties = $arguments['properties'] ?? [];
            assert(is_array($properties));
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
                'state' => $changes ? 'jmap-email-state-2' : 'jmap-email-state-1',
                'list' => $list,
                'notFound' => array_values(array_diff($ids, $foundIds)),
            ], $callId));
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
            ], $callId));
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

it('keeps the direct-only live lane unreachable without its explicit opt-in', function (): void {
    $command = sprintf('env -u MAIL_MIRROR_JMAP_LIVE_OPT_IN %s %s 2>&1',
        escapeshellarg(PHP_BINARY),
        escapeshellarg(__DIR__.'/../../scripts/fastmail-jmap-live-development-check.php'),
    );
    exec($command, $output, $exitCode);

    expect($exitCode)->toBe(2)
        ->and(implode("\n", $output))->toContain('explicit live-provider opt-in is required');
});

it('imports complete paginated JMAP state and converges duplicate delivery after crash resume', function (): void {
    $fixture = jmapFixture();
    $account = jmapAccount();
    $calls = [];
    fakeJmap($fixture, $calls);

    expect(app(MailImportEngine::class)->sync($account, 1))->toBeNull();
    $durable = MailSyncCheckpoint::query()->forAccount($account)->firstOrFail();
    expect($durable->provider_cursor)->not->toBeNull()
        ->and($durable->processed_count)->toBe(0)
        ->and(MailIdentity::query()->forAccount($account)->count())->toBe(1);

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
            'draft' => false,
            'email_state' => 'jmap-email-state-1',
        ])
        ->and($message->thread?->provider_metadata)->toMatchArray(['thread_state' => 'jmap-thread-state-1'])
        ->and($draft->provider_metadata)->toMatchArray(['keywords' => ['$draft' => true], 'draft' => true])
        ->and($account->refresh()->provider_metadata)->toMatchArray([
            'session_state' => 'jmap-session-state-3207',
            'email_state' => 'jmap-email-state-1',
            'mailbox_state' => 'jmap-mailbox-state-1',
            'identity_state' => 'jmap-identity-state-1',
        ])
        ->and($calls['Session/get'])->toBe(1)
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

it('recovers from changed query state with one fresh scan and resumes its durable cursor', function (): void {
    $fixture = jmapFixture();
    $account = jmapAccount();
    $queryCalls = 0;
    Http::fake(function (Request $request) use ($fixture, &$queryCalls) {
        if ($request->url() === 'https://api.fastmail.com/jmap/session') {
            return Http::response(jmapFixturePart($fixture, 'session'));
        }

        $methodCalls = $request->data()['methodCalls'] ?? null;
        assert(is_array($methodCalls));
        $call = $methodCalls[0] ?? null;
        assert(is_array($call));
        $method = $call[0] ?? null;
        $arguments = $call[1] ?? [];
        $callId = $call[2] ?? null;
        assert(is_string($method) && is_array($arguments) && is_string($callId));

        if ($method === 'Mailbox/get') {
            return Http::response(jmapResponse($method, jmapFixturePart($fixture, 'mailboxes'), $callId));
        }

        if ($method === 'Identity/get') {
            return Http::response(jmapResponse($method, jmapFixturePart($fixture, 'identities'), $callId));
        }

        if ($method === 'Email/query') {
            $queryCalls++;
            $position = $arguments['position'] ?? 0;

            return Http::response(jmapResponse($method, [
                'accountId' => 'jmap-account-synthetic-3207',
                'queryState' => $queryCalls === 2 ? 'changed-query-state' : 'stable-query-state',
                'ids' => $position === 0 ? ['jmap-email-a'] : [],
                'position' => $position,
                'total' => 2,
            ], $callId));
        }

        if ($method === 'Email/get') {
            $ids = $arguments['ids'] ?? [];
            assert(is_array($ids) && array_filter($ids, fn (mixed $id): bool => ! is_string($id)) === []);
            /** @var list<string> $ids */
            $list = array_map(fn (string $id): array => ['id' => $id, 'threadId' => 'jmap-thread-one'], $ids);

            return Http::response(jmapResponse($method, [
                'accountId' => 'jmap-account-synthetic-3207',
                'state' => 'jmap-email-state-1',
                'list' => $list,
                'notFound' => [],
            ], $callId));
        }

        return Http::response([], 500);
    });

    app(MailImportEngine::class)->sync($account, 2);
    $firstScan = MailSyncCheckpoint::query()->forAccount($account)->value('scan_id');

    expect(fn () => app(MailImportEngine::class)->sync($account, 1))->not->toThrow(Throwable::class)
        ->and(MailSyncCheckpoint::query()->forAccount($account)->value('scan_id'))->not->toBe($firstScan)
        ->and(MailSyncCheckpoint::query()->forAccount($account)->value('provider_cursor'))->not->toBeNull();
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
