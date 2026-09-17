<?php

declare(strict_types=1);

use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response as PsrResponse;
use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use GuzzleHttp\TransferStats;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Jkudish\MailMirror\Credentials\ApiTokenCredential;
use Jkudish\MailMirror\Credentials\MailAccountConnection;
use Jkudish\MailMirror\Credentials\OAuthTokenSetCredential;
use Jkudish\MailMirror\Enums\MailDriver;
use Jkudish\MailMirror\Exceptions\ConnectionCredentialException;
use Jkudish\MailMirror\Exceptions\ProviderNotificationException;
use Jkudish\MailMirror\Gmail\GmailOAuth;
use Jkudish\MailMirror\Gmail\GmailPubSubService;
use Jkudish\MailMirror\Gmail\GmailWatchIdentity;
use Jkudish\MailMirror\Gmail\GmailWatchService;
use Jkudish\MailMirror\Gmail\GmailWatchSetup;
use Jkudish\MailMirror\Jmap\FastmailEventBatch;
use Jkudish\MailMirror\Jmap\FastmailEventSourceService;
use Jkudish\MailMirror\Models\MailAccount;
use Jkudish\MailMirror\Models\MailDeltaCheckpoint;
use Jkudish\MailMirror\Models\MailGmailWatch;
use Jkudish\MailMirror\Models\MailJmapEventSource;
use PHPUnit\Framework\Assert;
use Psr\Http\Message\StreamInterface;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-15 12:00:00 UTC');
    putenv('MAIL_MIRROR_GMAIL_PUBSUB_ACCESS_TOKEN=synthetic-pubsub-token');
    config()->set('mail-mirror.gmail.enabled', true);
    config()->set('mail-mirror.gmail.pubsub', [
        'enabled' => true,
        'project_id' => 'mirror-project-3208',
        'topic_id' => 'gmail-events',
        'subscription_id' => 'gmail-events-pull',
        'access_token_environment' => 'MAIL_MIRROR_GMAIL_PUBSUB_ACCESS_TOKEN',
        'max_messages' => 20,
        'timeout_seconds' => 5,
    ]);
    config()->set('mail-mirror.jmap.enabled', true);
    config()->set('mail-mirror.jmap.event_source', [
        'enabled' => true,
        'timeout_seconds' => 5,
        'ping_seconds' => 30,
        'max_stream_bytes' => 262144,
        'max_event_bytes' => 32768,
        'max_events' => 20,
    ]);
    Http::preventStrayRequests();
});

afterEach(function (): void {
    Carbon::setTestNow();
    putenv('MAIL_MIRROR_GMAIL_PUBSUB_ACCESS_TOKEN');
});

function notificationAccount(MailDriver $driver, string $providerId, string $owner = 'owner-3208'): MailAccount
{
    $account = MailAccount::query()->create([
        'owner_type' => 'synthetic-workspace',
        'owner_id' => $owner,
        'driver' => $driver,
        'provider_account_id' => $providerId,
    ]);
    $credential = $driver === MailDriver::Gmail
        ? new OAuthTokenSetCredential('synthetic-mailbox-access', 'synthetic-mailbox-refresh', null, [GmailOAuth::SCOPE])
        : new ApiTokenCredential('synthetic-fastmail-token');
    app(MailAccountConnection::class)->store($account, $credential);

    return $account;
}

function gmailNotificationData(string $email, mixed $historyId): string
{
    return base64_encode(json_encode(['emailAddress' => $email, 'historyId' => $historyId], JSON_THROW_ON_ERROR));
}

function rawGmailNotificationData(string $json): string
{
    return base64_encode($json);
}

/** @return array{Process, string} */
function localEventSourceServer(string $mode): array
{
    $server = <<<'PHP'
        $mode = $argv[1];
        $server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);

        if ($server === false) {
            fwrite(STDERR, "server failed: {$errorCode}\n");
            exit(1);
        }

        $address = stream_socket_get_name($server, false);
        fwrite(STDOUT, $address."\n");
        fflush(STDOUT);
        $connection = stream_socket_accept($server, 5);

        if ($connection === false) {
            exit(2);
        }

        while (($line = fgets($connection)) !== false && $line !== "\r\n") {
        }

        if ($mode === 'no-headers') {
            usleep(4000000);
            fclose($connection);
            fclose($server);
            exit(0);
        }

        $status = $mode === 'unauthorized' ? '401 Unauthorized' : '200 OK';
        $contentType = $mode === 'wrong-content-type' ? 'text/html' : 'text/event-stream';
        fwrite($connection, "HTTP/1.1 {$status}\r\nContent-Type: {$contentType}\r\nCache-Control: no-cache\r\nConnection: close\r\n\r\n");
        fflush($connection);

        if ($mode === 'complete') {
            fwrite($connection, "id: local-1\nevent: state\ndata: {\"@type\":\"StateChange\",\"changed\":{\"jmap-account-3208\":{\"Email\":\"local-state-2\"}}}\n\n");
            fflush($connection);
        } elseif ($mode === 'oversized') {
            fwrite($connection, str_repeat('x', 2048));
            fflush($connection);
        } elseif ($mode === 'continuous') {
            for ($i = 0; $i < 40; $i++) {
                @fwrite($connection, ": ping {$i}\n\n");
                @fflush($connection);
                usleep(100000);
            }
        } else {
            usleep(4000000);
        }

        fclose($connection);
        fclose($server);
        PHP;
    $process = new Process([PHP_BINARY, '-r', $server, $mode]);
    $process->setTimeout(6);
    $process->start();
    $process->waitUntil(fn (): bool => str_contains($process->getOutput(), "\n"));
    $address = trim($process->getOutput());

    if (preg_match('/\A127\.0\.0\.1:(\d+)\z/', $address, $matches) !== 1) {
        $process->stop(0.1);
        throw new RuntimeException('Local EventSource fixture did not report its address.');
    }

    return [$process, 'http://'.$address.'/events'];
}

function receiveLocalEventSource(string $url, float $seconds): ?string
{
    $service = app(FastmailEventSourceService::class);
    $deadline = hrtime(true) + (int) ($seconds * 1_000_000_000);
    $request = new ReflectionMethod(FastmailEventSourceService::class, 'streamRequest');
    $read = new ReflectionMethod(FastmailEventSourceService::class, 'readBounded');
    $response = $request->invoke($service, new ApiTokenCredential('synthetic-fastmail-token'), $url, null, $deadline);

    if ($response === null) {
        return null;
    }

    if (! $response instanceof Response) {
        throw new RuntimeException('Local EventSource fixture returned an invalid response.');
    }

    $body = $read->invoke($service, $response, $deadline);

    if (! is_string($body)) {
        throw new RuntimeException('Local EventSource fixture returned an invalid body.');
    }

    return $body;
}

function storeNotificationWatch(MailAccount $account): void
{
    MailGmailWatch::query()->create([
        'mail_account_id' => $account->id,
        'project_id' => 'mirror-project-3208',
        'topic' => 'projects/mirror-project-3208/topics/gmail-events',
        'subscription' => 'projects/mirror-project-3208/subscriptions/gmail-events-pull',
        'history_id_hint' => '700',
        'expires_at' => Carbon::now()->addDays(6),
    ]);
}

it('registers and renews an explicitly identified Gmail watch without advancing delta state', function (): void {
    $account = notificationAccount(MailDriver::Gmail, 'mailbox@invented.test');
    MailDeltaCheckpoint::query()->create(['mail_account_id' => $account->id, 'provider_cursor' => 'applied-cursor-1']);
    $expiration = (Carbon::now()->addDays(6)->getTimestamp()) * 1000;
    Http::fake(['https://gmail.googleapis.com/gmail/v1/users/me/watch' => Http::sequence()
        ->push(['historyId' => '700', 'expiration' => (string) $expiration])
        ->push(['historyId' => '701', 'expiration' => (string) ($expiration + 60000)])]);

    $service = app(GmailWatchService::class);
    $created = $service->register($account, GmailWatchSetup::Create);
    $renewed = $service->register($account, GmailWatchSetup::Renew, $created->identity);

    expect($created->historyIdHint)->toBe('700')
        ->and($renewed->historyIdHint)->toBe('701')
        ->and(MailGmailWatch::query()->where('mail_account_id', $account->id)->value('history_id_hint'))->toBe('701')
        ->and(MailDeltaCheckpoint::query()->where('mail_account_id', $account->id)->value('provider_cursor'))->toBe('applied-cursor-1');

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer synthetic-mailbox-access')
        && $request['topicName'] === 'projects/mirror-project-3208/topics/gmail-events');
    Http::assertSentCount(2);
});

it('refuses implicit Gmail watch takeover before making a provider call', function (): void {
    $account = notificationAccount(MailDriver::Gmail, 'mailbox@invented.test');
    $current = new GmailWatchIdentity('mirror-project-3208', 'gmail-events', 'gmail-events-pull');
    $expiration = (Carbon::now()->addDays(6)->getTimestamp()) * 1000;
    Http::fake(['https://gmail.googleapis.com/gmail/v1/users/me/watch' => Http::response([
        'historyId' => '700', 'expiration' => (string) $expiration,
    ])]);
    $service = app(GmailWatchService::class);
    $service->register($account, GmailWatchSetup::Create);
    config()->set('mail-mirror.gmail.pubsub.topic_id', 'replacement-events');

    expect(fn () => $service->register($account, GmailWatchSetup::Renew, $current))
        ->toThrow(ProviderNotificationException::class, 'Provider notification operation failed');
    Http::assertSentCount(1);
});

it('rejects inconsistent stored Gmail watch resource identities', function (): void {
    $account = notificationAccount(MailDriver::Gmail, 'mailbox@invented.test');
    storeNotificationWatch($account);
    MailGmailWatch::query()->where('mail_account_id', $account->id)->update([
        'topic' => 'projects/another-project-3208/topics/gmail-events',
    ]);
    Http::fake();

    expect(fn () => app(GmailWatchService::class)->register(
        $account,
        GmailWatchSetup::Renew,
        new GmailWatchIdentity('mirror-project-3208', 'gmail-events', 'gmail-events-pull'),
    ))->toThrow(ProviderNotificationException::class);
    Http::assertNothingSent();
});

it('pulls bounded Gmail hints, classifies misrouting, and acknowledges only on an explicit host call', function (): void {
    $first = notificationAccount(MailDriver::Gmail, 'first@invented.test', 'owner-one');
    $second = notificationAccount(MailDriver::Gmail, 'second@invented.test', 'owner-two');
    storeNotificationWatch($first);
    storeNotificationWatch($second);
    Http::fake(function (Request $request) {
        if (str_ends_with($request->url(), ':pull')) {
            return Http::response(['receivedMessages' => [
                ['ackId' => 'ack-1', 'message' => ['messageId' => 'message-1', 'publishTime' => '2026-09-15T12:00:01Z', 'data' => gmailNotificationData('first@invented.test', '801')]],
                ['ackId' => 'ack-2', 'message' => ['messageId' => 'message-2', 'publishTime' => '2026-09-15T12:00:02Z', 'data' => gmailNotificationData('unknown@invented.test', '802')]],
            ]]);
        }

        return Http::response([]);
    });

    $service = app(GmailPubSubService::class);
    $batch = $service->pull([$first, $second], 2);

    expect($batch->notifications)->toHaveCount(2)
        ->and($batch->notifications[0]->accepted())->toBeTrue()
        ->and($batch->notifications[0]->mailAccountId)->toBe($first->id)
        ->and($batch->notifications[0]->historyIdHint)->toBe('801')
        ->and($batch->notifications[1]->accepted())->toBeFalse()
        ->and(fn () => serialize($batch->notifications[0]))->toThrow(ConnectionCredentialException::class);
    Http::assertSentCount(1);

    $service->acknowledge($batch->notifications);

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), 'projects/mirror-project-3208/subscriptions/gmail-events-pull:acknowledge')
        && $request['ackIds'] === ['ack-1', 'ack-2']
        && $request->hasHeader('Authorization', 'Bearer synthetic-pubsub-token'));
    Http::assertSentCount(2);
});

it('allows Gmail long polling without extending acknowledgement timeouts', function (): void {
    $account = notificationAccount(MailDriver::Gmail, 'first@invented.test');
    storeNotificationWatch($account);
    Http::fake(function (Request $request, array $options) {
        if (str_ends_with($request->url(), ':pull')) {
            expect($options['timeout'])->toBe(60)
                ->and($request->data())->toBe(['maxMessages' => 1]);

            return Http::response(['receivedMessages' => [[
                'ackId' => 'ack-long-poll',
                'message' => ['messageId' => 'message-long-poll', 'publishTime' => '2026-09-15T12:00:01Z', 'data' => gmailNotificationData('first@invented.test', '801')],
            ]]]);
        }

        expect($options['timeout'])->toBe(5);

        return Http::response([]);
    });

    $service = app(GmailPubSubService::class);
    $batch = $service->pull([$account], 1);
    expect($batch->notifications)->toHaveCount(1);
    $service->acknowledge($batch->notifications);
    Http::assertSentCount(2);
});

it('returns an empty Gmail batch only for successful empty pulls', function (): void {
    $account = notificationAccount(MailDriver::Gmail, 'first@invented.test');
    storeNotificationWatch($account);
    config()->set('mail-mirror.gmail.pubsub.pull_timeout_seconds', 23);
    Http::fake(function (Request $request, array $options) {
        expect($options['timeout'])->toBe(23);

        return Http::response('');
    });
    expect(app(GmailPubSubService::class)->pull([$account])->notifications)->toBe([]);
    Http::assertSentCount(1);

    Http::fake(['*' => Http::failedConnection()]);
    try {
        app(GmailPubSubService::class)->pull([$account]);
        Assert::fail('A failed pull must not be reported as an empty batch.');
    } catch (ProviderNotificationException $exception) {
        expect($exception->safeCode)->toBe('provider_unavailable');
    }
});

it('accepts exact positive numeric Gmail history IDs and rejects unsafe JSON numbers', function (): void {
    $account = notificationAccount(MailDriver::Gmail, 'first@invented.test');
    storeNotificationWatch($account);
    Http::fake(fn () => Http::response(['receivedMessages' => [
        ['ackId' => 'ack-positive', 'message' => ['messageId' => 'message-positive', 'publishTime' => '2026-09-15T12:00:01Z', 'data' => gmailNotificationData('first@invented.test', 9876543210)]],
        ['ackId' => 'ack-zero', 'message' => ['messageId' => 'message-zero', 'publishTime' => '2026-09-15T12:00:02Z', 'data' => gmailNotificationData('first@invented.test', 0)]],
        ['ackId' => 'ack-negative', 'message' => ['messageId' => 'message-negative', 'publishTime' => '2026-09-15T12:00:03Z', 'data' => gmailNotificationData('first@invented.test', -1)]],
        ['ackId' => 'ack-fractional', 'message' => ['messageId' => 'message-fractional', 'publishTime' => '2026-09-15T12:00:04Z', 'data' => gmailNotificationData('first@invented.test', 1.5)]],
        ['ackId' => 'ack-imprecise', 'message' => ['messageId' => 'message-imprecise', 'publishTime' => '2026-09-15T12:00:05Z', 'data' => rawGmailNotificationData('{"emailAddress":"first@invented.test","historyId":9223372036854775808}')]],
    ]]));

    $notifications = app(GmailPubSubService::class)->pull([$account], 5)->notifications;

    expect($notifications)->toHaveCount(5)
        ->and($notifications[0]->accepted())->toBeTrue()
        ->and($notifications[0]->historyIdHint)->toBe('9876543210')
        ->and($notifications[1]->accepted())->toBeFalse()
        ->and($notifications[2]->accepted())->toBeFalse()
        ->and($notifications[3]->accepted())->toBeFalse()
        ->and($notifications[4]->accepted())->toBeFalse();
});

it('rejects stale or cross-resource Pub/Sub acknowledgement capabilities', function (): void {
    $account = notificationAccount(MailDriver::Gmail, 'first@invented.test');
    storeNotificationWatch($account);
    Http::fake(fn () => Http::response(['receivedMessages' => [[
        'ackId' => 'ack-1',
        'message' => ['messageId' => 'message-1', 'publishTime' => '2026-09-15T12:00:01Z', 'data' => gmailNotificationData('first@invented.test', '801')],
    ]]]));
    $service = app(GmailPubSubService::class);
    $batch = $service->pull([$account], 1);
    config()->set('mail-mirror.gmail.pubsub.subscription_id', 'other-pull-subscription');

    expect(fn () => $service->acknowledge($batch->notifications))
        ->toThrow(ProviderNotificationException::class);
    Http::assertSentCount(1);
});

it('parses one bounded Fastmail EventSource response and advances resume state only after host intent', function (): void {
    $account = notificationAccount(MailDriver::Jmap, 'jmap-account-3208');
    $stream = "event: ping\ndata: {\"interval\":30}\n\n"
        ."id: event-42\nevent: state\ndata: {\"@type\":\"StateChange\",\"changed\":{\"jmap-account-3208\":{\"Email\":\"email-state-2\",\"Mailbox\":\"mailbox-state-2\"}}}\n\n";
    Http::fake(function (Request $request) use ($stream) {
        if ($request->url() === 'https://api.fastmail.com/jmap/session') {
            return Http::response([
                'eventSourceUrl' => 'https://api.fastmail.com/jmap/eventsource?types={types}&closeafter={closeafter}&ping={ping}',
                'accounts' => ['jmap-account-3208' => []],
            ]);
        }

        return Http::response($stream, 200, ['Content-Type' => 'text/event-stream; charset=utf-8']);
    });

    $service = app(FastmailEventSourceService::class);
    $batch = $service->receive($account);

    expect($batch->stateChanges)->toHaveCount(1)
        ->and($batch->stateChanges[0]->changed)->toBe(['Email' => 'email-state-2', 'Mailbox' => 'mailbox-state-2'])
        ->and($batch->lastEventId)->toBe('event-42')
        ->and($batch->pingIntervalSeconds)->toBe(30)
        ->and(MailJmapEventSource::query()->where('mail_account_id', $account->id)->value('last_event_id'))->toBeNull();

    $service->advanceLastEventId($account, null, $batch);

    expect(MailJmapEventSource::query()->where('mail_account_id', $account->id)->value('last_event_id'))->toBe('event-42');
    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://api.fastmail.com/jmap/eventsource?')
        && str_contains($request->url(), 'closeafter=state')
        && $request->hasHeader('Authorization', 'Bearer synthetic-fastmail-token')
        && ! $request->hasHeader('Last-Event-ID'));
});

it('filters unrelated Fastmail state types without discarding valid mail hints', function (array $changed, array $expected): void {
    $account = notificationAccount(MailDriver::Jmap, 'jmap-account-3208');
    $payload = json_encode(['@type' => 'StateChange', 'changed' => ['jmap-account-3208' => $changed]], JSON_THROW_ON_ERROR);
    Http::fake(fn (Request $request) => $request->url() === 'https://api.fastmail.com/jmap/session'
        ? Http::response([
            'eventSourceUrl' => 'https://api.fastmail.com/events?types={types}&closeafter={closeafter}&ping={ping}',
            'accounts' => ['jmap-account-3208' => []],
        ])
        : Http::response("event: state\ndata: {$payload}\n\n", 200, ['Content-Type' => 'text/event-stream']));

    $batch = app(FastmailEventSourceService::class)->receive($account);

    expect(array_map(fn ($hint) => $hint->changed, $batch->stateChanges))->toBe($expected);
})->with([
    'mixed' => [['Thread' => 'thread-3', 'Email' => 'email-4', 'Identity' => 'identity-2', 'Mailbox' => 'mailbox-7'], [['Email' => 'email-4', 'Mailbox' => 'mailbox-7']]],
    'unrelated only' => [['Thread' => 'thread-3'], []],
]);

it('still rejects malformed mail state when unrelated Fastmail types are present', function (): void {
    $account = notificationAccount(MailDriver::Jmap, 'jmap-account-3208');
    Http::fake(fn (Request $request) => $request->url() === 'https://api.fastmail.com/jmap/session'
        ? Http::response([
            'eventSourceUrl' => 'https://api.fastmail.com/events?types={types}&closeafter={closeafter}&ping={ping}',
            'accounts' => ['jmap-account-3208' => []],
        ])
        : Http::response("event: state\ndata: {\"@type\":\"StateChange\",\"changed\":{\"jmap-account-3208\":{\"Thread\":\"thread-3\",\"Email\":12}}}\n\n", 200, ['Content-Type' => 'text/event-stream']));

    expect(fn () => app(FastmailEventSourceService::class)->receive($account))
        ->toThrow(ProviderNotificationException::class);
});

it('accepts the exact Session-advertised Fastmail Philadelphia EventSource origin', function (): void {
    $account = notificationAccount(MailDriver::Jmap, 'jmap-account-3208');
    Http::fake(function (Request $request) {
        return $request->url() === 'https://api.fastmail.com/jmap/session'
            ? Http::response([
                'eventSourceUrl' => 'https://phl.api.fastmail.com/events?types={types}&closeafter={closeafter}&ping={ping}',
                'accounts' => ['jmap-account-3208' => []],
            ])
            : Http::response("event: ping\ndata: {\"interval\":30}\n\n", 200, ['Content-Type' => 'text/event-stream']);
    });

    $batch = app(FastmailEventSourceService::class)->receive($account);

    expect($batch->pingIntervalSeconds)->toBe(30)
        ->and(MailJmapEventSource::query()->where('mail_account_id', $account->id)->value('event_source_origin'))
        ->toBe('https://phl.api.fastmail.com');
    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://phl.api.fastmail.com/events?'));
});

it('refuses a Session-advertised Fastmail origin change after the origin is persisted', function (): void {
    $account = notificationAccount(MailDriver::Jmap, 'jmap-account-3208');
    MailJmapEventSource::query()->create([
        'mail_account_id' => $account->id,
        'event_source_origin' => 'https://api.fastmail.com',
        'last_event_id' => null,
    ]);
    Http::fake(['https://api.fastmail.com/jmap/session' => Http::response([
        'eventSourceUrl' => 'https://phl.api.fastmail.com/events?types={types}&closeafter={closeafter}&ping={ping}',
        'accounts' => ['jmap-account-3208' => []],
    ])]);

    expect(fn () => app(FastmailEventSourceService::class)->receive($account))
        ->toThrow(ProviderNotificationException::class);
    Http::assertSentCount(1);
});

it('sends the persisted Fastmail Last-Event-ID without mixing it with applied JMAP state', function (): void {
    $account = notificationAccount(MailDriver::Jmap, 'jmap-account-3208');
    MailJmapEventSource::query()->create([
        'mail_account_id' => $account->id,
        'event_source_origin' => 'https://api.fastmail.com',
        'last_event_id' => 'event-41',
    ]);
    Http::fake(function (Request $request) {
        return $request->url() === 'https://api.fastmail.com/jmap/session'
            ? Http::response([
                'eventSourceUrl' => 'https://api.fastmail.com/jmap/eventsource?types={types}&closeafter={closeafter}&ping={ping}',
                'accounts' => ['jmap-account-3208' => []],
            ])
            : Http::response("id: event-42\nevent: state\ndata: {\"@type\":\"StateChange\",\"changed\":{\"jmap-account-3208\":{\"Email\":\"email-state-2\"}}}\n\n", 200, ['Content-Type' => 'text/event-stream']);
    });

    app(FastmailEventSourceService::class)->receive($account);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/jmap/eventsource?')
        && $request->hasHeader('Last-Event-ID', 'event-41'));
    expect(MailAccount::query()->whereKey($account->id)->value('provider_metadata'))->toBeNull();
});

it('keeps notification provider state account-scoped and compare-and-set fenced', function (): void {
    $gmail = notificationAccount(MailDriver::Gmail, 'first@invented.test', 'owner-one');
    $otherGmail = notificationAccount(MailDriver::Gmail, 'first@invented.test', 'owner-two');
    storeNotificationWatch($gmail);
    $jmap = notificationAccount(MailDriver::Jmap, 'jmap-account-3208', 'owner-one');
    $otherJmap = notificationAccount(MailDriver::Jmap, 'jmap-account-3208', 'owner-two');
    MailJmapEventSource::query()->create([
        'mail_account_id' => $jmap->id,
        'event_source_origin' => 'https://api.fastmail.com',
        'last_event_id' => 'event-41',
    ]);
    $batch = new FastmailEventBatch([], 'event-42', null);

    expect($otherGmail->gmailWatch()->exists())->toBeFalse()
        ->and(fn () => app(FastmailEventSourceService::class)->advanceLastEventId($otherJmap, 'event-41', $batch))
        ->toThrow(ProviderNotificationException::class)
        ->and(MailJmapEventSource::query()->where('mail_account_id', $jmap->id)->value('last_event_id'))->toBe('event-41');
});

it('ignores valid Fastmail state hints for another advertised account', function (): void {
    $account = notificationAccount(MailDriver::Jmap, 'jmap-account-3208');
    Http::fake(function (Request $request) {
        return $request->url() === 'https://api.fastmail.com/jmap/session'
            ? Http::response([
                'eventSourceUrl' => 'https://api.fastmail.com/events?ping={ping}&types={types}&closeafter={closeafter}',
                'accounts' => ['jmap-account-3208' => [], 'other-account' => []],
            ])
            : Http::response("id: event-42\nevent: state\ndata: {\"@type\":\"StateChange\",\"changed\":{\"other-account\":{\"Email\":\"state-2\"}}}\n\n", 200, ['Content-Type' => 'text/event-stream']);
    });

    $batch = app(FastmailEventSourceService::class)->receive($account);

    expect($batch->stateChanges)->toBeEmpty()
        ->and($batch->lastEventId)->toBe('event-42');
});

it('rejects credential-bearing or redirected Fastmail EventSource destinations before connecting', function (string $url): void {
    $account = notificationAccount(MailDriver::Jmap, 'jmap-account-3208');
    Http::fake(['https://api.fastmail.com/jmap/session' => Http::response([
        'eventSourceUrl' => $url,
        'accounts' => ['jmap-account-3208' => []],
    ])]);

    expect(fn () => app(FastmailEventSourceService::class)->receive($account))
        ->toThrow(ProviderNotificationException::class);
    Http::assertSentCount(1);
})->with([
    'foreign origin' => 'https://attacker.invalid/events?types={types}&closeafter={closeafter}&ping={ping}',
    'unqualified Fastmail subdomain' => 'https://syd.api.fastmail.com/events?types={types}&closeafter={closeafter}&ping={ping}',
    'Fastmail suffix lookalike' => 'https://phl.api.fastmail.com.attacker.invalid/events?types={types}&closeafter={closeafter}&ping={ping}',
    'userinfo secret' => 'https://secret@api.fastmail.com/events?types={types}&closeafter={closeafter}&ping={ping}',
    'static query credential' => 'https://api.fastmail.com/events?token=secret&types={types}&closeafter={closeafter}&ping={ping}',
    'duplicate query variable' => 'https://api.fastmail.com/events?types=Email&types={types}&closeafter={closeafter}&ping={ping}',
]);

it('rejects oversized Fastmail events without exposing provider content', function (): void {
    $account = notificationAccount(MailDriver::Jmap, 'jmap-account-3208');
    config()->set('mail-mirror.jmap.event_source.max_stream_bytes', 1024);
    Http::fake(function (Request $request) {
        return $request->url() === 'https://api.fastmail.com/jmap/session'
            ? Http::response([
                'eventSourceUrl' => 'https://api.fastmail.com/events?types={types}&closeafter={closeafter}&ping={ping}',
                'accounts' => ['jmap-account-3208' => []],
            ])
            : Http::response(str_repeat('sensitive-provider-content', 100), 200, ['Content-Type' => 'text/event-stream']);
    });

    try {
        app(FastmailEventSourceService::class)->receive($account);
        throw new RuntimeException('Oversized stream was accepted.');
    } catch (ProviderNotificationException $exception) {
        expect($exception->safeCode)->toBe('malformed_payload')
            ->and((string) $exception)->not->toContain('sensitive-provider-content');
    }
});

it('rejects an oversized Fastmail SSE line instead of ignoring it', function (): void {
    $account = notificationAccount(MailDriver::Jmap, 'jmap-account-3208');
    Http::fake(function (Request $request) {
        return $request->url() === 'https://api.fastmail.com/jmap/session'
            ? Http::response([
                'eventSourceUrl' => 'https://api.fastmail.com/events?types={types}&closeafter={closeafter}&ping={ping}',
                'accounts' => ['jmap-account-3208' => []],
            ])
            : Http::response('data: '.str_repeat('x', 16385)."\n\n", 200, ['Content-Type' => 'text/event-stream']);
    });

    expect(fn () => app(FastmailEventSourceService::class)->receive($account))
        ->toThrow(ProviderNotificationException::class);
});

it('bounds a Fastmail stream that keeps sending pings without ending', function (): void {
    $account = notificationAccount(MailDriver::Jmap, 'jmap-account-3208');
    config()->set('mail-mirror.jmap.event_source.timeout_seconds', 1);
    $stream = new class(Utils::streamFor('')) implements StreamInterface
    {
        use StreamDecoratorTrait { close as private closeWrapped; }

        protected StreamInterface $stream;

        public bool $closed = false;

        public int $reads = 0;

        public function eof(): bool
        {
            return $this->reads >= 20;
        }

        public function read(int $length): string
        {
            usleep(100_000);
            $this->reads++;

            return "event: ping\ndata: {\"interval\":30}\n\n";
        }

        public function close(): void
        {
            $this->closed = true;
            $this->closeWrapped();
        }
    };
    Http::fake(function (Request $request) use ($stream) {
        return $request->url() === 'https://api.fastmail.com/jmap/session'
            ? Http::response([
                'eventSourceUrl' => 'https://api.fastmail.com/events?types={types}&closeafter={closeafter}&ping={ping}',
                'accounts' => ['jmap-account-3208' => []],
            ])
            : Create::promiseFor(new PsrResponse(200, ['Content-Type' => 'text/event-stream'], $stream));
    });
    $started = hrtime(true);

    try {
        app(FastmailEventSourceService::class)->receive($account);
        throw new RuntimeException('Non-ending stream exceeded its wall-time budget.');
    } catch (ProviderNotificationException $exception) {
        expect($exception->safeCode)->toBe('provider_unavailable')
            ->and($exception->retryable)->toBeTrue();
    }

    expect((hrtime(true) - $started) / 1_000_000_000)->toBeLessThan(1.5)
        ->and($stream->reads)->toBeGreaterThan(1)
        ->and($stream->closed)->toBeTrue();
});

it('returns no hints and preserves the resume ID on a healthy idle deadline', function (string $partialBody): void {
    $account = notificationAccount(MailDriver::Jmap, 'jmap-account-3208');
    MailJmapEventSource::query()->create([
        'mail_account_id' => $account->id, 'event_source_origin' => 'https://api.fastmail.com',
        'last_event_id' => 'previous-event',
    ]);
    Http::fake(function (Request $request, array $options) use ($partialBody) {
        if ($request->url() === 'https://api.fastmail.com/jmap/session') {
            return Http::response([
                'eventSourceUrl' => 'https://api.fastmail.com/events?types={types}&closeafter={closeafter}&ping={ping}',
                'accounts' => ['jmap-account-3208' => []],
            ]);
        }
        $onStats = $options['on_stats'] ?? null;
        if (! is_callable($onStats)) {
            throw new RuntimeException('Expected transfer statistics callback.');
        }
        $onStats(new TransferStats(
            $request->toPsrRequest(), new PsrResponse(200, ['Content-Type' => 'text/event-stream'], $partialBody),
            5.0, 28, ['size_download' => strlen($partialBody)],
        ));
        throw new ConnectionException('Synthetic receive deadline');
    });

    $batch = app(FastmailEventSourceService::class)->receive($account);
    expect($batch->stateChanges)->toBe([])
        ->and($batch->lastEventId)->toBeNull()
        ->and(MailJmapEventSource::query()->where('mail_account_id', $account->id)->value('last_event_id'))->toBe('previous-event');
})->with(['empty' => '', 'partial event' => "id: uncommitted-event\nevent: state\ndata: {\"changed\":"]);

it('ends a healthy idle EventSource at its deadline without reporting a provider outage', function (string $mode): void {
    [$process, $url] = localEventSourceServer($mode);
    Http::allowStrayRequests([$url]);
    $startedAt = hrtime(true);

    try {
        expect(receiveLocalEventSource($url, 1.0))->toBeNull()
            ->and($process->isRunning())->toBeTrue()
            ->and((hrtime(true) - $startedAt) / 1_000_000_000)->toBeLessThan(2.5);
    } finally {
        $process->stop(0.1);
    }
})->with(['quiet', 'continuous']);

it('does not treat missing or invalid EventSource headers as a healthy idle deadline', function (string $mode): void {
    [$process, $url] = localEventSourceServer($mode);
    Http::allowStrayRequests([$url]);
    $startedAt = hrtime(true);

    try {
        receiveLocalEventSource($url, 1.0);
        throw new RuntimeException('Local EventSource exceeded its deadline without failing.');
    } catch (ProviderNotificationException $exception) {
        expect($exception->safeCode)->toBe('provider_unavailable')
            ->and($exception->retryable)->toBeTrue()
            ->and($process->isRunning())->toBeTrue()
            ->and((hrtime(true) - $startedAt) / 1_000_000_000)->toBeLessThan(2.5);
    } finally {
        $process->stop(0.1);
    }
})->with(['no-headers', 'unauthorized', 'wrong-content-type']);

it('receives a complete event through the real local HTTP transport', function (): void {
    $account = notificationAccount(MailDriver::Jmap, 'jmap-account-3208');
    [$process, $url] = localEventSourceServer('complete');
    Http::allowStrayRequests([$url]);

    try {
        $body = receiveLocalEventSource($url, 2.0);
        $parse = new ReflectionMethod(FastmailEventSourceService::class, 'parse');
        $batch = $parse->invoke(app(FastmailEventSourceService::class), $account, $body);

        if (! $batch instanceof FastmailEventBatch) {
            throw new RuntimeException('Local EventSource fixture returned an invalid event batch.');
        }

        expect($batch->lastEventId)->toBe('local-1')
            ->and($batch->stateChanges)->toHaveCount(1)
            ->and($batch->stateChanges[0]->changed)->toBe(['Email' => 'local-state-2']);
    } finally {
        $process->stop(0.1);
    }
});

it('rejects an oversized response received through the real local HTTP transport', function (): void {
    config()->set('mail-mirror.jmap.event_source.max_stream_bytes', 1024);
    [$process, $url] = localEventSourceServer('oversized');
    Http::allowStrayRequests([$url]);

    try {
        receiveLocalEventSource($url, 2.0);
        throw new RuntimeException('Oversized local EventSource response was accepted.');
    } catch (ProviderNotificationException $exception) {
        expect($exception->safeCode)->toBe('malformed_payload');
    } finally {
        $process->stop(0.1);
    }
});

it('accepts a complete Fastmail event after more than one quiet second', function (): void {
    $account = notificationAccount(MailDriver::Jmap, 'jmap-account-3208');
    config()->set('mail-mirror.jmap.event_source.timeout_seconds', 3);
    $history = [];
    app(Factory::class)->globalMiddleware(Middleware::history($history));
    $stream = new class(Utils::streamFor('')) implements StreamInterface
    {
        use StreamDecoratorTrait { close as private closeWrapped; }

        protected StreamInterface $stream;

        public bool $closed = false;

        private bool $delivered = false;

        public function eof(): bool
        {
            return $this->delivered;
        }

        public function read(int $length): string
        {
            usleep(1_200_000);
            $this->delivered = true;

            return "id: event-quiet\nevent: state\ndata: {\"@type\":\"StateChange\",\"changed\":{\"jmap-account-3208\":{\"Email\":\"email-after-quiet\"}}}\n\n";
        }

        public function close(): void
        {
            $this->closed = true;
            $this->closeWrapped();
        }
    };
    Http::fake(function (Request $request) use ($stream) {
        return $request->url() === 'https://api.fastmail.com/jmap/session'
            ? Http::response([
                'eventSourceUrl' => 'https://api.fastmail.com/events?types={types}&closeafter={closeafter}&ping={ping}',
                'accounts' => ['jmap-account-3208' => []],
            ])
            : Create::promiseFor(new PsrResponse(200, ['Content-Type' => 'text/event-stream'], $stream));
    });

    $batch = app(FastmailEventSourceService::class)->receive($account);
    $eventSourceRequest = $history[1] ?? null;

    if (! is_array($eventSourceRequest)) {
        throw new RuntimeException('EventSource request options were not recorded.');
    }

    /** @var array<string, mixed> $options */
    $options = $eventSourceRequest['options'];

    expect($batch->stateChanges)->toHaveCount(1)
        ->and($batch->stateChanges[0]->changed)->toBe(['Email' => 'email-after-quiet'])
        ->and($options['stream'] ?? false)->toBeFalse()
        ->and($options['timeout'] ?? null)->toBeGreaterThan(2.0)
        ->and($stream->closed)->toBeTrue();
});

it('sanitizes a Fastmail stream failure and always closes its body', function (): void {
    $account = notificationAccount(MailDriver::Jmap, 'jmap-account-3208');
    $stream = new class(Utils::streamFor('')) implements StreamInterface
    {
        use StreamDecoratorTrait { close as private closeWrapped; }

        protected StreamInterface $stream;

        public bool $closed = false;

        private int $reads = 0;

        public function eof(): bool
        {
            return false;
        }

        public function read(int $length): string
        {
            if ($this->reads++ === 0) {
                return "event: ping\ndata: {\"interval\":30}\n\n";
            }

            throw new RuntimeException('sensitive-provider-stream-content');
        }

        public function close(): void
        {
            $this->closed = true;
            $this->closeWrapped();
        }
    };
    Http::fake(function (Request $request) use ($stream) {
        return $request->url() === 'https://api.fastmail.com/jmap/session'
            ? Http::response([
                'eventSourceUrl' => 'https://api.fastmail.com/events?types={types}&closeafter={closeafter}&ping={ping}',
                'accounts' => ['jmap-account-3208' => []],
            ])
            : Create::promiseFor(new PsrResponse(200, ['Content-Type' => 'text/event-stream'], $stream));
    });

    try {
        app(FastmailEventSourceService::class)->receive($account);
        throw new RuntimeException('Throwing stream was accepted.');
    } catch (ProviderNotificationException $exception) {
        expect($exception->safeCode)->toBe('provider_unavailable')
            ->and($exception->retryable)->toBeTrue()
            ->and((string) $exception)->not->toContain('sensitive-provider-stream-content');
    }

    expect($stream->closed)->toBeTrue();
});

it('discards a Fastmail state event truncated before its dispatch delimiter', function (): void {
    $account = notificationAccount(MailDriver::Jmap, 'jmap-account-3208');
    $complete = "id: event-41\nevent: state\ndata: {\"@type\":\"StateChange\",\"changed\":{\"jmap-account-3208\":{\"Email\":\"email-state-1\"}}}\n\n";
    $truncated = "id: event-42\nevent: state\ndata: {\"@type\":\"StateChange\",\"changed\":{\"jmap-account-3208\":{\"Email\":\"email-state-2\"}}}";
    Http::fake(function (Request $request) use ($complete, $truncated) {
        return $request->url() === 'https://api.fastmail.com/jmap/session'
            ? Http::response([
                'eventSourceUrl' => 'https://api.fastmail.com/events?types={types}&closeafter={closeafter}&ping={ping}',
                'accounts' => ['jmap-account-3208' => []],
            ])
            : Http::response($complete.$truncated, 200, ['Content-Type' => 'text/event-stream']);
    });

    $batch = app(FastmailEventSourceService::class)->receive($account);

    expect($batch->stateChanges)->toHaveCount(1)
        ->and($batch->stateChanges[0]->eventId)->toBe('event-41')
        ->and($batch->stateChanges[0]->changed)->toBe(['Email' => 'email-state-1'])
        ->and($batch->lastEventId)->toBe('event-41');
});
